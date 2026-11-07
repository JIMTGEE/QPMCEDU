<?php
/**
 * View Submissions Page for Teachers
 * AI Grading powered by Gemini Flash Latest
 *
 * @package QueensPa School Management System
 * @version 6.2.0
 */

// =============================================================================
// INITIALIZATION & SECURITY
// =============================================================================

ob_start();
if (session_status() === PHP_SESSION_NONE) session_start();

if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'teacher') {
    error_log("Unauthorized access attempt: user_id=" . ($_SESSION['user_id'] ?? 'unset') . ", role=" . ($_SESSION['user_role'] ?? 'unset'));
    $_SESSION['error_msg'] = "Please log in as a teacher.";
    header("Location: /login.php");
    exit();
}

ini_set('log_errors', 1);
ini_set('error_log', dirname(__FILE__) . '/error.log');
error_reporting(E_ALL);
ini_set('display_errors', 0);

$db_path = $_SERVER['DOCUMENT_ROOT'] . '/config/db.php';
if (!file_exists($db_path)) {
    error_log("Database config file missing: $db_path");
    $_SESSION['error_msg'] = "System error: Server configuration error.";
    header("Location: /login.php");
    exit();
}
require_once($db_path);

$log_path = $_SERVER['DOCUMENT_ROOT'] . '/functions/log_activity.php';
if (!file_exists($log_path)) {
    function logActivity($conn, $user_id, $action, $description) {
        try {
            $sql = "INSERT INTO activity_logs (user_id, action, description, created_at) VALUES (?, ?, ?, NOW())";
            $stmt = $conn->prepare($sql);
            if ($stmt) {
                $stmt->bind_param("iss", $user_id, $action, $description);
                $stmt->execute();
                $stmt->close();
            }
        } catch (Exception $e) {
            error_log("Failed to log activity: " . $e->getMessage());
        }
    }
} else {
    require_once($log_path);
}

if (!isset($conn) || !$conn || mysqli_connect_error()) {
    error_log("Database connection failed: " . (mysqli_connect_error() ?: "Unknown error"));
    $_SESSION['error_msg'] = "System error: Database connection failed.";
    header("Location: /login.php");
    exit();
}

$teacher_id = (int)$_SESSION['user_id'];
$user_check_sql = "SELECT id, name, department_id FROM users WHERE id = ? AND role = 'teacher' AND status = 'active'";
$user_check_stmt = $conn->prepare($user_check_sql);
if ($user_check_stmt) {
    $user_check_stmt->bind_param("i", $teacher_id);
    $user_check_stmt->execute();
    $user_result = $user_check_stmt->get_result();
    if ($user_result->num_rows === 0) {
        $_SESSION['error_msg'] = "Invalid user session.";
        header("Location: /login.php");
        exit();
    }
    $teacher = $user_result->fetch_assoc();
    $teacher_name = $teacher['name'];
    $teacher_department_id = $teacher['department_id'];
    $user_check_stmt->close();
} else {
    $_SESSION['error_msg'] = "System error: Unable to validate user.";
    header("Location: /login.php");
    exit();
}

$assignment_id = filter_input(INPUT_GET, 'assignment_id', FILTER_VALIDATE_INT);
if (!$assignment_id) {
    $_SESSION['error_msg'] = "Invalid assignment ID.";
    header("Location: /teacher/manage_assignments.php");
    exit();
}

// =============================================================================
// FETCH ASSIGNMENT DETAILS  (teacher ownership enforced)
// =============================================================================

$assignment_sql = "
    SELECT
        a.id, a.title, a.type, a.due_date, a.status, a.max_points,
        a.is_group_assignment, a.questions, a.description,
        a.file_path as assignment_file, a.link_url, a.tags,
        a.created_at, a.updated_at,
        c.id as class_id, c.class_name, c.department_id,
        d.name as department_name,
        s.id as subject_id, s.subject_name, s.subject_code,
        t.id as term_id, t.term_name, t.term_number,
        ay.id as academic_year_id, ay.year_label as academic_year
    FROM assignments a
    JOIN classes c ON a.class_id = c.id
    JOIN subjects s ON a.subject_id = s.id
    LEFT JOIN departments d ON c.department_id = d.id
    LEFT JOIN academic_terms t ON a.term_id = t.id
    LEFT JOIN academic_years ay ON t.academic_year_id = ay.id
    WHERE a.id = ? AND a.teacher_id = ?";

$assignment_stmt = $conn->prepare($assignment_sql);
$assignment = null;
if ($assignment_stmt) {
    $assignment_stmt->bind_param("ii", $assignment_id, $teacher_id);
    $assignment_stmt->execute();
    $assignment_result = $assignment_stmt->get_result();
    if ($assignment_result->num_rows === 0) {
        $_SESSION['error_msg'] = "Assignment not found or you don't have permission to view it.";
        header("Location: /teacher/manage_assignments.php");
        exit();
    }
    $assignment = $assignment_result->fetch_assoc();
    $assignment_stmt->close();
} else {
    $_SESSION['error_msg'] = "System error: Unable to fetch assignment.";
    header("Location: /teacher/manage_assignments.php");
    exit();
}

// =============================================================================
// PARSE QUESTIONS
// =============================================================================

function parseQuestions($raw) {
    if (empty($raw)) {
        return [['question_text' => 'No questions provided', 'points' => 0, 'type' => 'short_answer']];
    }
    $decoded = json_decode($raw, true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
        $out = [];
        foreach ($decoded as $q) {
            if (is_string($q)) {
                $out[] = ['question_text' => $q, 'points' => 0, 'type' => 'short_answer'];
            } elseif (is_array($q)) {
                $out[] = [
                    'question_text'  => $q['question_text'] ?? $q['question'] ?? 'Question',
                    'points'         => floatval($q['points'] ?? 0),
                    'type'           => $q['type'] ?? 'short_answer',
                    'options'        => $q['options'] ?? null,
                    'correct_answer' => $q['correct_answer'] ?? null,
                ];
            }
        }
        return $out ?: [['question_text' => 'No questions', 'points' => 0, 'type' => 'short_answer']];
    }
    $lines = preg_split('/[\n;]/', trim($raw));
    $out = [];
    foreach ($lines as $line) {
        $line = trim($line);
        if (!$line) continue;
        if (preg_match('/^(.*?)\s*\((\d+)\s*points?\)$/i', $line, $m)) {
            $out[] = ['question_text' => trim($m[1]), 'points' => (int)$m[2], 'type' => 'short_answer'];
        } else {
            $out[] = ['question_text' => $line, 'points' => 0, 'type' => 'short_answer'];
        }
    }
    return $out ?: [['question_text' => 'No questions', 'points' => 0, 'type' => 'short_answer']];
}

$questions = parseQuestions($assignment['questions']);

// =============================================================================
// CSRF TOKEN
// =============================================================================

if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// =============================================================================
// AI CONFIGURATION  ← API key & model updated
// =============================================================================

$ai_config = [
    'api_key'     => $apiKey = getenv('GEMINI_API_KEY');
    'model'       => 'gemini-flash-latest',   // matches your working curl command
    'endpoint'    => 'https://generativelanguage.googleapis.com/v1beta/models/',
    'timeout'     => 60,
    'max_retries' => 3,
    'temperature' => 0.2,
    'max_tokens'  => 2048,
];

// =============================================================================
// HELPERS
// =============================================================================

function parseStudentAnswers($answers_raw) {
    if (empty($answers_raw)) return [];
    $decoded = json_decode($answers_raw, true);
    if (json_last_error() === JSON_ERROR_NONE) {
        if (is_array($decoded)) return $decoded;
        return [['answer' => (string)$decoded]];
    }
    return [['answer' => $answers_raw]];
}

function extractAnswerText($answer_entry) {
    if (is_string($answer_entry))  return $answer_entry;
    if (is_array($answer_entry))   return $answer_entry['answer'] ?? $answer_entry['text'] ?? $answer_entry['value'] ?? json_encode($answer_entry);
    return (string)$answer_entry;
}

/**
 * Call Gemini API — uses both query-param AND header auth for maximum compatibility
 */
function callGeminiAPI($prompt, $config) {
    if (empty($config['api_key'])) {
        throw new Exception("Gemini API key is not configured.");
    }

    $url = rtrim($config['endpoint'], '/') . '/' . $config['model'] . ':generateContent?key=' . urlencode($config['api_key']);

    $payload = [
        "contents" => [["parts" => [["text" => $prompt]]]],
        "generationConfig" => [
            "temperature"     => $config['temperature'],
            "maxOutputTokens" => $config['max_tokens'],
        ]
    ];

    $lastError = '';
    for ($attempt = 0; $attempt <= $config['max_retries']; $attempt++) {
        if ($attempt > 0) sleep(min(2 * $attempt, 6));

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'X-goog-api-key: ' . $config['api_key'],   // dual auth
            ],
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

        if ($curlError) {
            $lastError = "Network error: $curlError";
            error_log("Gemini CURL error (attempt $attempt): $curlError");
            continue;
        }

        $data    = json_decode($result, true);
        $errMsg  = $data['error']['message'] ?? $result;

        if ($httpCode === 200) {
            if (json_last_error() !== JSON_ERROR_NONE) throw new Exception("Failed to parse AI response.");
            $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? null;
            if ($text === null) {
                $reason = $data['candidates'][0]['finishReason'] ?? 'UNKNOWN';
                // If content was filtered, give a helpful message
                if (in_array($reason, ['SAFETY','RECITATION'])) {
                    throw new Exception("AI response blocked by safety filter ($reason). Try rephrasing the content.");
                }
                throw new Exception("AI returned empty response (reason: $reason). Please try again.");
            }
            return $text;
        }

        if ($httpCode === 400) throw new Exception("AI request error (400): $errMsg");
        if ($httpCode === 403) throw new Exception("AI access denied (403) — check API key or billing: $errMsg");
        if ($httpCode === 404) throw new Exception("AI model '{$config['model']}' not found (404). It may be unavailable in your region.");
        if ($httpCode === 429) { $lastError = "Rate limit hit (429). Retrying…"; error_log($lastError); continue; }

        $lastError = "AI service error (HTTP $httpCode): $errMsg";
        error_log("Gemini error attempt $attempt: $lastError");
    }
    throw new Exception($lastError ?: "Failed to get AI response after {$config['max_retries']} retries.");
}

// =============================================================================
// AJAX HANDLERS
// =============================================================================

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json; charset=utf-8');

    // CSRF check
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $csrf_token) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Invalid security token. Please refresh the page and try again.']);
        exit();
    }

    try {
        switch ($_POST['action']) {
            case 'generate_support_plan':
                handleGenerateSupportPlan($conn, $assignment, $questions, $ai_config, $teacher_id);
                break;
            case 'grade_with_ai':
                handleAIGrading($conn, $assignment, $questions, $ai_config, $teacher_id);
                break;
            case 'manual_grade':
                handleManualGrading($conn, $assignment, $teacher_id);
                break;
            case 'return_submission':
                handleReturnSubmission($conn, $assignment, $teacher_id);
                break;
            default:
                throw new Exception("Unknown action: " . htmlspecialchars($_POST['action']));
        }
    } catch (Exception $e) {
        error_log("AJAX error [{$_POST['action']}]: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit();
}

// ---------------------------------------------------------------------------
// ACTION: Generate Support Plan
// ---------------------------------------------------------------------------
function handleGenerateSupportPlan($conn, $assignment, $questions, $ai_config, $teacher_id) {
    $submission_id = filter_input(INPUT_POST, 'submission_id', FILTER_VALIDATE_INT);
    if (!$submission_id) throw new Exception("Invalid submission ID.");

    $sub_sql = "SELECT s.id, s.user_id, s.answers, s.submission_text, s.feedback, s.score, u.name as student_name
                FROM assignment_submissions s
                JOIN users u ON s.user_id = u.id
                WHERE s.id = ? AND s.assignment_id = ?";
    $sub_stmt = $conn->prepare($sub_sql);
    if (!$sub_stmt) throw new Exception("Database error: " . $conn->error);
    $sub_stmt->bind_param("ii", $submission_id, $assignment['id']);
    $sub_stmt->execute();
    $sub_res = $sub_stmt->get_result();
    if ($sub_res->num_rows === 0) throw new Exception("Submission not found.");
    $sub_data = $sub_res->fetch_assoc();
    $sub_stmt->close();

    $answers = parseStudentAnswers($sub_data['answers']);
    if (empty($answers) && !empty($sub_data['submission_text'])) {
        $answers = [['answer' => $sub_data['submission_text']]];
    }

    $prompt     = buildSupportPlanPrompt($assignment, $questions, $answers, $sub_data);
    $ai_response = callGeminiAPI($prompt, $ai_config);

    echo json_encode(['success' => true, 'support_plan' => trim($ai_response)]);
}

// ---------------------------------------------------------------------------
// ACTION: AI Grading
// ---------------------------------------------------------------------------
function handleAIGrading($conn, $assignment, $questions, $ai_config, $teacher_id) {
    $submission_id = filter_input(INPUT_POST, 'submission_id', FILTER_VALIDATE_INT);
    if (!$submission_id) throw new Exception("Invalid submission ID.");

    $sub_sql = "SELECT s.id, s.user_id, s.answers, s.submission_text, u.name as student_name
                FROM assignment_submissions s
                JOIN users u ON s.user_id = u.id
                WHERE s.id = ? AND s.assignment_id = ?";
    $sub_stmt = $conn->prepare($sub_sql);
    if (!$sub_stmt) throw new Exception("Database error: " . $conn->error);
    $sub_stmt->bind_param("ii", $submission_id, $assignment['id']);
    $sub_stmt->execute();
    $sub_res = $sub_stmt->get_result();
    if ($sub_res->num_rows === 0) throw new Exception("Submission not found.");
    $sub_data = $sub_res->fetch_assoc();
    $sub_stmt->close();

    $answers = parseStudentAnswers($sub_data['answers']);
    if (empty($answers) && !empty($sub_data['submission_text'])) {
        $answers = [['answer' => $sub_data['submission_text']]];
    }

    if (empty($answers)) throw new Exception("This submission has no answers to grade.");

    $prompt  = buildGradingPrompt($assignment, $questions, $answers);
    $ai_resp = callGeminiAPI($prompt, $ai_config);
    $result  = parseAIGradingResponse($ai_resp, $assignment['max_points']);

    $feedback_json = json_encode($result, JSON_UNESCAPED_UNICODE);
    $update_sql = "UPDATE assignment_submissions
                   SET score = ?, feedback = ?, graded_by = ?, graded_at = NOW(),
                       status = 'graded', is_graded = 1, updated_at = NOW()
                   WHERE id = ? AND assignment_id = ?";
    $upd = $conn->prepare($update_sql);
    if (!$upd) throw new Exception("Database error saving grade: " . $conn->error);
    $upd->bind_param("dsiii", $result['score'], $feedback_json, $teacher_id, $submission_id, $assignment['id']);
    $upd->execute();
    if ($upd->affected_rows === 0) throw new Exception("Could not save grade. The submission may have been deleted.");
    $upd->close();

    if (function_exists('logActivity')) {
        logActivity($conn, $teacher_id, "AI Graded Submission",
            "AI graded submission ID $submission_id for {$sub_data['student_name']} — score {$result['score']}/{$assignment['max_points']}");
    }

    echo json_encode([
        'success'          => true,
        'message'          => "AI graded successfully!",
        'score'            => $result['score'],
        'score_percentage' => round(($result['score'] / max(1, $assignment['max_points'])) * 100, 1),
        'overall_feedback' => $result['overall'],
        'per_question'     => $result['per_question'],
    ]);
}

// ---------------------------------------------------------------------------
// ACTION: Manual Grading
// ---------------------------------------------------------------------------
function handleManualGrading($conn, $assignment, $teacher_id) {
    $submission_id = filter_input(INPUT_POST, 'submission_id', FILTER_VALIDATE_INT);
    $score         = filter_input(INPUT_POST, 'score',         FILTER_VALIDATE_FLOAT);
    $feedback      = trim(filter_input(INPUT_POST, 'feedback', FILTER_UNSAFE_RAW) ?? '');

    if (!$submission_id)                              throw new Exception("Invalid submission ID.");
    if ($score === null || $score === false)          throw new Exception("Please enter a valid score.");
    if ($score < 0 || $score > $assignment['max_points'])
        throw new Exception("Score must be between 0 and {$assignment['max_points']}.");

    $feedback_data = [
        'overall'   => $feedback ?: 'Manually graded.',
        'score'     => $score,
        'graded_by' => $teacher_id,
        'graded_at' => date('Y-m-d H:i:s'),
    ];

    $update_sql = "UPDATE assignment_submissions
                   SET score = ?, feedback = ?, graded_by = ?, graded_at = NOW(),
                       status = 'graded', is_graded = 1, updated_at = NOW()
                   WHERE id = ? AND assignment_id = ?";
    $upd = $conn->prepare($update_sql);
    if (!$upd) throw new Exception("Database error: " . $conn->error);
    $upd->bind_param("dsiii", $score, json_encode($feedback_data), $teacher_id, $submission_id, $assignment['id']);
    $upd->execute();
    if ($upd->affected_rows === 0) throw new Exception("No changes made. Submission not found.");
    $upd->close();

    $student_sql  = "SELECT u.name FROM assignment_submissions s JOIN users u ON s.user_id = u.id WHERE s.id = ?";
    $student_stmt = $conn->prepare($student_sql);
    if ($student_stmt) {
        $student_stmt->bind_param("i", $submission_id);
        $student_stmt->execute();
        $student = $student_stmt->get_result()->fetch_assoc();
        $student_stmt->close();
        if (function_exists('logActivity'))
            logActivity($conn, $teacher_id, "Manual Graded", "Graded submission $submission_id for {$student['name']} — score $score");
    }

    echo json_encode(['success' => true, 'message' => "Submission graded successfully!", 'score' => $score]);
}

// ---------------------------------------------------------------------------
// ACTION: Return for Revision
// ---------------------------------------------------------------------------
function handleReturnSubmission($conn, $assignment, $teacher_id) {
    $submission_id  = filter_input(INPUT_POST, 'submission_id', FILTER_VALIDATE_INT);
    $revision_notes = trim(filter_input(INPUT_POST, 'revision_notes', FILTER_UNSAFE_RAW) ?? '');

    if (!$submission_id)        throw new Exception("Invalid submission ID.");
    if (empty($revision_notes)) throw new Exception("Please provide revision notes for the student.");

    $feedback_data = [
        'overall'        => "Revision requested: $revision_notes",
        'revision_notes' => $revision_notes,
        'returned_by'    => $teacher_id,
        'returned_at'    => date('Y-m-d H:i:s'),
    ];

    $update_sql = "UPDATE assignment_submissions
                   SET status = 'returned', feedback = ?, updated_at = NOW()
                   WHERE id = ? AND assignment_id = ?";
    $upd = $conn->prepare($update_sql);
    if (!$upd) throw new Exception("Database error: " . $conn->error);
    $upd->bind_param("sii", json_encode($feedback_data), $submission_id, $assignment['id']);
    $upd->execute();
    if ($upd->affected_rows === 0) throw new Exception("Submission not found or already returned.");
    $upd->close();

    echo json_encode(['success' => true, 'message' => "Submission returned to student for revision."]);
}

// ---------------------------------------------------------------------------
// PROMPT BUILDERS
// ---------------------------------------------------------------------------

function buildSupportPlanPrompt($assignment, $questions, $answers, $sub_data) {
    $prompt  = "You are an experienced educational consultant. Provide clear, actionable teacher recommendations.\n\n";
    $prompt .= "Assignment: " . $assignment['title'] . "\n";
    $prompt .= "Subject: "    . ($assignment['subject_name'] ?? 'N/A') . "\n";
    $prompt .= "Max Points: " . $assignment['max_points'] . "\n";
    if (!empty($sub_data['score'])) $prompt .= "Student Score: " . $sub_data['score'] . "\n";
    $prompt .= "Student: " . ($sub_data['student_name'] ?? 'Unknown') . "\n\n";
    $prompt .= "Questions and Answers:\n";

    foreach ($questions as $i => $q) {
        $q_text = $q['question_text'] ?? "Question " . ($i + 1);
        $answer = isset($answers[$i]) ? extractAnswerText($answers[$i]) : "No answer provided";
        $prompt .= "Q" . ($i + 1) . ": $q_text\nAnswer: $answer\n\n";
    }

    $prompt .= "Please provide the teacher with:\n";
    $prompt .= "1. Specific areas the student is struggling with.\n";
    $prompt .= "2. Recommended teaching strategies or interventions.\n";
    $prompt .= "3. Suggested resources (exercises, videos, activities) to assign.\n";
    $prompt .= "4. Any additional advice for supporting this student's improvement.\n";
    $prompt .= "Keep it under 400 words. Be specific and practical.";
    return $prompt;
}

function buildGradingPrompt($assignment, $questions, $answers) {
    $prompt  = "You are an expert teacher grading a student assignment. Grade fairly, consistently, and constructively.\n\n";
    $prompt .= "Assignment: "    . $assignment['title'] . "\n";
    $prompt .= "Subject: "       . ($assignment['subject_name'] ?? 'N/A') . "\n";
    $prompt .= "Description: "   . ($assignment['description'] ?? 'N/A') . "\n";
    $prompt .= "Maximum Points: " . $assignment['max_points'] . "\n\n";
    $prompt .= "Questions and Student Answers:\n";

    foreach ($questions as $i => $q) {
        $q_text  = $q['question_text'] ?? "Question " . ($i + 1);
        $pts     = $q['points'] > 0 ? " [{$q['points']} pts]" : "";
        $answer  = isset($answers[$i]) ? extractAnswerText($answers[$i]) : "No answer provided";
        $prompt .= "Q" . ($i + 1) . "$pts: $q_text\nStudent's Answer: $answer\n\n";
    }

    $prompt .= "IMPORTANT: Respond with ONLY a valid JSON object — no markdown fences, no preamble, no explanation.\n";
    $prompt .= "Use this exact format:\n";
    $prompt .= '{"score":NUMERIC_SCORE,"overall":"constructive overall feedback","per_question":["feedback for Q1","feedback for Q2"]}' . "\n\n";
    $prompt .= "Rules:\n";
    $prompt .= "- score must be a number between 0 and " . $assignment['max_points'] . "\n";
    $prompt .= "- overall must be encouraging and constructive, 1-3 sentences\n";
    $prompt .= "- per_question array must have exactly " . count($questions) . " entries\n";
    $prompt .= "- Output ONLY the JSON. Nothing before or after it.";
    return $prompt;
}

function parseAIGradingResponse($response, $max_points) {
    $response = trim($response);
    // Strip markdown code fences
    $response = preg_replace('/^```(?:json)?\s*/i', '', $response);
    $response = preg_replace('/\s*```\s*$/i', '', $response);
    // Strip any text before first {
    if (($pos = strpos($response, '{')) !== false) {
        $response = substr($response, $pos);
    }
    // Strip any text after last }
    if (($pos = strrpos($response, '}')) !== false) {
        $response = substr($response, 0, $pos + 1);
    }
    $response = trim($response);

    $grading = json_decode($response, true);

    if (json_last_error() === JSON_ERROR_NONE && is_array($grading) && isset($grading['score'])) {
        $score = min((float)$max_points, max(0, floatval($grading['score'])));
        return [
            'score'        => $score,
            'overall'      => $grading['overall'] ?? 'Your assignment has been graded.',
            'per_question' => is_array($grading['per_question'] ?? null) ? $grading['per_question'] : [],
        ];
    }

    // Regex fallback
    if (preg_match('/"score"\s*:\s*(\d+(?:\.\d+)?)/', $response, $m)) {
        $score   = min((float)$max_points, max(0, floatval($m[1])));
        $overall = 'Assignment graded by AI.';
        if (preg_match('/"overall"\s*:\s*"((?:[^"\\\\]|\\\\.)*)"/s', $response, $fm)) {
            $overall = stripslashes($fm[1]);
        }
        return ['score' => $score, 'overall' => $overall, 'per_question' => []];
    }

    error_log("Could not parse AI grading JSON. Raw (first 600 chars): " . substr($response, 0, 600));
    throw new Exception("AI returned an unrecognised format. Please try manual grading or try again.");
}

// =============================================================================
// LOG PAGE ACCESS
// =============================================================================

if (function_exists('logActivity')) {
    logActivity($conn, $teacher_id, "Viewed Submissions",
        "Accessed submissions for: {$assignment['title']} (ID: $assignment_id)");
}

// =============================================================================
// FETCH SUBMISSIONS WITH FILTERS
// =============================================================================

$status_filter   = trim(filter_input(INPUT_GET, 'status',      FILTER_UNSAFE_RAW) ?? '');
$search          = trim(filter_input(INPUT_GET, 'search',      FILTER_UNSAFE_RAW) ?? '');
$grade_range_min = filter_input(INPUT_GET, 'grade_min',        FILTER_VALIDATE_FLOAT);
$grade_range_max = filter_input(INPUT_GET, 'grade_max',        FILTER_VALIDATE_FLOAT);
$sort_by         = filter_input(INPUT_GET, 'sort_by',          FILTER_UNSAFE_RAW) ?: 'submission_date';
$sort_order      = filter_input(INPUT_GET, 'sort_order',       FILTER_UNSAFE_RAW) === 'asc' ? 'ASC' : 'DESC';
$page            = max(1, filter_input(INPUT_GET, 'page',      FILTER_VALIDATE_INT) ?: 1);
$per_page        = 10;

$allowed_sort = [
    'submission_date' => 's.submission_date',
    'score'           => 's.score',
    'student_name'    => 'u.name',
    'status'          => 's.status',
];
$sort_column = $allowed_sort[$sort_by] ?? 's.submission_date';

$conditions  = ["s.assignment_id = ?"];
$params      = [$assignment_id];
$param_types = "i";

if ($status_filter && in_array($status_filter, ['pending','submitted','graded','late','returned'])) {
    $conditions[]  = "s.status = ?";
    $params[]      = $status_filter;
    $param_types  .= "s";
}
if ($search) {
    $conditions[]  = "(u.name LIKE ? OR u.email LIKE ?)";
    $params[]      = "%$search%";
    $params[]      = "%$search%";
    $param_types  .= "ss";
}
if ($grade_range_min !== null && $grade_range_min !== false) {
    $conditions[]  = "s.score >= ?";
    $params[]      = $grade_range_min;
    $param_types  .= "d";
}
if ($grade_range_max !== null && $grade_range_max !== false) {
    $conditions[]  = "s.score <= ?";
    $params[]      = $grade_range_max;
    $param_types  .= "d";
}

$where_clause = implode(" AND ", $conditions);

$submissions_sql = "
    SELECT
        s.id, s.user_id, s.group_id, s.submission_date, s.file_paths, s.score,
        s.feedback, s.status, s.answers, s.submission_text,
        s.late_submission_reason, s.graded_at, s.graded_by,
        u.name  as student_name,
        u.email as student_email,
        g.name  as group_name
    FROM assignment_submissions s
    JOIN users u ON s.user_id = u.id
    LEFT JOIN groups g ON s.group_id = g.id
    WHERE $where_clause
    ORDER BY $sort_column $sort_order
    LIMIT ? OFFSET ?";

$params[]      = $per_page;
$params[]      = ($page - 1) * $per_page;
$param_types  .= "ii";

$submissions_stmt = $conn->prepare($submissions_sql);
$submissions      = [];

if ($submissions_stmt) {
    $submissions_stmt->bind_param($param_types, ...$params);
    $submissions_stmt->execute();
    $result = $submissions_stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $row['parsed_answers'] = parseStudentAnswers($row['answers']);
        if (empty($row['parsed_answers']) && !empty($row['submission_text'])) {
            $row['parsed_answers'] = [['answer' => $row['submission_text']]];
        }

        $row['parsed_file_paths'] = [];
        if ($row['file_paths']) {
            $dec = json_decode($row['file_paths'], true);
            $row['parsed_file_paths'] = (json_last_error() === JSON_ERROR_NONE && is_array($dec))
                ? $dec
                : array_filter(explode(',', $row['file_paths']));
        }

        $row['feedback_text'] = '';
        $row['feedback_data'] = [];
        if ($row['feedback']) {
            $dec = json_decode($row['feedback'], true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($dec)) {
                $row['feedback_text'] = $dec['overall'] ?? $row['feedback'];
                $row['feedback_data'] = $dec;
            } else {
                $row['feedback_text'] = $row['feedback'];
            }
        }

        $row['is_late'] = false;
        if ($assignment['due_date'] && $row['submission_date'] && $row['status'] !== 'graded') {
            try {
                $row['is_late'] = (new DateTime($row['submission_date'])) > (new DateTime($assignment['due_date']));
            } catch (Exception $e) {}
        }

        $row['score_percentage'] = null;
        if ($row['score'] !== null && $assignment['max_points'] > 0) {
            $row['score_percentage'] = round(($row['score'] / $assignment['max_points']) * 100, 1);
        }

        $submissions[] = $row;
    }
    $submissions_stmt->close();
} else {
    error_log("Submissions query prepare failed: " . $conn->error);
}

$count_stmt = $conn->prepare("SELECT COUNT(*) as total FROM assignment_submissions s WHERE s.assignment_id = ?");
$total_submissions = 0;
if ($count_stmt) {
    $count_stmt->bind_param("i", $assignment_id);
    $count_stmt->execute();
    $total_submissions = $count_stmt->get_result()->fetch_assoc()['total'];
    $count_stmt->close();
}
$total_pages = max(1, ceil($total_submissions / $per_page));

$metrics_sql = "
    SELECT
        COUNT(*)  as total_submissions,
        SUM(CASE WHEN status = 'graded'                    THEN 1 ELSE 0 END) as graded_submissions,
        SUM(CASE WHEN status IN ('pending','submitted')    THEN 1 ELSE 0 END) as pending_submissions,
        SUM(CASE WHEN status = 'late'                      THEN 1 ELSE 0 END) as late_submissions,
        SUM(CASE WHEN status = 'returned'                  THEN 1 ELSE 0 END) as returned_submissions,
        AVG(CASE WHEN status = 'graded' AND score IS NOT NULL THEN score END) as average_score,
        MAX(CASE WHEN status = 'graded' AND score IS NOT NULL THEN score END) as highest_score,
        MIN(CASE WHEN status = 'graded' AND score IS NOT NULL THEN score END) as lowest_score
    FROM assignment_submissions WHERE assignment_id = ?";
$metrics_stmt = $conn->prepare($metrics_sql);
$metrics = [];
if ($metrics_stmt) {
    $metrics_stmt->bind_param("i", $assignment_id);
    $metrics_stmt->execute();
    $metrics = $metrics_stmt->get_result()->fetch_assoc();
    $metrics_stmt->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Submissions: <?= htmlspecialchars($assignment['title']) ?> — QueensPa</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <style>
        * { font-family: 'Inter', system-ui, sans-serif; }

        .status-badge    { display:inline-flex; align-items:center; padding:.25rem .75rem; border-radius:9999px; font-size:.75rem; font-weight:500; }
        .status-graded   { background:#d1fae5; color:#065f46; }
        .status-pending  { background:#fef3c7; color:#92400e; }
        .status-submitted{ background:#dbeafe; color:#1e40af; }
        .status-late     { background:#fee2e2; color:#991b1b; }
        .status-returned { background:#f3e8ff; color:#6b21a5; }

        .modal { position:fixed; inset:0; background:rgba(0,0,0,.6); z-index:1000; display:none; justify-content:center; align-items:center; padding:1rem; }
        .modal.active { display:flex; }
        .modal-content { background:white; border-radius:1rem; max-width:820px; width:95%; max-height:90vh; overflow-y:auto; }

        .btn-primary   { background:#3b82f6; color:white; padding:.5rem 1rem; border-radius:.5rem; cursor:pointer; transition:all .2s; border:none; }
        .btn-primary:hover:not(:disabled) { background:#2563eb; }
        .btn-primary:disabled { background:#93c5fd; cursor:not-allowed; }
        .btn-secondary { background:#6b7280; color:white; padding:.5rem 1rem; border-radius:.5rem; cursor:pointer; transition:all .2s; border:none; }
        .btn-secondary:hover { background:#4b5563; }
        .btn-warning   { background:#f59e0b; color:white; padding:.5rem 1rem; border-radius:.5rem; cursor:pointer; transition:all .2s; border:none; }
        .btn-warning:hover { background:#d97706; }
        .btn-danger    { background:#ef4444; color:white; padding:.5rem 1rem; border-radius:.5rem; cursor:pointer; transition:all .2s; border:none; }
        .btn-danger:hover { background:#dc2626; }

        .loading-overlay { position:fixed; inset:0; background:rgba(0,0,0,.7); z-index:1100; display:none; justify-content:center; align-items:center; }
        .loading-overlay.active { display:flex; }

        .action-btn { transition:all .15s ease; border:none; background:transparent; }
        .action-btn:hover { transform:scale(1.18); }

        .toast { position:fixed; bottom:2rem; right:2rem; z-index:2000; min-width:300px; max-width:480px; animation:slideIn .3s ease; }
        @keyframes slideIn { from{transform:translateX(110%);opacity:0} to{transform:translateX(0);opacity:1} }

        .answer-card { border-left:4px solid #60a5fa; transition:border-color .2s, background .2s; }
        .answer-card:hover { border-left-color:#f97316; background:#fffbf7; }

        .stats-card { transition:transform .2s, box-shadow .2s; }
        .stats-card:hover { transform:translateY(-2px); box-shadow:0 10px 25px -5px rgba(0,0,0,.1); }

        .submission-row:hover { background:#f9fafb; }

        .score-badge { display:inline-block; padding:.2rem .6rem; border-radius:.4rem; font-weight:600; font-size:.85rem; }
        .score-high { background:#d1fae5; color:#065f46; }
        .score-mid  { background:#fef3c7; color:#92400e; }
        .score-low  { background:#fee2e2; color:#991b1b; }

        ::-webkit-scrollbar { width:6px; height:6px; }
        ::-webkit-scrollbar-track { background:#f1f1f1; border-radius:10px; }
        ::-webkit-scrollbar-thumb { background:#cbd5e1; border-radius:10px; }

        .ai-feedback-section { background:#f0fdf4; border:1px solid #bbf7d0; border-radius:.75rem; padding:1rem; margin-top:1rem; }
        .per-question-feedback { background:#eff6ff; border:1px solid #bfdbfe; border-radius:.5rem; padding:.75rem; margin-top:.5rem; }

        @media print { .no-print { display:none !important; } }
    </style>
</head>
<body class="bg-gray-50 min-h-screen">

<!-- Loading Overlay -->
<div id="loadingOverlay" class="loading-overlay">
    <div class="bg-white rounded-xl p-8 flex flex-col items-center shadow-2xl min-w-[280px]">
        <div class="relative mb-4">
            <i class="fas fa-spinner fa-spin text-blue-500 text-4xl"></i>
        </div>
        <p class="text-gray-700 font-semibold text-lg" id="loadingText">Processing…</p>
        <p class="text-gray-400 text-sm mt-1" id="loadingSubText">Please wait</p>
    </div>
</div>

<!-- Toast -->
<div id="toastContainer" class="toast" style="display:none;">
    <div class="rounded-xl shadow-2xl p-4 flex items-center gap-3" id="toastContent"></div>
</div>

<!-- ===================== SUPPORT PLAN MODAL ===================== -->
<div id="supportPlanModal" class="modal no-print">
    <div class="modal-content">
        <div class="bg-gradient-to-r from-purple-600 to-indigo-600 text-white px-6 py-4 rounded-t-xl flex justify-between items-center">
            <h3 class="text-lg font-semibold"><i class="fas fa-lightbulb mr-2"></i>AI Support Plan</h3>
            <button onclick="closeModal('supportPlanModal')" class="text-white hover:text-gray-200 text-3xl leading-none">&times;</button>
        </div>
        <div class="p-6">
            <div id="supportPlanContent" class="text-gray-700 text-sm leading-relaxed"></div>
            <div class="flex justify-end mt-6 gap-3">
                <button onclick="copyToClipboard('supportPlanContent')" class="btn-secondary">
                    <i class="fas fa-copy mr-2"></i>Copy Text
                </button>
                <button onclick="closeModal('supportPlanModal')" class="btn-primary">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- ===================== ANSWERS MODAL ===================== -->
<div id="answersModal" class="modal no-print">
    <div class="modal-content">
        <div class="bg-gradient-to-r from-blue-600 to-cyan-600 text-white px-6 py-4 rounded-t-xl flex justify-between items-center">
            <h3 class="text-lg font-semibold"><i class="fas fa-clipboard-check mr-2"></i>Student Answers</h3>
            <button onclick="closeModal('answersModal')" class="text-white hover:text-gray-200 text-3xl leading-none">&times;</button>
        </div>
        <div class="p-6">
            <div class="mb-4 pb-3 border-b border-gray-200 flex flex-wrap gap-4 text-sm">
                <p class="text-gray-500">Student: <span id="answersStudentName" class="font-semibold text-gray-800"></span></p>
                <p class="text-gray-500">Assignment: <span class="font-semibold text-gray-800"><?= htmlspecialchars($assignment['title']) ?></span></p>
                <p class="text-gray-500">Max: <span class="font-semibold text-gray-800"><?= $assignment['max_points'] ?> pts</span></p>
            </div>
            <div id="existingFeedbackStrip" class="hidden mb-4 bg-green-50 border border-green-200 rounded-lg p-3">
                <p class="text-xs font-semibold text-green-700 mb-1"><i class="fas fa-check-circle mr-1"></i>Graded Feedback</p>
                <p id="existingFeedbackText" class="text-sm text-green-800"></p>
            </div>
            <div id="answersModalContent" class="space-y-4 max-h-[55vh] overflow-y-auto pr-1"></div>
            <div class="flex flex-wrap justify-end mt-6 gap-3">
                <button id="gradeFromAnswersBtn" class="btn-primary hidden">
                    <i class="fas fa-edit mr-2"></i>Grade This Submission
                </button>
                <button id="aiGradeFromAnswersBtn" class="btn-primary hidden" style="background:#7c3aed;">
                    <i class="fas fa-robot mr-2"></i>AI Grade
                </button>
                <button onclick="closeModal('answersModal')" class="btn-secondary">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- ===================== AI GRADING RESULT MODAL ===================== -->
<div id="aiResultModal" class="modal no-print">
    <div class="modal-content">
        <div class="bg-gradient-to-r from-purple-600 to-indigo-600 text-white px-6 py-4 rounded-t-xl flex justify-between items-center">
            <h3 class="text-lg font-semibold"><i class="fas fa-robot mr-2"></i>AI Grading Result</h3>
            <button onclick="closeModal('aiResultModal')" class="text-white hover:text-gray-200 text-3xl leading-none">&times;</button>
        </div>
        <div class="p-6" id="aiResultContent"></div>
    </div>
</div>

<!-- ===================== GRADING MODAL ===================== -->
<div id="gradingModal" class="modal no-print">
    <div class="modal-content">
        <div class="bg-gradient-to-r from-green-600 to-green-700 text-white px-6 py-4 rounded-t-xl flex justify-between items-center">
            <h3 class="text-lg font-semibold"><i class="fas fa-graduation-cap mr-2"></i>Grade Submission</h3>
            <button onclick="closeModal('gradingModal')" class="text-white hover:text-gray-200 text-3xl leading-none">&times;</button>
        </div>
        <div class="p-6">
            <p class="text-gray-600 mb-4" id="gradingStudentName"></p>
            <div id="gradingQuestionPreview" class="mb-4 max-h-40 overflow-y-auto bg-gray-50 rounded-lg p-3 text-xs text-gray-600 space-y-1 hidden"></div>
            <div class="grid grid-cols-2 gap-4 mb-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">
                        Score <span class="text-gray-400">(max <?= $assignment['max_points'] ?> pts)</span>
                    </label>
                    <input type="number" id="gradeScore" step="0.5" min="0" max="<?= $assignment['max_points'] ?>"
                           class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 text-lg font-semibold">
                </div>
                <div class="flex items-end">
                    <div id="scorePreview" class="w-full text-center py-2 rounded-lg text-sm font-medium bg-gray-100 text-gray-500">
                        — / <?= $assignment['max_points'] ?>
                    </div>
                </div>
            </div>
            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-700 mb-1">Feedback for Student</label>
                <textarea id="gradeFeedback" rows="5"
                          class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500"
                          placeholder="Provide constructive feedback…"></textarea>
            </div>
            <input type="hidden" id="gradeSubmissionId">
            <div class="flex justify-end gap-3">
                <button onclick="closeModal('gradingModal')" class="btn-secondary">Cancel</button>
                <button id="submitGrade" class="btn-primary">
                    <i class="fas fa-save mr-2"></i>Save Grade
                </button>
            </div>
        </div>
    </div>
</div>

<!-- ===================== RETURN MODAL ===================== -->
<div id="returnModal" class="modal no-print">
    <div class="modal-content">
        <div class="bg-gradient-to-r from-yellow-500 to-orange-500 text-white px-6 py-4 rounded-t-xl flex justify-between items-center">
            <h3 class="text-lg font-semibold"><i class="fas fa-undo-alt mr-2"></i>Return for Revision</h3>
            <button onclick="closeModal('returnModal')" class="text-white hover:text-gray-200 text-3xl leading-none">&times;</button>
        </div>
        <div class="p-6">
            <p class="text-gray-600 mb-4">Return this submission to the student with revision instructions.</p>
            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-700 mb-1">
                    Revision Notes <span class="text-red-500">*</span>
                </label>
                <textarea id="revisionNotes" rows="4"
                          class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-yellow-500"
                          placeholder="Explain clearly what needs to be revised…"></textarea>
            </div>
            <input type="hidden" id="returnSubmissionId">
            <div class="flex justify-end gap-3">
                <button onclick="closeModal('returnModal')" class="btn-secondary">Cancel</button>
                <button id="confirmReturn" class="btn-warning">
                    <i class="fas fa-undo-alt mr-2"></i>Return for Revision
                </button>
            </div>
        </div>
    </div>
</div>

<!-- ===================== HEADER ===================== -->
<?php
$header_path = $_SERVER['DOCUMENT_ROOT'] . '/templates/teacher_header.php';
if (file_exists($header_path)) include $header_path;
else echo '<div class="bg-blue-600 text-white p-4 text-center font-semibold">QueensPa School Management System</div>';
?>

<div class="container mx-auto px-4 py-8 max-w-7xl">

    <!-- Page Header -->
    <div class="mb-8">
        <div class="flex flex-wrap justify-between items-start gap-4">
            <div>
                <div class="flex items-center gap-3 mb-2">
                    <div class="h-10 w-10 rounded-xl bg-blue-100 flex items-center justify-center">
                        <i class="fas fa-upload text-blue-500 text-xl"></i>
                    </div>
                    <h1 class="text-2xl font-bold text-gray-800">View Submissions</h1>
                </div>
                <p class="text-gray-600 ml-14">
                    <span class="font-semibold text-blue-600"><?= htmlspecialchars($assignment['title']) ?></span>
                    <span class="ml-2 text-xs bg-blue-100 text-blue-700 px-2 py-0.5 rounded-full capitalize"><?= htmlspecialchars($assignment['type']) ?></span>
                </p>
                <div class="flex flex-wrap gap-4 mt-2 ml-14 text-sm text-gray-500">
                    <span><i class="fas fa-book mr-1 text-gray-400"></i><?= htmlspecialchars($assignment['subject_name'] ?? 'N/A') ?></span>
                    <span><i class="fas fa-users mr-1 text-gray-400"></i><?= htmlspecialchars($assignment['class_name'] ?? 'N/A') ?></span>
                    <span><i class="fas fa-calendar mr-1 text-gray-400"></i>Due: <?= $assignment['due_date'] ? date('M j, Y', strtotime($assignment['due_date'])) : 'Not set' ?></span>
                    <span><i class="fas fa-star mr-1 text-gray-400"></i>Max: <?= $assignment['max_points'] ?> pts</span>
                </div>
                <?php if ($assignment['description']): ?>
                <div class="ml-14 mt-2 text-xs text-gray-400 max-w-2xl"><?= htmlspecialchars($assignment['description']) ?></div>
                <?php endif; ?>
            </div>
            <div class="flex gap-2 no-print flex-wrap">
                <button onclick="window.print()" class="bg-gray-200 hover:bg-gray-300 text-gray-700 px-4 py-2 rounded-lg text-sm flex items-center gap-2 transition-all">
                    <i class="fas fa-print"></i> Print
                </button>
                <a href="/teacher/manage_assignments.php" class="bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded-lg text-sm flex items-center gap-2 transition-all">
                    <i class="fas fa-arrow-left"></i> Back
                </a>
            </div>
        </div>
    </div>

    <!-- Stats -->
    <div class="grid grid-cols-2 sm:grid-cols-4 lg:grid-cols-7 gap-4 mb-8">
        <?php
        $stats = [
            ['val' => $metrics['total_submissions']   ?? 0,  'label' => 'Total',    'color' => 'text-blue-600',   'icon' => 'fa-layer-group'],
            ['val' => $metrics['pending_submissions']  ?? 0,  'label' => 'Pending',  'color' => 'text-yellow-600', 'icon' => 'fa-clock'],
            ['val' => $metrics['graded_submissions']   ?? 0,  'label' => 'Graded',   'color' => 'text-green-600',  'icon' => 'fa-check-circle'],
            ['val' => $metrics['late_submissions']     ?? 0,  'label' => 'Late',     'color' => 'text-red-600',    'icon' => 'fa-exclamation-circle'],
            ['val' => $metrics['returned_submissions'] ?? 0,  'label' => 'Returned', 'color' => 'text-purple-600', 'icon' => 'fa-undo'],
            ['val' => isset($metrics['average_score']) && $metrics['average_score'] !== null ? number_format($metrics['average_score'], 1) : '—', 'label' => 'Avg Score', 'color' => 'text-blue-600', 'icon' => 'fa-chart-bar'],
            ['val' => $metrics['highest_score'] ?? '—',       'label' => 'Highest',  'color' => 'text-green-600',  'icon' => 'fa-trophy'],
        ];
        foreach ($stats as $s):
        ?>
        <div class="bg-white rounded-xl shadow-sm p-4 text-center border border-gray-100 stats-card">
            <i class="fas <?= $s['icon'] ?> <?= $s['color'] ?> text-lg mb-1 block opacity-70"></i>
            <div class="text-2xl font-bold <?= $s['color'] ?>"><?= $s['val'] ?></div>
            <div class="text-xs text-gray-500 mt-0.5"><?= $s['label'] ?></div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Questions Summary -->
    <div class="bg-white rounded-xl shadow-sm mb-6 border border-gray-100">
        <button onclick="toggleSection('questionsSummary')" class="w-full px-6 py-4 flex justify-between items-center text-left hover:bg-gray-50 rounded-xl transition-all">
            <h3 class="font-semibold text-gray-800">
                <i class="fas fa-question-circle mr-2 text-blue-500"></i>
                Assignment Questions
                <span class="text-gray-400 font-normal text-sm ml-2">(<?= count($questions) ?> question<?= count($questions) !== 1 ? 's' : '' ?>)</span>
            </h3>
            <i id="questionsSummaryIcon" class="fas fa-chevron-down text-gray-400 transition-transform duration-200"></i>
        </button>
        <div id="questionsSummary" class="hidden border-t border-gray-100 divide-y divide-gray-50">
            <?php foreach ($questions as $i => $q): ?>
            <div class="px-6 py-3 flex gap-4 items-start">
                <span class="flex-shrink-0 w-7 h-7 rounded-full bg-blue-100 text-blue-700 text-xs font-bold flex items-center justify-center mt-0.5">
                    <?= $i + 1 ?>
                </span>
                <div class="flex-1 min-w-0">
                    <p class="text-sm text-gray-700 break-words"><?= htmlspecialchars($q['question_text']) ?></p>
                    <div class="flex flex-wrap gap-3 mt-1 text-xs text-gray-400">
                        <?php if ($q['points'] > 0): ?>
                        <span><i class="fas fa-star mr-1"></i><?= $q['points'] ?> pts</span>
                        <?php endif; ?>
                        <span class="capitalize"><?= htmlspecialchars($q['type'] ?? 'short_answer') ?></span>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Filters -->
    <div class="bg-white rounded-xl shadow-sm mb-6 border border-gray-100 no-print">
        <div class="px-6 py-4 border-b border-gray-200">
            <h3 class="font-semibold text-gray-800"><i class="fas fa-filter mr-2 text-blue-500"></i>Filter Submissions</h3>
        </div>
        <div class="p-6">
            <form id="filterForm" method="GET" class="grid grid-cols-1 md:grid-cols-5 gap-4">
                <input type="hidden" name="assignment_id" value="<?= $assignment_id ?>">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Status</label>
                    <select name="status" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                        <option value="">All Statuses</option>
                        <?php foreach (['pending','submitted','graded','late','returned'] as $st): ?>
                        <option value="<?= $st ?>" <?= $status_filter === $st ? 'selected' : '' ?>><?= ucfirst($st) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Search Student</label>
                    <input type="text" name="search" value="<?= htmlspecialchars($search) ?>"
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500"
                           placeholder="Name or email…">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Min Score</label>
                    <input type="number" name="grade_min" step="0.5" value="<?= htmlspecialchars($grade_range_min ?? '') ?>"
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500" placeholder="0">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Max Score</label>
                    <input type="number" name="grade_max" step="0.5" value="<?= htmlspecialchars($grade_range_max ?? '') ?>"
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500" placeholder="<?= $assignment['max_points'] ?>">
                </div>
                <div class="flex gap-2 items-end">
                    <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg flex-1 transition-all">
                        <i class="fas fa-search mr-2"></i>Apply
                    </button>
                    <a href="?assignment_id=<?= $assignment_id ?>" class="bg-gray-400 hover:bg-gray-500 text-white px-4 py-2 rounded-lg transition-all text-center" title="Clear filters">
                        <i class="fas fa-undo"></i>
                    </a>
                </div>
            </form>
        </div>
    </div>

    <!-- Submissions Table -->
    <div class="bg-white rounded-xl shadow-sm overflow-hidden border border-gray-100">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Student</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Group</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Submitted</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Files</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Score</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Status</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider no-print">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200">
                    <?php if (empty($submissions)): ?>
                        <tr>
                            <td colspan="7" class="px-4 py-16 text-center text-gray-500">
                                <i class="fas fa-inbox text-6xl mb-4 block text-gray-200"></i>
                                <p class="text-lg font-medium">No submissions found</p>
                                <p class="text-sm text-gray-400 mt-1">
                                    <?= ($status_filter || $search) ? 'Try adjusting your filters.' : "Students haven't submitted this assignment yet." ?>
                                </p>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($submissions as $sub): ?>
                        <tr class="submission-row transition-colors" id="submission-<?= $sub['id'] ?>">
                            <td class="px-4 py-3">
                                <div class="font-medium text-gray-800"><?= htmlspecialchars($sub['student_name']) ?></div>
                                <div class="text-xs text-gray-500"><?= htmlspecialchars($sub['student_email']) ?></div>
                            </td>
                            <td class="px-4 py-3 text-sm">
                                <?php if ($sub['group_name']): ?>
                                    <span class="inline-flex items-center px-2 py-1 rounded-full text-xs bg-blue-100 text-blue-700">
                                        <i class="fas fa-users mr-1 text-xs"></i><?= htmlspecialchars($sub['group_name']) ?>
                                    </span>
                                <?php else: ?>
                                    <span class="text-gray-400 text-xs">Individual</span>
                                <?php endif; ?>
                            </td>
                            <td class="px-4 py-3 text-sm text-gray-600">
                                <?= $sub['submission_date'] ? date('M j, Y g:i A', strtotime($sub['submission_date'])) : '—' ?>
                                <?php if ($sub['is_late']): ?>
                                    <span class="ml-1 text-red-500 text-xs"><i class="fas fa-exclamation-triangle"></i> Late</span>
                                <?php endif; ?>
                                <?php if ($sub['late_submission_reason']): ?>
                                    <div class="text-xs text-gray-400 mt-0.5 italic">
                                        <?= htmlspecialchars(mb_strimwidth($sub['late_submission_reason'], 0, 60, '…')) ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td class="px-4 py-3">
                                <?php if (!empty($sub['parsed_file_paths'])): ?>
                                    <div class="flex flex-col gap-1">
                                        <?php foreach ($sub['parsed_file_paths'] as $file): ?>
                                            <?php if (trim($file)): ?>
                                            <a href="<?= htmlspecialchars(trim($file)) ?>" target="_blank"
                                               class="text-blue-500 hover:text-blue-700 text-xs flex items-center gap-1">
                                                <i class="fas fa-file-alt"></i>
                                                <span class="truncate max-w-[90px]"><?= htmlspecialchars(basename($file)) ?></span>
                                            </a>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                    </div>
                                <?php else: ?>
                                    <span class="text-gray-400 text-xs">—</span>
                                <?php endif; ?>
                            </td>
                            <td class="px-4 py-3">
                                <?php if ($sub['score'] !== null): ?>
                                    <?php
                                    $pct = $sub['score_percentage'];
                                    $cls = $pct >= 70 ? 'score-high' : ($pct >= 50 ? 'score-mid' : 'score-low');
                                    ?>
                                    <span class="score-badge <?= $cls ?>">
                                        <?= number_format($sub['score'], 1) ?> / <?= $assignment['max_points'] ?>
                                    </span>
                                    <div class="text-xs text-gray-400 mt-0.5"><?= $pct ?>%</div>
                                <?php else: ?>
                                    <span class="text-gray-400 text-sm">—</span>
                                <?php endif; ?>
                            </td>
                            <td class="px-4 py-3">
                                <span class="status-badge status-<?= $sub['status'] ?>">
                                    <?= ucfirst($sub['status']) ?>
                                </span>
                            </td>
                            <td class="px-4 py-3 no-print">
                                <div class="flex flex-wrap gap-1.5 items-center">
                                    <!-- View Answers -->
                                    <button class="action-btn view-answers-btn text-blue-500 hover:text-blue-700 p-1.5 rounded-lg hover:bg-blue-50"
                                            data-submission-id="<?= $sub['id'] ?>"
                                            data-student-name="<?= htmlspecialchars($sub['student_name'], ENT_QUOTES) ?>"
                                            data-answers='<?= htmlspecialchars(json_encode($sub['parsed_answers'], JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>'
                                            data-feedback="<?= htmlspecialchars($sub['feedback_text'], ENT_QUOTES) ?>"
                                            data-score="<?= $sub['score'] ?? '' ?>"
                                            title="View Answers">
                                        <i class="fas fa-eye"></i>
                                    </button>

                                    <!-- Manual Grade -->
                                    <button class="action-btn manual-grade-btn text-green-500 hover:text-green-700 p-1.5 rounded-lg hover:bg-green-50"
                                            data-submission-id="<?= $sub['id'] ?>"
                                            data-student-name="<?= htmlspecialchars($sub['student_name'], ENT_QUOTES) ?>"
                                            data-current-score="<?= $sub['score'] ?? '' ?>"
                                            data-current-feedback="<?= htmlspecialchars($sub['feedback_text'], ENT_QUOTES) ?>"
                                            title="Manual Grade">
                                        <i class="fas fa-edit"></i>
                                    </button>

                                    <!-- AI Grade -->
                                    <button class="action-btn grade-ai-btn text-purple-500 hover:text-purple-700 p-1.5 rounded-lg hover:bg-purple-50"
                                            data-submission-id="<?= $sub['id'] ?>"
                                            data-student-name="<?= htmlspecialchars($sub['student_name'], ENT_QUOTES) ?>"
                                            title="Grade with AI (Gemini)">
                                        <i class="fas fa-robot"></i>
                                    </button>

                                    <!-- Return for Revision -->
                                    <?php if (!in_array($sub['status'], ['graded','returned'])): ?>
                                    <button class="action-btn return-btn text-yellow-500 hover:text-yellow-700 p-1.5 rounded-lg hover:bg-yellow-50"
                                            data-submission-id="<?= $sub['id'] ?>"
                                            data-student-name="<?= htmlspecialchars($sub['student_name'], ENT_QUOTES) ?>"
                                            title="Return for Revision">
                                        <i class="fas fa-undo-alt"></i>
                                    </button>
                                    <?php endif; ?>

                                    <!-- AI Support Plan -->
                                    <button class="action-btn support-plan-btn text-indigo-500 hover:text-indigo-700 p-1.5 rounded-lg hover:bg-indigo-50"
                                            data-submission-id="<?= $sub['id'] ?>"
                                            data-student-name="<?= htmlspecialchars($sub['student_name'], ENT_QUOTES) ?>"
                                            title="AI Support Plan">
                                        <i class="fas fa-lightbulb"></i>
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <?php if ($total_pages > 1): ?>
        <div class="px-4 py-3 bg-gray-50 border-t border-gray-200 flex flex-col sm:flex-row justify-between items-center gap-3 no-print">
            <div class="text-sm text-gray-600">
                Page <?= $page ?> of <?= $total_pages ?> &nbsp;·&nbsp; <?= $total_submissions ?> total
            </div>
            <div class="flex gap-1">
                <?php
                $qp = array_filter([
                    'assignment_id' => $assignment_id,
                    'status'        => $status_filter,
                    'search'        => $search,
                    'grade_min'     => $grade_range_min,
                    'grade_max'     => $grade_range_max,
                ]);
                ?>
                <?php if ($page > 1): ?>
                <a href="?<?= http_build_query(array_merge($qp, ['page' => $page - 1])) ?>"
                   class="px-3 py-1 bg-white border border-gray-300 rounded-lg text-sm hover:bg-gray-50">← Prev</a>
                <?php endif; ?>
                <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                <a href="?<?= http_build_query(array_merge($qp, ['page' => $i])) ?>"
                   class="px-3 py-1 <?= $i == $page ? 'bg-blue-600 text-white border-blue-600' : 'bg-white border border-gray-300 hover:bg-blue-50' ?> rounded-lg text-sm border">
                    <?= $i ?>
                </a>
                <?php endfor; ?>
                <?php if ($page < $total_pages): ?>
                <a href="?<?= http_build_query(array_merge($qp, ['page' => $page + 1])) ?>"
                   class="px-3 py-1 bg-white border border-gray-300 rounded-lg text-sm hover:bg-gray-50">Next →</a>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Legend -->
    <div class="mt-4 flex flex-wrap gap-4 text-xs text-gray-500 no-print">
        <span class="flex items-center gap-1"><i class="fas fa-eye text-blue-400"></i> View Answers</span>
        <span class="flex items-center gap-1"><i class="fas fa-edit text-green-400"></i> Manual Grade</span>
        <span class="flex items-center gap-1"><i class="fas fa-robot text-purple-400"></i> AI Grade</span>
        <span class="flex items-center gap-1"><i class="fas fa-undo-alt text-yellow-400"></i> Return for Revision</span>
        <span class="flex items-center gap-1"><i class="fas fa-lightbulb text-indigo-400"></i> AI Support Plan</span>
    </div>
</div>

<!-- Footer -->
<?php
$footer_path = $_SERVER['DOCUMENT_ROOT'] . '/templates/teacher_footer.php';
if (file_exists($footer_path)) include $footer_path;
else echo '<div class="text-center text-gray-400 text-sm py-6">QueensPa School Management System &copy; ' . date('Y') . '</div>';
mysqli_close($conn);
ob_end_flush();
?>

<script>
$(function () {

    // ── Constants ────────────────────────────────────────────────────────────
    const CSRF_TOKEN = <?= json_encode($csrf_token) ?>;
    const MAX_POINTS = <?= (float)$assignment['max_points'] ?>;
    const QUESTIONS  = <?= json_encode($questions, JSON_UNESCAPED_UNICODE) ?>;
    const AJAX_URL   = window.location.href.split('?')[0] + '?assignment_id=<?= $assignment_id ?>';

    // ── Utility ──────────────────────────────────────────────────────────────
    function esc(t) {
        if (t == null) return '';
        const d = document.createElement('div');
        d.textContent = String(t);
        return d.innerHTML;
    }

    function showToast(msg, type = 'info') {
        const palette = { success:'bg-green-500', error:'bg-red-500', warning:'bg-amber-500', info:'bg-blue-500' };
        const icons   = { success:'fa-check-circle', error:'fa-exclamation-circle', warning:'fa-exclamation-triangle', info:'fa-info-circle' };
        $('#toastContent')
            .attr('class', `rounded-xl shadow-2xl p-4 flex items-center gap-3 text-white ${palette[type]}`)
            .html(`<i class="fas ${icons[type]} text-xl flex-shrink-0"></i><span class="text-sm font-medium">${esc(msg)}</span>`);
        $('#toastContainer').stop(true, true).fadeIn(300).delay(5000).fadeOut(400);
    }

    function showLoading(main = 'Processing…', sub = 'Please wait') {
        $('#loadingText').text(main);
        $('#loadingSubText').text(sub);
        $('#loadingOverlay').addClass('active');
    }
    function hideLoading() { $('#loadingOverlay').removeClass('active'); }

    window.closeModal = id => { $('#' + id).removeClass('active'); };

    // Close modal on backdrop click
    $(document).on('click', '.modal', function (e) {
        if (e.target === this) $(this).removeClass('active');
    });
    $(document).on('keydown', e => {
        if (e.key === 'Escape') $('.modal.active').first().removeClass('active');
    });

    window.toggleSection = function (id) {
        $('#' + id).toggleClass('hidden');
        $('#' + id + 'Icon').toggleClass('rotate-180');
    };

    window.copyToClipboard = function (id) {
        const t = document.getElementById(id)?.innerText || '';
        navigator.clipboard.writeText(t)
            .then(() => showToast('Copied to clipboard!', 'success'))
            .catch(() => showToast('Copy failed — please copy manually.', 'error'));
    };

    // ── Central AJAX helper ──────────────────────────────────────────────────
    function ajaxAction(action, extraData, onSuccess, onError) {
        $.ajax({
            url: AJAX_URL, method: 'POST', dataType: 'json',
            data: Object.assign({ action, csrf_token: CSRF_TOKEN }, extraData),
            success(r) {
                hideLoading();
                if (r.success) {
                    onSuccess(r);
                } else {
                    showToast(r.message || 'Operation failed.', 'error');
                    if (onError) onError(r.message);
                }
            },
            error(xhr) {
                hideLoading();
                let msg = 'Request failed. Please check your connection and try again.';
                try { const j = JSON.parse(xhr.responseText); msg = j.message || msg; } catch(e) {}
                showToast(msg, 'error');
                if (onError) onError(msg);
            }
        });
    }

    // ── Build answer cards HTML ──────────────────────────────────────────────
    function buildAnswersHtml(answers) {
        if (!answers || answers.length === 0) {
            return `<div class="text-center text-gray-400 py-10">
                        <i class="fas fa-comment-slash text-4xl mb-3 block"></i>
                        <p class="font-medium">No answers were submitted.</p>
                    </div>`;
        }
        let html = '';
        const len = Math.max(answers.length, QUESTIONS.length);
        for (let idx = 0; idx < len; idx++) {
            const q     = QUESTIONS[idx] || {};
            const qText = q.question_text || ('Question ' + (idx + 1));
            const pts   = q.points > 0 ? `<span class="text-blue-500 font-semibold">${esc(q.points)} pts</span>` : '';
            const qType = q.type || '';

            let ansText = '<em class="text-gray-400">Not answered</em>';
            let unanswered = true;
            if (answers[idx] != null) {
                unanswered = false;
                const ans = answers[idx];
                if (typeof ans === 'string') ansText = ans;
                else if (ans.answer != null) ansText = ans.answer;
                else if (ans.text != null)   ansText = ans.text;
                else ansText = JSON.stringify(ans);
            }

            let correctNote = '';
            if (!unanswered && q.correct_answer && qType === 'multiple_choice') {
                const isOk = String(ansText).trim().toLowerCase() === String(q.correct_answer).trim().toLowerCase();
                correctNote = isOk
                    ? `<span class="ml-2 text-xs text-green-600 font-semibold"><i class="fas fa-check mr-1"></i>Correct</span>`
                    : `<span class="ml-2 text-xs text-red-500 font-semibold"><i class="fas fa-times mr-1"></i>Expected: ${esc(q.correct_answer)}</span>`;
            }

            html += `
                <div class="answer-card rounded-lg p-4 ${unanswered ? 'opacity-55' : ''}">
                    <div class="flex justify-between items-start gap-2 mb-2">
                        <span class="font-semibold text-gray-800 text-sm leading-snug">
                            <span class="inline-flex items-center justify-center w-6 h-6 rounded-full bg-blue-100 text-blue-700 text-xs font-bold mr-1 flex-shrink-0">${idx+1}</span>
                            ${esc(qText)}
                        </span>
                        <span class="text-xs text-gray-400 flex-shrink-0">${pts}</span>
                    </div>
                    <div>
                        <span class="text-xs text-gray-400 uppercase tracking-wide">Answer</span>
                        <div class="mt-1 p-3 bg-white rounded-lg border ${unanswered ? 'border-dashed border-gray-300' : 'border-gray-200'} text-sm text-gray-700 whitespace-pre-wrap">
                            ${unanswered ? '<em class="text-gray-400">Not answered</em>' : esc(ansText)}${correctNote}
                        </div>
                    </div>
                </div>`;
        }
        return html;
    }

    // ── VIEW ANSWERS ─────────────────────────────────────────────────────────
    $(document).on('click', '.view-answers-btn', function () {
        const $btn   = $(this);
        const subId  = $btn.data('submission-id');
        const name   = $btn.data('student-name');
        const fbk    = $btn.data('feedback') || '';
        const score  = $btn.data('score');
        let answers  = $btn.data('answers');

        if (typeof answers === 'string') {
            try { answers = JSON.parse(answers); } catch(e) { answers = [{ answer: answers }]; }
        }
        if (!Array.isArray(answers)) answers = answers ? [answers] : [];

        $('#answersStudentName').text(name);

        if (fbk && score !== '') {
            $('#existingFeedbackStrip').removeClass('hidden');
            $('#existingFeedbackText').text(`Score: ${score} / ${MAX_POINTS}  —  ${fbk}`);
        } else {
            $('#existingFeedbackStrip').addClass('hidden');
        }

        $('#answersModalContent').html(buildAnswersHtml(answers));

        // Grade shortcuts
        $('#gradeFromAnswersBtn').removeClass('hidden').off('click').on('click', function () {
            closeModal('answersModal');
            $(`.manual-grade-btn[data-submission-id="${subId}"]`).trigger('click');
        });
        $('#aiGradeFromAnswersBtn').removeClass('hidden').off('click').on('click', function () {
            closeModal('answersModal');
            $(`.grade-ai-btn[data-submission-id="${subId}"]`).trigger('click');
        });

        $('#answersModal').addClass('active');
    });

    // Double-click row to view answers
    $(document).on('dblclick', '.submission-row td:not(:last-child)', function () {
        $(this).closest('tr').find('.view-answers-btn').trigger('click');
    });

    // ── MANUAL GRADE ─────────────────────────────────────────────────────────
    $(document).on('click', '.manual-grade-btn', function () {
        const $btn   = $(this);
        const subId  = $btn.data('submission-id');
        const name   = $btn.data('student-name');
        const score  = $btn.data('current-score');
        const fbk    = $btn.data('current-feedback') || '';

        $('#gradeSubmissionId').val(subId);
        $('#gradingStudentName').html(`Grading for <strong>${esc(name)}</strong>`);
        $('#gradeScore').val(score !== undefined && score !== '' ? score : '');
        $('#gradeFeedback').val(fbk);

        const previewHtml = QUESTIONS.map((q, i) =>
            `<div class="flex gap-2 items-start">
                <span class="text-blue-500 font-bold flex-shrink-0">Q${i+1}.</span>
                <span class="flex-1">${esc(q.question_text)}</span>
                ${q.points > 0 ? `<span class="text-gray-300 flex-shrink-0">${q.points}pts</span>` : ''}
            </div>`
        ).join('');
        if (QUESTIONS.length > 0) {
            $('#gradingQuestionPreview').html(previewHtml).removeClass('hidden');
        }

        updateScorePreview();
        $('#gradingModal').addClass('active');
        setTimeout(() => $('#gradeScore').focus(), 200);
    });

    function updateScorePreview() {
        const val = parseFloat($('#gradeScore').val());
        const el  = $('#scorePreview');
        if (isNaN(val)) {
            el.text(`— / ${MAX_POINTS}`)
              .attr('class', 'w-full text-center py-2 rounded-lg text-sm font-medium bg-gray-100 text-gray-500');
        } else {
            const pct = (val / MAX_POINTS) * 100;
            const cls = pct >= 70 ? 'bg-green-100 text-green-700' : (pct >= 50 ? 'bg-amber-100 text-amber-700' : 'bg-red-100 text-red-700');
            el.text(`${val} / ${MAX_POINTS}  (${pct.toFixed(1)}%)`)
              .attr('class', `w-full text-center py-2 rounded-lg text-sm font-medium ${cls}`);
        }
    }
    $('#gradeScore').on('input', updateScorePreview);

    $('#submitGrade').on('click', function () {
        const subId = $('#gradeSubmissionId').val();
        const score = parseFloat($('#gradeScore').val());
        const fbk   = $('#gradeFeedback').val().trim();

        if (isNaN(score))                         { showToast('Please enter a valid score.', 'warning'); return; }
        if (score < 0 || score > MAX_POINTS)      { showToast(`Score must be between 0 and ${MAX_POINTS}.`, 'warning'); return; }

        const $btn = $(this);
        $btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin mr-2"></i>Saving…');
        showLoading('Saving grade…', 'Updating submission record');

        ajaxAction('manual_grade', { submission_id: subId, score, feedback: fbk }, r => {
            showToast(r.message, 'success');
            closeModal('gradingModal');
            // Update score in table without full reload
            const $row = $(`#submission-${subId}`);
            const pct  = (r.score / MAX_POINTS) * 100;
            const cls  = pct >= 70 ? 'score-high' : (pct >= 50 ? 'score-mid' : 'score-low');
            $row.find('td:eq(4)').html(`<span class="score-badge ${cls}">${parseFloat(r.score).toFixed(1)} / ${MAX_POINTS}</span><div class="text-xs text-gray-400 mt-0.5">${pct.toFixed(1)}%</div>`);
            $row.find('td:eq(5)').html('<span class="status-badge status-graded">Graded</span>');
            $btn.prop('disabled', false).html('<i class="fas fa-save mr-2"></i>Save Grade');
        }, () => {
            $btn.prop('disabled', false).html('<i class="fas fa-save mr-2"></i>Save Grade');
        });
    });

    // ── AI GRADING ───────────────────────────────────────────────────────────
    $(document).on('click', '.grade-ai-btn', function () {
        const subId = $(this).data('submission-id');
        const name  = $(this).data('student-name');

        if (!confirm(`Grade ${name}'s submission with Gemini AI?\n\nThis will automatically assign a score and detailed feedback.`)) return;

        showLoading('Gemini AI is grading…', 'This may take up to 30 seconds');

        ajaxAction('grade_with_ai', { submission_id: subId }, r => {
            showToast(`AI Graded! Score: ${r.score} / ${MAX_POINTS} (${r.score_percentage}%)`, 'success');

            // Build result display
            const perQHtml = (r.per_question && r.per_question.length > 0)
                ? r.per_question.map((fb, i) =>
                    `<div class="per-question-feedback">
                        <span class="font-semibold text-blue-700 text-xs">Q${i+1} feedback:</span>
                        <p class="text-sm text-gray-700 mt-0.5">${esc(fb)}</p>
                    </div>`
                  ).join('')
                : '';

            const pct = r.score_percentage;
            const cls = pct >= 70 ? 'bg-green-100 text-green-700 border-green-200' : (pct >= 50 ? 'bg-amber-100 text-amber-700 border-amber-200' : 'bg-red-100 text-red-700 border-red-200');

            $('#aiResultContent').html(`
                <div class="text-center mb-5">
                    <div class="text-5xl font-bold ${pct >= 70 ? 'text-green-600' : (pct >= 50 ? 'text-amber-600' : 'text-red-600')}">${r.score}</div>
                    <div class="text-gray-500 text-sm">out of ${MAX_POINTS} points (${pct}%)</div>
                    <div class="mt-2 inline-block px-3 py-1 rounded-full text-sm font-semibold border ${cls}">
                        ${pct >= 70 ? '✓ Passing' : (pct >= 50 ? '⚠ Average' : '✗ Needs Improvement')}
                    </div>
                </div>
                <div class="ai-feedback-section">
                    <p class="text-xs font-semibold text-green-700 mb-2"><i class="fas fa-robot mr-1"></i>Overall AI Feedback</p>
                    <p class="text-sm text-gray-800">${esc(r.overall_feedback)}</p>
                </div>
                ${perQHtml ? `<div class="mt-4"><p class="text-xs font-semibold text-gray-600 mb-2 uppercase tracking-wide">Per-Question Feedback</p>${perQHtml}</div>` : ''}
                <div class="flex justify-end mt-5 gap-3">
                    <button class="btn-secondary" onclick="closeModal('aiResultModal')">Close</button>
                    <button class="btn-primary" onclick="closeModal('aiResultModal');location.reload()">
                        <i class="fas fa-sync mr-2"></i>Refresh Page
                    </button>
                </div>`);

            // Update row inline
            const $row = $(`#submission-${subId}`);
            const cls2 = pct >= 70 ? 'score-high' : (pct >= 50 ? 'score-mid' : 'score-low');
            $row.find('td:eq(4)').html(`<span class="score-badge ${cls2}">${parseFloat(r.score).toFixed(1)} / ${MAX_POINTS}</span><div class="text-xs text-gray-400 mt-0.5">${pct}%</div>`);
            $row.find('td:eq(5)').html('<span class="status-badge status-graded">Graded</span>');

            $('#aiResultModal').addClass('active');
        });
    });

    // ── RETURN FOR REVISION ──────────────────────────────────────────────────
    $(document).on('click', '.return-btn', function () {
        const subId = $(this).data('submission-id');
        const name  = $(this).data('student-name');
        $('#returnSubmissionId').val(subId);
        $('#revisionNotes').val('').attr('placeholder', `Provide revision instructions for ${name}…`);
        $('#returnModal').addClass('active');
        setTimeout(() => $('#revisionNotes').focus(), 200);
    });

    $('#confirmReturn').on('click', function () {
        const subId = $('#returnSubmissionId').val();
        const notes = $('#revisionNotes').val().trim();
        if (!notes) { showToast('Please enter revision notes for the student.', 'warning'); return; }

        const $btn = $(this);
        $btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin mr-2"></i>Returning…');
        showLoading('Returning submission…', 'Notifying student');

        ajaxAction('return_submission', { submission_id: subId, revision_notes: notes }, r => {
            showToast(r.message, 'success');
            closeModal('returnModal');
            $(`#submission-${subId} td:eq(5)`).html('<span class="status-badge status-returned">Returned</span>');
            $(`#submission-${subId} .return-btn`).remove();
            $btn.prop('disabled', false).html('<i class="fas fa-undo-alt mr-2"></i>Return for Revision');
        }, () => {
            $btn.prop('disabled', false).html('<i class="fas fa-undo-alt mr-2"></i>Return for Revision');
        });
    });

    // ── AI SUPPORT PLAN ──────────────────────────────────────────────────────
    $(document).on('click', '.support-plan-btn', function () {
        const subId = $(this).data('submission-id');
        const name  = $(this).data('student-name');

        $('#supportPlanContent').html(`
            <div class="text-center py-12">
                <i class="fas fa-spinner fa-spin text-purple-400 text-5xl mb-4"></i>
                <p class="text-gray-600 font-semibold">Generating AI support plan…</p>
                <p class="text-xs text-gray-400 mt-1">Analysing ${esc(name)}'s responses with Gemini</p>
            </div>`);
        $('#supportPlanModal').addClass('active');

        ajaxAction('generate_support_plan', { submission_id: subId }, r => {
            // Lightweight Markdown-ish render
            let html = String(r.support_plan)
                .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')
                .replace(/\*\*(.*?)\*\*/g,'<strong>$1</strong>')
                .replace(/\*(.*?)\*/g,'<em>$1</em>')
                .replace(/^#{1,3}\s+(.+)$/gm,'<h4 class="font-semibold text-gray-800 mt-4 mb-1 text-base">$1</h4>')
                .replace(/^\d+\.\s+(.+)$/gm,'<div class="ml-5 mb-1.5">• $1</div>')
                .replace(/^[-•]\s+(.+)$/gm,'<div class="ml-5 mb-1.5 flex gap-2"><span class="text-purple-400 flex-shrink-0">•</span><span>$1</span></div>')
                .replace(/\n{2,}/g,'</p><p class="mb-3">')
                .replace(/\n/g,'<br>');

            $('#supportPlanContent').html(`
                <div class="bg-purple-50 border border-purple-200 rounded-lg p-3 mb-4 flex items-center gap-2">
                    <i class="fas fa-robot text-purple-500"></i>
                    <span class="text-sm text-purple-700 font-semibold">AI Support Plan — ${esc(name)}</span>
                </div>
                <div class="text-gray-700 text-sm leading-relaxed bg-gray-50 rounded-xl p-5 border border-gray-100">${html}</div>
                <p class="text-xs text-gray-400 mt-3 text-right">Generated at ${new Date().toLocaleString()}</p>`);
        }, errMsg => {
            $('#supportPlanContent').html(`
                <div class="text-center py-8">
                    <i class="fas fa-exclamation-triangle text-red-400 text-4xl mb-3"></i>
                    <p class="text-red-600 font-semibold">Could not generate support plan</p>
                    <p class="text-sm text-gray-500 mt-1">${esc(errMsg || 'Unknown error')}</p>
                    <button onclick="closeModal('supportPlanModal')" class="btn-secondary mt-4">Close</button>
                </div>`);
        });
    });

});
</script>
</body>
</html>