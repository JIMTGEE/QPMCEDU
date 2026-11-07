<?php
ob_start();

// FIX (ported from weekly_plan_oxford.php): session.gc_maxlifetime and
// session_set_cookie_params() are session-related ini settings — PHP refuses to
// apply them (and emits a warning) once a session is already active. They must
// run BEFORE session_start(), not after.
ini_set('session.gc_maxlifetime', 86400);
session_set_cookie_params(86400);
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Extended timeout for AI operations
ini_set('max_execution_time', 300);
ini_set('memory_limit', '256M');

/**
 * Converts a php.ini shorthand size (e.g. "8M", "2G") to bytes.
 */
function iniSizeToBytes($val) {
    $val = trim((string)$val);
    if ($val === '') return 0;
    $last = strtolower($val[strlen($val) - 1]);
    $num = (int)$val;
    switch ($last) {
        case 'g': $num *= 1024;
        case 'm': $num *= 1024;
        case 'k': $num *= 1024;
    }
    return $num;
}

// ---- FIX: detect a POST body PHP rejected for exceeding post_max_size ----
// When an uploaded file (or the whole multipart body) exceeds post_max_size,
// PHP empties $_POST and $_FILES entirely with no inspectable error flag.
// Without this check, such a request silently falls through this whole file
// and renders the full HTML page back as the "AJAX" response, which the
// front-end can't parse as JSON — surfacing as a vague "network error".
if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($_POST) && empty($_FILES)) {
    $content_length = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
    $post_max_bytes = iniSizeToBytes(ini_get('post_max_size'));
    if ($content_length > 0 && $post_max_bytes > 0 && $content_length > $post_max_bytes) {
        error_log("Upload rejected: POST body {$content_length} bytes exceeds post_max_size {$post_max_bytes} bytes (post_max_size=" . ini_get('post_max_size') . ")");
        header('Content-Type: application/json');
        http_response_code(413);
        echo json_encode([
            'success' => false,
            'error' => 'That file is too large for the server to accept right now (server limit is ' . ini_get('post_max_size') . '). Please ask an administrator to raise post_max_size and upload_max_filesize (and client_max_body_size, if using nginx), or try a smaller file.',
        ]);
        exit();
    }
    // Otherwise let normal handling continue; downstream checks report their own errors.
}

// Restrict to teachers
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'teacher') {
    error_log("Unauthorized access: user_id=" . ($_SESSION['user_id'] ?? 'unset'));
    $_SESSION['error_msg'] = "Please log in as a teacher.";
    header("Location: /login.php");
    exit();
}

// Database connection
try {
    require_once($_SERVER['DOCUMENT_ROOT'] . '/config/db.php');
} catch (Exception $e) {
    error_log("DB include failed: " . $e->getMessage());
    $_SESSION['error_msg'] = "System error: Unable to connect to database.";
    header("Location: /login.php");
    exit();
}

if (!mysqli_ping($conn)) {
    error_log("DB connection lost, reconnecting...");
    $conn = mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    if (!$conn) {
        $_SESSION['error_msg'] = "System error: Database connection failed.";
        header("Location: /login.php");
        exit();
    }
}

// FIX: force utf8mb4 so text in any language (Twi, Ewe, Ga, French, etc.)
// round-trips correctly instead of silently corrupting on save.
mysqli_set_charset($conn, 'utf8mb4');

$teacher_id = (int)$_SESSION['user_id'];

// Validate teacher
$teacher_sql = "SELECT id, name, department_id FROM users WHERE id = ? AND role = 'teacher'";
$teacher_stmt = $conn->prepare($teacher_sql);
if (!$teacher_stmt) {
    error_log("Teacher query prepare failed: " . $conn->error);
    $_SESSION['error_msg'] = "System error. Please try again.";
    header("Location: /login.php");
    exit();
}
$teacher_stmt->bind_param("i", $teacher_id);
$teacher_stmt->execute();
$teacher = $teacher_stmt->get_result()->fetch_assoc();
$teacher_stmt->close();

if (!$teacher) {
    error_log("Teacher not found: user_id=$teacher_id");
    $_SESSION['error_msg'] = "Teacher account not found.";
    header("Location: /login.php");
    exit();
}

$teacher_department_id = $teacher['department_id'];

// Class name mapping (shared with the Oxford lesson plan page)
$class_name_mapping = [
    'Esther Foundation' => 'Creche',
    'Sheba 1' => 'Nursery 1',
    'Sheba 2' => 'Nursery 2',
    'Yaasant 1' => 'KG 1',
    'Yaasant 2' => 'KG 2',
    'Cleopatra 1' => 'Basic 1',
    'Cleopatra 2' => 'Basic 2',
    'Nefertiti 1' => 'Basic 3',
    'Nefertiti 2' => 'Basic 4',
    'Malika 1' => 'Basic 5',
    'Malika 2' => 'Basic 6',
    'Makeda 1' => 'Basic 7',
    'Makeda 2' => 'Basic 8',
    'An Majesty' => 'Basic 9'
];

function getDisplayClassName($class_name) {
    global $class_name_mapping;
    return $class_name_mapping[$class_name] ?? $class_name;
}

// Day/time helpers for the schedule picker
function getDayAbbrev($day) {
    $map = [
        'Monday' => 'Mon', 'Tuesday' => 'Tue', 'Wednesday' => 'Wed',
        'Thursday' => 'Thu', 'Friday' => 'Fri', 'Saturday' => 'Sat', 'Sunday' => 'Sun'
    ];
    return $map[$day] ?? substr((string)$day, 0, 3);
}

function formatTime12($time) {
    if (empty($time)) return '';
    $ts = strtotime($time);
    return $ts ? date('g:i A', $ts) : $time;
}

// Builds a single dropdown label like: "Basic 5 - Mathematics (Mon, 8:00 AM - 9:00 AM)"
function formatScheduleLabel($display_class, $subject_name, $day, $start, $end) {
    $dayAbbr = getDayAbbrev($day);
    $timeRange = trim(formatTime12($start) . ' - ' . formatTime12($end), ' -');
    $label = $display_class . ' - ' . $subject_name;
    $meta = trim($dayAbbr . ($timeRange ? ', ' . $timeRange : ''));
    if ($meta) {
        $label .= ' (' . $meta . ')';
    }
    return $label;
}

// =====================================================================================
// SAFE-SAVE HELPERS (ported from weekly_plan_oxford.php)
// =====================================================================================
// AI-generated content (long presentations, multi-line keyword lists, etc.) can
// legitimately run much longer than a hand-typed lesson plan. Every field is safely
// truncated to a generous character budget — using mb_substr so multi-byte characters
// (Twi, Ewe, Ga, French accents, etc.) are never split mid-character — before it ever
// reaches the database, and any remaining DB error is translated into a message that
// names the field instead of leaking raw SQL text.
if (!defined('SAFE_LEN_SHORT')) define('SAFE_LEN_SHORT', 8000);
if (!defined('SAFE_LEN_LONG')) define('SAFE_LEN_LONG', 20000);

function dbSafeText($text, $maxChars = SAFE_LEN_SHORT) {
    $text = (string)$text;
    if (mb_strlen($text, 'UTF-8') <= $maxChars) {
        return $text;
    }
    return mb_substr($text, 0, $maxChars, 'UTF-8') . "\n…[truncated — please shorten this field]";
}

function friendlyDbError($rawMessage) {
    if (preg_match("/Data too long for column '([a-zA-Z0-9_]+)'/i", $rawMessage, $m)) {
        $field = str_replace('_', ' ', $m[1]);
        return "The \"" . ucfirst($field) . "\" field is too long to save. Please shorten it a little and try again. "
             . "(An administrator can also permanently fix this by widening that database column.)";
    }
    if (stripos($rawMessage, 'Incorrect string value') !== false) {
        return "Some of the text contains characters the database can't store yet. An administrator needs to "
             . "convert the lesson-plan table to the utf8mb4 character set to support all languages and symbols.";
    }
    return "Could not save the lesson plan due to a database error. Please try again, and contact an administrator if it persists.";
}

// =====================================================================================
// AI CONFIGURATION
// =====================================================================================
// TODO: move this to a non-web-accessible config file (e.g. /config/ai.php loaded like
// db.php) rather than hardcoding it here, and rotate this key.
$config = include $_SERVER['DOCUMENT_ROOT'] . '/config/gemini.php';
$api_key = $config['api_key'];
$gemini_models = [
    'gemini-flash-latest',   // official latest Flash alias (recommended, multimodal)
    'gemini-3.7-flash',
    'gemini-3.6-flash',
    'gemini-2.5-flash',
];

// =====================================================================================
// SOURCE / RESOURCE UPLOAD CONFIGURATION
// =====================================================================================
// NOTE: this deliberately reuses the SAME per-teacher folder and the SAME
// `learning_resources` table as weekly_plan_oxford.php. A teacher's uploaded
// textbook pages/worksheets are not tied to one lesson-plan format, so a
// document uploaded from the GES page is instantly available on the Oxford
// page too (and vice versa) — no re-uploading the same file twice.
$resource_upload_dir = $_SERVER['DOCUMENT_ROOT'] . '/uploads/resources/' . $teacher_id . '/';
if (!is_dir($resource_upload_dir)) {
    @mkdir($resource_upload_dir, 0755, true);
}
$resource_max_bytes = 35 * 1024 * 1024; // 35MB — comfortable headroom under Gemini's 50MB PDF inline cap
$effective_max_upload_bytes = min(
    $resource_max_bytes,
    iniSizeToBytes(ini_get('upload_max_filesize')) ?: $resource_max_bytes,
    iniSizeToBytes(ini_get('post_max_size')) ?: $resource_max_bytes
);
$resource_allowed_ext = [
    'pdf'  => 'application/pdf',
    'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'txt'  => 'text/plain',
    'jpg'  => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png'  => 'image/png',
    'webp' => 'image/webp',
];

/**
 * Extract plain text from a .docx file without any external library:
 * a .docx is a zip archive containing word/document.xml.
 */
function extractDocxText($filepath) {
    if (!class_exists('ZipArchive')) {
        return '';
    }
    $zip = new ZipArchive();
    if ($zip->open($filepath) !== true) {
        return '';
    }
    $xml = $zip->getFromName('word/document.xml');
    $zip->close();
    if ($xml === false) {
        return '';
    }
    $xml = str_replace(['</w:p>', '<w:tab/>'], ["\n", "\t"], $xml);
    $text = strip_tags($xml);
    return trim(preg_replace('/[ \t]+\n/', "\n", $text));
}

/**
 * Given a stored resource row, build the Gemini "part" that best represents it:
 * PDFs/images go in as inline_data (native reading); DOCX/TXT go in as extracted text.
 */
function resourceToGeminiPart($resource) {
    $mime = $resource['mime_type'] ?? '';
    $path = $resource['file_path'] ?? '';
    $label = "\n\n--- SOURCE DOCUMENT: " . ($resource['title'] ?? 'Untitled') . " ---\n";

    if (in_array($mime, ['application/pdf', 'image/jpeg', 'image/png', 'image/webp'], true)
        && $path && file_exists($path) && filesize($path) <= 35 * 1024 * 1024) {
        return [
            'label_text' => ['text' => $label],
            'file_part'  => ['inline_data' => ['mime_type' => $mime, 'data' => base64_encode(file_get_contents($path))]],
        ];
    }

    $text = trim((string)($resource['extracted_text'] ?? ''));
    if ($text === '') {
        return null;
    }
    if (mb_strlen($text) > 60000) {
        $text = mb_substr($text, 0, 60000) . "\n...[truncated]";
    }
    return ['label_text' => ['text' => $label . $text]];
}

/**
 * Builds the per-model generationConfig. gemini-3.x uses thinkingConfig.thinkingLevel
 * instead of temperature/topP/topK (deprecated + ignored on 3.x). Legacy 2.5-flash
 * still honors temperature/topP and disables thinking via thinkingBudget = 0.
 */
function buildGenerationConfigForModel($model, $responseSchema) {
    $config = ['maxOutputTokens' => 4096];
    if ($responseSchema) {
        $config['responseMimeType'] = 'application/json';
        $config['responseSchema'] = $responseSchema;
    }
    if (strpos($model, 'gemini-3') === 0) {
        $config['thinkingConfig'] = ['thinkingLevel' => 'low'];
    } else {
        $config['temperature'] = 0.4;
        $config['topP'] = 0.95;
        $config['thinkingConfig'] = ['thinkingBudget' => 0];
    }
    return $config;
}

/**
 * Calls Gemini with arbitrary "parts" (text and/or inline_data files) and,
 * optionally, a strict JSON responseSchema.
 */
function callGeminiWithParts($parts, $api_key, $models, $responseSchema = null) {
    $maxRetries = 2;
    $baseDelay  = 1000000; // 1 second

    foreach ($models as $model) {
        for ($attempt = 0; $attempt < $maxRetries; $attempt++) {
            $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent";
            $data = [
                'contents' => [[ 'parts' => $parts ]],
                'generationConfig' => buildGenerationConfigForModel($model, $responseSchema),
                'safetySettings' => [
                    ['category' => 'HARM_CATEGORY_HARASSMENT',  'threshold' => 'BLOCK_MEDIUM_AND_ABOVE'],
                    ['category' => 'HARM_CATEGORY_HATE_SPEECH', 'threshold' => 'BLOCK_MEDIUM_AND_ABOVE'],
                ],
            ];

            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_HTTPHEADER     => [
                    'Content-Type: application/json',
                    'X-goog-api-key: ' . $api_key,
                ],
                CURLOPT_POSTFIELDS     => json_encode($data),
                CURLOPT_TIMEOUT        => 25,
                CURLOPT_CONNECTTIMEOUT => 15,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS      => 3,
            ]);

            $response  = curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            error_log("Gemini(parts) attempt " . ($attempt + 1) . " model={$model} HTTP={$http_code}");

            if ($http_code === 200 && $response) {
                $result = json_decode($response, true);
                if (isset($result['candidates'][0]['content']['parts'][0]['text'])) {
                    return ['success' => true, 'text' => trim($result['candidates'][0]['content']['parts'][0]['text'])];
                }
                if (isset($result['candidates'][0]['finishReason'])) {
                    error_log("Gemini finishReason: " . $result['candidates'][0]['finishReason']);
                }
            }
            if ($response) error_log("Gemini error body: " . substr($response, 0, 2000));

            if ($http_code === 429) usleep($baseDelay * ($attempt + 1) * 2);
            elseif (in_array($http_code, [500, 502, 503])) usleep($baseDelay * 2);
            else usleep($baseDelay);
        }
    }

    return ['success' => false, 'error' => "AI service did not respond after multiple attempts. Please try again later."];
}

/**
 * Legacy single-string-prompt caller, used by the per-field "AI suggestion" buttons
 * (objectives / starter / resources / keywords / summary).
 */
function callGeminiAI($prompt, $api_key, $models) {
    return callGeminiWithParts([['text' => $prompt]], $api_key, $models);
}

// The strict JSON schema the full-plan generator must return.
// Unlike the Oxford format (Unit/Topic), GES lesson plans are organised around
// the Ghana Education Service Standards-Based Curriculum hierarchy:
// Strand -> Sub-Strand -> Content Standard -> Indicator.
function gesCompletePlanSchema() {
    return [
        'type' => 'OBJECT',
        'properties' => [
            'strand'           => ['type' => 'STRING'],
            'sub_strand'       => ['type' => 'STRING'],
            'content_standard' => ['type' => 'STRING'],
            'indicator'        => ['type' => 'STRING'],
            'objectives'       => ['type' => 'STRING'],
            'reference'        => ['type' => 'STRING'],
            'resources'        => ['type' => 'STRING'],
            'keywords'         => ['type' => 'STRING'],
            'starter'          => ['type' => 'STRING'],
            'presentation'     => ['type' => 'STRING'],
            'summary'          => ['type' => 'STRING'],
        ],
        'required' => ['strand','sub_strand','content_standard','indicator','objectives','reference','resources','keywords','starter','presentation','summary'],
    ];
}

/**
 * Builds the full instruction prompt for a complete, ready-to-use GES lesson plan,
 * meticulously grounded in any attached source documents.
 */
function buildCompleteGesPlanInstruction($context, $hasSources) {
    $week = (int)($context['week'] ?? 0);
    $class_name = $context['class_name'] ?? 'the class';
    $subject = $context['subject_name'] ?? '';
    $day = $context['day_of_week'] ?? '';
    $duration = $context['duration'] ?? '40 minutes';
    $language = trim($context['language'] ?? '');

    $strand = trim($context['strand'] ?? '');
    $sub_strand = trim($context['sub_strand'] ?? '');
    $content_standard = trim($context['content_standard'] ?? '');
    $indicator = trim($context['indicator'] ?? '');

    $curriculumLine = ($strand || $sub_strand || $content_standard || $indicator)
        ? "The teacher has already specified some curriculum alignment details — use these EXACTLY as given wherever present, and only fill in any of the four that are blank:\n"
            . "- Strand: " . ($strand ?: '[not specified — infer it]') . "\n"
            . "- Sub-Strand: " . ($sub_strand ?: '[not specified — infer it]') . "\n"
            . "- Content Standard: " . ($content_standard ?: '[not specified — infer it]') . "\n"
            . "- Indicator: " . ($indicator ?: '[not specified — infer it]')
        : "The teacher has NOT specified any Strand / Sub-Strand / Content Standard / Indicator — you must infer the single most appropriate set for Week {$week} from the source material provided (or, if no source material is provided, from the standard Ghana Education Service (GES) Standards-Based Curriculum scope and sequence for {$class_name} / {$subject}).";

    $sourceInstruction = $hasSources
        ? "You have been given one or more SOURCE DOCUMENTs below (textbook pages, syllabus excerpts, worksheets, notes, images, etc), which may be written in any language. Read every one of them carefully and in full before writing anything — recognise the language(s) they are written in and do not misread or mistranslate any of the content. Ground the Objectives, Presentation content, Keywords, and Resources firmly in what these documents actually say — do not invent facts, numbers, vocabulary, or examples that are not supported by the sources or well-established general curriculum knowledge. Where the sources give specific page numbers, exercises, diagrams, or terminology, reuse them precisely."
        : "No source documents were provided for this lesson. Base the plan on standard Ghana Education Service (GES) Standards-Based Curriculum expectations for this class/subject/week, and on materials realistically available in a well-resourced Ghanaian classroom.";

    $languageInstruction = $language !== ''
        ? "Write the ENTIRE lesson plan (every field) in {$language}, using correct spelling, grammar, and natural phrasing for that language. If any curriculum-specific English terms have no natural equivalent, you may keep the English term in parentheses."
        : "Write the lesson plan in the same language as the source documents provided, if any (matching their spelling and terminology accurately); otherwise write it in clear, natural English.";

    return <<<PROMPT
You are an expert, meticulous Ghana Education Service (GES) teacher-trainer. You are producing a complete, ready-to-teach weekly lesson plan for a class teacher, who will read it once and use it directly in the classroom — it must be accurate, specific, and usable with zero further editing required (though the teacher may still tweak it).

CONTEXT:
- Class: {$class_name}
- Subject: {$subject}
- Week: {$week} of the term
- Scheduled: {$day}, {$duration}

CURRICULUM ALIGNMENT:
{$curriculumLine}

{$sourceInstruction}

LANGUAGE: {$languageInstruction} Read carefully regardless of language, and do not mix languages within a field unless the teacher's source material itself mixes them.

REQUIREMENTS FOR EACH FIELD:
- strand: the broad GES curriculum area for this lesson.
- sub_strand: the narrower area within the strand.
- content_standard: the specific content standard being addressed, in the wording used by the GES curriculum where possible.
- indicator: the specific, measurable learning indicator for this lesson, in the wording used by the GES curriculum where possible.
- objectives: exactly 3-4 numbered SMART (Specific, Measurable, Achievable, Relevant, Time-bound) learning objectives, each starting "By the end of the lesson, learners will be able to...".
- reference: the specific textbook/page/source being used — cite the source document title(s) given to you if any, plus page/section if identifiable; otherwise a plausible GES-aligned reference.
- resources: a clear list of teacher resources, student resources, digital resources (if any), and locally available Ghanaian materials — one per line.
- keywords: 5-8 "Word: simple child-friendly definition" lines, using vocabulary actually present in the source material where possible.
- starter: a detailed, timed 5-minute starter activity with step-by-step teacher instructions, activating prior knowledge, usable with minimal resources.
- presentation: the full lesson body as one text block with clear sub-headings and minute allocations: Introduction (~5 min), Main Teaching Activity with explicit teacher instructions AND expected student responses (~20 min), Group/Pair Work with differentiation for higher- and lower-ability learners (~10 min), Plenary/Assessment questions with expected answers (~5 min).
- summary: 3-4 sentences covering key concepts learned, skills developed, a real-life application in a Ghanaian context, and how it connects to the next lesson.

Respond with ONLY the JSON object matching the required schema. No markdown fences, no commentary before or after.
PROMPT;
}

// =====================================================================================
// AJAX GET HANDLERS (read-only)
// =====================================================================================
if (isset($_GET['action'])) {
    header('Content-Type: application/json');
    header('Cache-Control: no-cache, must-revalidate');

    // Get schedules for a given term
    if ($_GET['action'] === 'get_schedules') {
        $term_id = (int)($_GET['term_id'] ?? 0);
        $response = ['schedules' => []];
        if ($term_id > 0) {
            $term_sql = "SELECT year FROM academic_calendar WHERE id = ?";
            $term_stmt = $conn->prepare($term_sql);
            $term_stmt->bind_param("i", $term_id);
            $term_stmt->execute();
            $term_result = $term_stmt->get_result();
            $academic_year = $term_result->fetch_assoc()['year'] ?? '';
            $term_stmt->close();
            if (!empty($academic_year)) {
                $sql = "SELECT cs.id, c.class_name, s.subject_name, cs.day_of_week, cs.start_time, cs.end_time
                        FROM class_schedules cs
                        JOIN classes c ON cs.class_id = c.id
                        LEFT JOIN subjects s ON cs.subject_id = s.id
                        WHERE cs.teacher_id = ? AND cs.academic_year = ?
                        ORDER BY c.class_name, s.subject_name,
                                 FIELD(cs.day_of_week, 'Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'),
                                 cs.start_time";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("is", $teacher_id, $academic_year);
                $stmt->execute();
                $result = $stmt->get_result();
                while ($row = $result->fetch_assoc()) {
                    $display_class = getDisplayClassName($row['class_name']);
                    $subject_name = $row['subject_name'] ?? 'N/A';
                    $response['schedules'][] = [
                        'id' => $row['id'],
                        'class_name' => $row['class_name'],
                        'display_class' => $display_class,
                        'subject_name' => $subject_name,
                        'day_of_week' => $row['day_of_week'],
                        'start_time' => $row['start_time'],
                        'end_time' => $row['end_time'],
                        'label' => formatScheduleLabel($display_class, $subject_name, $row['day_of_week'], $row['start_time'], $row['end_time'])
                    ];
                }
                $stmt->close();
            }
        }
        echo json_encode($response);
        exit();
    }

    // Get schedule details
    if ($_GET['action'] === 'get_schedule_details') {
        $schedule_id = (int)($_GET['schedule_id'] ?? 0);
        $response = ['schedule' => null, 'error' => ''];
        if ($schedule_id <= 0) {
            $response['error'] = 'Invalid schedule ID.';
        } else {
            $schedule_sql = "SELECT cs.id, cs.class_id, cs.subject_id, cs.academic_year, cs.department_id,
                            c.class_name, s.subject_name, d.name AS department_name,
                            CONCAT(cs.day_of_week, ' ', cs.start_time, ' - ', cs.end_time) AS time_slot,
                            cs.day_of_week, cs.start_time, cs.end_time
                            FROM class_schedules cs
                            JOIN classes c ON cs.class_id = c.id
                            LEFT JOIN subjects s ON cs.subject_id = s.id
                            LEFT JOIN departments d ON cs.department_id = d.id
                            WHERE cs.id = ? AND cs.teacher_id = ?";
            $schedule_stmt = $conn->prepare($schedule_sql);
            $schedule_stmt->bind_param("ii", $schedule_id, $teacher_id);
            if ($schedule_stmt->execute()) {
                $schedule = $schedule_stmt->get_result()->fetch_assoc();
                if ($schedule) {
                    $response['schedule'] = [
                        'id' => $schedule['id'],
                        'class_id' => $schedule['class_id'],
                        'class_name' => $schedule['class_name'],
                        'display_class_name' => getDisplayClassName($schedule['class_name']),
                        'subject_id' => $schedule['subject_id'] ?? 0,
                        'subject_name' => $schedule['subject_name'] ?? 'N/A',
                        'academic_year' => $schedule['academic_year'],
                        'department_id' => $schedule['department_id'] ?? $teacher_department_id,
                        'department_name' => $schedule['department_name'] ?? 'N/A',
                        'time_slot' => $schedule['time_slot'],
                        'day_of_week' => $schedule['day_of_week'],
                        'start_time' => $schedule['start_time'],
                        'end_time' => $schedule['end_time']
                    ];
                } else {
                    $response['error'] = 'No schedule found.';
                }
            } else {
                $response['error'] = 'Query failed.';
                error_log("Schedule query error: " . $schedule_stmt->error);
            }
            $schedule_stmt->close();
        }
        echo json_encode($response);
        exit();
    }

    // List this teacher's uploaded sources, optionally filtered by subject/class
    // (shared resource library — same table/folder as the Oxford lesson plan page).
    if ($_GET['action'] === 'list_resources') {
        $subject_id = (int)($_GET['subject_id'] ?? 0);
        $class_id = (int)($_GET['class_id'] ?? 0);
        $response = ['resources' => []];

        $sql = "SELECT id, title, resource_type, mime_type, subject_id, class_id, week, tags, created_at
                FROM learning_resources
                WHERE teacher_id = ? AND created_by = ?";
        $types = "ii";
        $params = [$teacher_id, $teacher_id];
        if ($subject_id > 0) { $sql .= " AND (subject_id = ? OR subject_id IS NULL)"; $types .= "i"; $params[] = $subject_id; }
        if ($class_id > 0)   { $sql .= " AND (class_id = ? OR class_id IS NULL)"; $types .= "i"; $params[] = $class_id; }
        $sql .= " ORDER BY created_at DESC LIMIT 100";

        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            error_log("list_resources prepare failed: " . $conn->error);
            echo json_encode(['resources' => [], 'error' => 'Could not load resources.']);
            exit();
        }
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $response['resources'][] = $row;
        }
        $stmt->close();
        echo json_encode($response);
        exit();
    }

    // View plan
    if ($_GET['action'] === 'view_plan') {
        $plan_id = (int)($_GET['plan_id'] ?? 0);
        $response = ['plan' => null, 'schedule' => null, 'error' => ''];
        if ($plan_id <= 0) {
            $response['error'] = 'Invalid plan ID.';
        } else {
            $plan_sql = "SELECT tn.*, c.class_name, s.subject_name, ac.year AS academic_year, ac.term_name,
                        ac.start_date AS term_start, ac.end_date AS term_end
                        FROM teacher_notes tn
                        JOIN classes c ON tn.class_id = c.id
                        LEFT JOIN subjects s ON tn.subject_id = s.id
                        JOIN academic_calendar ac ON tn.term_id = ac.id
                        WHERE tn.id = ? AND tn.teacher_id = ? AND tn.format = 'ges'";
            $plan_stmt = $conn->prepare($plan_sql);
            $plan_stmt->bind_param("ii", $plan_id, $teacher_id);
            $plan_stmt->execute();
            $plan = $plan_stmt->get_result()->fetch_assoc();
            $plan_stmt->close();
            if ($plan) {
                $schedule_sql = "SELECT cs.id, CONCAT(cs.day_of_week, ' ', cs.start_time, ' - ', cs.end_time) AS time_slot,
                                cs.day_of_week, cs.start_time, cs.end_time
                               FROM class_schedules cs
                               WHERE cs.class_id = ? AND cs.subject_id = ? AND cs.teacher_id = ?
                               LIMIT 1";
                $schedule_stmt = $conn->prepare($schedule_sql);
                $schedule_stmt->bind_param("iii", $plan['class_id'], $plan['subject_id'], $teacher_id);
                $schedule_stmt->execute();
                $schedule = $schedule_stmt->get_result()->fetch_assoc();
                $schedule_stmt->close();
                $response['plan'] = $plan;
                $response['plan']['display_class_name'] = getDisplayClassName($plan['class_name']);
                $response['schedule'] = $schedule;
            } else {
                $response['error'] = 'Lesson plan not found.';
            }
        }
        echo json_encode($response);
        exit();
    }

    // Get plan for edit
    if ($_GET['action'] === 'get_plan_for_edit') {
        $plan_id = (int)($_GET['plan_id'] ?? 0);
        $response = ['plan' => null, 'error' => ''];
        if ($plan_id <= 0) {
            $response['error'] = 'Invalid plan ID.';
        } else {
            $plan_sql = "SELECT * FROM teacher_notes WHERE id = ? AND teacher_id = ? AND format = 'ges'";
            $plan_stmt = $conn->prepare($plan_sql);
            $plan_stmt->bind_param("ii", $plan_id, $teacher_id);
            $plan_stmt->execute();
            $plan = $plan_stmt->get_result()->fetch_assoc();
            $plan_stmt->close();
            if ($plan) {
                $schedule_sql = "SELECT cs.id, CONCAT(cs.day_of_week, ' ', cs.start_time, ' - ', cs.end_time) AS time_slot
                               FROM class_schedules cs
                               WHERE cs.class_id = ? AND cs.subject_id = ? AND cs.teacher_id = ?
                               LIMIT 1";
                $schedule_stmt = $conn->prepare($schedule_sql);
                $schedule_stmt->bind_param("iii", $plan['class_id'], $plan['subject_id'], $teacher_id);
                $schedule_stmt->execute();
                $schedule = $schedule_stmt->get_result()->fetch_assoc();
                $schedule_stmt->close();
                $plan['schedule_info'] = $schedule;
                $class_sql = "SELECT class_name FROM classes WHERE id = ?";
                $class_stmt = $conn->prepare($class_sql);
                $class_stmt->bind_param("i", $plan['class_id']);
                $class_stmt->execute();
                $class_result = $class_stmt->get_result()->fetch_assoc();
                $plan['display_class_name'] = getDisplayClassName($class_result['class_name'] ?? 'N/A');
                $class_stmt->close();
                $response['plan'] = $plan;
            } else {
                $response['error'] = 'Lesson plan not found.';
            }
        }
        echo json_encode($response);
        exit();
    }

    // AI suggestion (single field — used by the per-field AI buttons)
    if ($_GET['action'] === 'ai_suggestion') {
        set_time_limit(120);
        $type = $_GET['type'] ?? '';
        $context = json_decode($_GET['context'] ?? '{}', true);
        $response = ['suggestion' => '', 'error' => '', 'notice' => ''];

        $indicatorLine = $context['indicator'] ?? '';
        $contentStandardLine = $context['content_standard'] ?? '';
        $focusLine = $indicatorLine
            ? "the Indicator '{$indicatorLine}'" . ($contentStandardLine ? " (Content Standard: '{$contentStandardLine}')" : '')
            : "the Content Standard '{$contentStandardLine}'";

        $prompts = [
            'objectives' => "As an experienced Ghana Education Service (GES) teacher, create 3-4 SMART (Specific, Measurable, Achievable, Relevant, Time-bound) learning objectives for a lesson covering {$focusLine} for {$context['class_name']}. Follow the GES Standards-Based Curriculum. Format as a numbered list with clear, concise objectives.",
            'starter' => "Create an engaging 5-minute starter activity for a GES curriculum lesson covering {$focusLine} for {$context['class_name']}. The activity should be practical, activate prior knowledge, use minimal resources, and be suitable for a typical Ghanaian classroom. Include step-by-step instructions.",
            'presentation' => "Create a detailed lesson presentation outline for a GES curriculum lesson covering {$focusLine} for {$context['class_name']}. Structure it as: 1) Introduction (5 mins), 2) Main Teaching Activity with clear teacher instructions and student activities (20 mins), 3) Group/Pair Work with differentiation for different ability levels (10 mins), 4) Plenary/Assessment (5 mins). Include specific questions to ask students and expected answers.",
            'resources' => "List specific teaching and learning resources needed for a GES curriculum lesson covering {$focusLine} for {$context['class_name']}. Include: 1) Teacher resources, 2) Student resources, 3) Digital resources (if applicable), 4) Locally available materials. Focus on resources commonly available in Ghanaian schools.",
            'keywords' => "List 5-8 key vocabulary words with simple, child-friendly definitions for a GES curriculum lesson covering {$focusLine} for {$context['class_name']}. Format as 'Word: Simple definition'.",
            'summary' => "Create a concise lesson summary (3-4 sentences) for a GES curriculum lesson covering {$focusLine} for {$context['class_name']}. Include: 1) Key concepts learned, 2) Skills developed, 3) Real-life applications in Ghana, 4) Next lesson connection.",
        ];

        $prompt = $prompts[$type] ?? '';
        $language = trim($context['language'] ?? '');
        if ($prompt !== '' && $language !== '') {
            $prompt .= " Write your entire response in {$language}, with correct spelling and natural phrasing for that language.";
        }
        if (empty($prompt)) {
            $response['error'] = 'Invalid suggestion type.';
        } elseif (empty($indicatorLine) && empty($contentStandardLine)) {
            $response['error'] = 'Please enter a Content Standard or Indicator first to get AI suggestions (or use "Generate Complete Plan" to have AI fill these in too).';
        } else {
            $ai_result = callGeminiAI($prompt, $gemini_api_key, $gemini_models);
            if ($ai_result['success']) {
                $response['suggestion'] = $ai_result['text'];
            } else {
                $response['error'] = $ai_result['error'] ?? 'AI generation failed. Please try again.';
                $response['suggestion'] = getFallbackSuggestion($type, $context);
                $response['notice'] = 'AI service temporarily unavailable. A template has been provided. You can edit it as needed.';
            }
        }
        echo json_encode($response);
        exit();
    }

    // Refresh the "My GES Lesson Plans" table without a full page reload.
    if ($_GET['action'] === 'list_plans') {
        $response = ['rows' => []];
        $plans_sql_local = "SELECT tn.id, tn.week, tn.indicator, tn.content_standard, c.class_name, s.subject_name,
                      ac.year AS academic_year, ac.term_name, tn.created_at, tn.ai_generated
                      FROM teacher_notes tn
                      JOIN classes c ON tn.class_id = c.id
                      LEFT JOIN subjects s ON tn.subject_id = s.id
                      JOIN academic_calendar ac ON tn.term_id = ac.id
                      WHERE tn.teacher_id = ? AND tn.format = 'ges'
                      ORDER BY tn.created_at DESC LIMIT 100";
        $plans_stmt_local = $conn->prepare($plans_sql_local);
        $plans_stmt_local->bind_param("i", $teacher_id);
        $plans_stmt_local->execute();
        $rows = $plans_stmt_local->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
        $plans_stmt_local->close();
        foreach ($rows as $row) {
            $response['rows'][] = [
                'id' => $row['id'],
                'display_class_name' => getDisplayClassName($row['class_name']),
                'subject_name' => $row['subject_name'],
                'indicator' => $row['indicator'],
                'content_standard' => $row['content_standard'],
                'week' => $row['week'],
                'term_name' => $row['term_name'],
                'academic_year' => $row['academic_year'],
                'created_at_display' => date('M d, Y', strtotime($row['created_at'])),
                'ai_generated' => (int)$row['ai_generated'],
            ];
        }
        echo json_encode($response);
        exit();
    }
}

// =====================================================================================
// AJAX POST HANDLERS (state-changing — resource upload/delete + smart full-plan generation)
// All require a valid CSRF token, same as the main form.
// =====================================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action'])) {
    header('Content-Type: application/json');
    header('Cache-Control: no-cache, must-revalidate');

    $posted_csrf = $_POST['csrf_token'] ?? '';
    $csrf_ok = false;
    if (preg_match('/^[a-f0-9]{64}$/', $posted_csrf)) {
        $chk = $conn->prepare("SELECT id FROM csrf_tokens WHERE user_id = ? AND token = ? AND expires_at > NOW()");
        $chk->bind_param("is", $teacher_id, $posted_csrf);
        $chk->execute();
        $csrf_ok = $chk->get_result()->num_rows > 0;
        $chk->close();
    }
    if (!$csrf_ok) {
        echo json_encode(['success' => false, 'error' => 'Your session has expired. Please refresh the page and try again.']);
        exit();
    }

    $ajax_action = $_POST['ajax_action'];

    // ---- Upload a source document / image, extract text, save as a reusable resource ----
    if ($ajax_action === 'upload_resource') {
        if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            echo json_encode(['success' => false, 'error' => 'No file received, or the upload failed.']);
            exit();
        }
        $file = $_FILES['file'];
        if ($file['size'] > $resource_max_bytes) {
            echo json_encode(['success' => false, 'error' => 'File is too large. Maximum size is ' . round($resource_max_bytes / 1024 / 1024, 1) . 'MB.']);
            exit();
        }
        if ($effective_max_upload_bytes > 0 && $file['size'] > $effective_max_upload_bytes) {
            echo json_encode(['success' => false, 'error' => 'File exceeds the server\'s current upload limit (' . round($effective_max_upload_bytes / 1024 / 1024, 1) . 'MB). Please ask an administrator to raise it, or try a smaller file.']);
            exit();
        }
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!isset($resource_allowed_ext[$ext])) {
            echo json_encode(['success' => false, 'error' => 'Unsupported file type. Allowed: PDF, DOCX, TXT, JPG, PNG, WEBP.']);
            exit();
        }
        $mime = $resource_allowed_ext[$ext];

        $safe_name = bin2hex(random_bytes(8)) . '_' . preg_replace('/[^A-Za-z0-9._-]/', '_', $file['name']);
        $dest_path = $resource_upload_dir . $safe_name;
        if (!move_uploaded_file($file['tmp_name'], $dest_path)) {
            echo json_encode(['success' => false, 'error' => 'Could not save the uploaded file.']);
            exit();
        }

        $extracted_text = null;
        if ($ext === 'docx') {
            $extracted_text = extractDocxText($dest_path);
        } elseif ($ext === 'txt') {
            $extracted_text = file_get_contents($dest_path);
        }

        $title = trim($_POST['title'] ?? '') ?: pathinfo($file['name'], PATHINFO_FILENAME);
        $subject_id = filter_input(INPUT_POST, 'subject_id', FILTER_VALIDATE_INT) ?: null;
        $class_id = filter_input(INPUT_POST, 'class_id', FILTER_VALIDATE_INT) ?: null;
        $term_id = filter_input(INPUT_POST, 'term_id', FILTER_VALIDATE_INT) ?: null;
        $week = filter_input(INPUT_POST, 'week', FILTER_VALIDATE_INT) ?: null;
        $tags = trim(strip_tags($_POST['tags'] ?? ''));
        $academic_year = $_POST['academic_year'] ?? '';

        $insert_sql = "INSERT INTO learning_resources
            (title, description, resource_type, mime_type, file_path, extracted_text, file_size, subject_id, class_id, teacher_id, term_id, week, academic_year, access_level, tags, is_approved, created_by, created_at)
            VALUES (?, ?, 'document', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'private', ?, 1, ?, NOW())";
        $stmt = $conn->prepare($insert_sql);
        if (!$stmt) {
            error_log("Resource insert prepare failed: " . $conn->error);
            echo json_encode(['success' => false, 'error' => 'System error: could not prepare resource save. Please contact an administrator.']);
            @unlink($dest_path);
            exit();
        }
        // Bind order mirrors columns:
        // s title, s description, s mime_type, s file_path, s extracted_text, i file_size,
        // i subject_id, i class_id, i teacher_id, i term_id, i week,
        // s academic_year, s tags, i created_by  ==  "sssssiiiiiissi" (14 vars)
        $stmt->bind_param(
            "sssssiiiiiissi",
            $title, $title, $mime, $dest_path, $extracted_text, $file['size'],
            $subject_id, $class_id, $teacher_id, $term_id, $week,
            $academic_year, $tags, $teacher_id
        );
        if (!$stmt->execute()) {
            error_log("Resource insert failed: " . $stmt->error);
            echo json_encode(['success' => false, 'error' => 'Could not save the resource record.']);
            $stmt->close();
            @unlink($dest_path);
            exit();
        }
        $resource_id = $stmt->insert_id;
        $stmt->close();

        echo json_encode([
            'success' => true,
            'resource' => [
                'id' => $resource_id,
                'title' => $title,
                'mime_type' => $mime,
                'has_text' => !empty($extracted_text),
            ],
        ]);
        exit();
    }

    // ---- Delete a resource the teacher owns ----
    if ($ajax_action === 'delete_resource') {
        $resource_id = filter_input(INPUT_POST, 'resource_id', FILTER_VALIDATE_INT);
        if (!$resource_id) {
            echo json_encode(['success' => false, 'error' => 'Invalid resource ID.']);
            exit();
        }
        $sel = $conn->prepare("SELECT file_path FROM learning_resources WHERE id = ? AND teacher_id = ?");
        $sel->bind_param("ii", $resource_id, $teacher_id);
        $sel->execute();
        $row = $sel->get_result()->fetch_assoc();
        $sel->close();
        if (!$row) {
            echo json_encode(['success' => false, 'error' => 'Resource not found.']);
            exit();
        }
        $del = $conn->prepare("DELETE FROM learning_resources WHERE id = ? AND teacher_id = ?");
        $del->bind_param("ii", $resource_id, $teacher_id);
        $del->execute();
        $del->close();
        if (!empty($row['file_path']) && file_exists($row['file_path'])) {
            @unlink($row['file_path']);
        }
        echo json_encode(['success' => true]);
        exit();
    }

    // ---- Smart complete-plan generation, grounded in selected source resources ----
    if ($ajax_action === 'generate_plan') {
        set_time_limit(150);

        $week = filter_input(INPUT_POST, 'week', FILTER_VALIDATE_INT);
        $class_name = trim($_POST['class_name'] ?? '');
        $subject_name = trim($_POST['subject_name'] ?? '');
        $day_of_week = trim($_POST['day_of_week'] ?? '');
        $duration = trim($_POST['duration'] ?? '40 minutes');
        $strand = trim(strip_tags($_POST['strand'] ?? ''));
        $sub_strand = trim(strip_tags($_POST['sub_strand'] ?? ''));
        $content_standard = trim(strip_tags($_POST['content_standard'] ?? ''));
        $indicator = trim(strip_tags($_POST['indicator'] ?? ''));
        $language = trim(strip_tags($_POST['language'] ?? ''));
        $resource_ids = json_decode($_POST['resource_ids'] ?? '[]', true) ?: [];
        $resource_ids = array_values(array_filter(array_map('intval', $resource_ids)));

        if (!$week) {
            echo json_encode(['success' => false, 'error' => 'Please select a week first.']);
            exit();
        }
        if (!$class_name) {
            echo json_encode(['success' => false, 'error' => 'Please select a class/schedule first.']);
            exit();
        }

        // Fetch the selected source resources belonging to this teacher.
        $resources = [];
        if (!empty($resource_ids)) {
            $placeholders = implode(',', array_fill(0, count($resource_ids), '?'));
            $types = str_repeat('i', count($resource_ids)) . 'i';
            $sql = "SELECT id, title, file_path, extracted_text, mime_type FROM learning_resources
                    WHERE teacher_id = ? AND id IN ($placeholders)";
            $stmt = $conn->prepare($sql);
            if (!$stmt) {
                error_log("generate_plan resource fetch prepare failed: " . $conn->error);
                echo json_encode(['success' => false, 'error' => 'System error: could not load selected sources.']);
                exit();
            }
            $params = array_merge([$teacher_id], $resource_ids);
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
                $resources[] = $row;
            }
            $stmt->close();
        }

        $context = [
            'week' => $week,
            'class_name' => $class_name,
            'subject_name' => $subject_name,
            'day_of_week' => $day_of_week,
            'duration' => $duration,
            'strand' => $strand,
            'sub_strand' => $sub_strand,
            'content_standard' => $content_standard,
            'indicator' => $indicator,
            'language' => $language,
        ];

        $parts = [['text' => buildCompleteGesPlanInstruction($context, !empty($resources))]];
        foreach ($resources as $resource) {
            $piece = resourceToGeminiPart($resource);
            if ($piece === null) continue;
            $parts[] = $piece['label_text'];
            if (isset($piece['file_part'])) $parts[] = $piece['file_part'];
        }

        $ai_result = callGeminiWithParts($parts, $gemini_api_key, $gemini_models, gesCompletePlanSchema());

        if (!$ai_result['success']) {
            echo json_encode(['success' => false, 'error' => $ai_result['error']]);
            exit();
        }

        $plan = json_decode($ai_result['text'], true);
        if (!is_array($plan) || empty($plan['objectives'])) {
            error_log("Gemini returned unparsable JSON: " . substr($ai_result['text'], 0, 1000));
            echo json_encode(['success' => false, 'error' => 'AI response could not be understood. Please try again.']);
            exit();
        }

        echo json_encode([
            'success' => true,
            'plan' => $plan,
            'used_resource_ids' => $resource_ids,
        ]);
        exit();
    }

    // ---- One-click AJAX save (create or update), used by the Save/Update buttons ----
    if ($ajax_action === 'save_plan') {
        $action = $_POST['action'] ?? 'submit';
        $plan_id = filter_input(INPUT_POST, 'plan_id', FILTER_VALIDATE_INT) ?: 0;
        $week = filter_input(INPUT_POST, 'week', FILTER_VALIDATE_INT);
        $class_id = filter_input(INPUT_POST, 'class_id', FILTER_VALIDATE_INT);
        $term_id = filter_input(INPUT_POST, 'term_id', FILTER_VALIDATE_INT);
        $subject_id = filter_input(INPUT_POST, 'subject_id', FILTER_VALIDATE_INT) ?: null;

        // Every text field is safely truncated (multi-byte-aware) before it ever
        // reaches the database — this is what prevents "Data too long" crashes
        // regardless of how verbose the AI-generated content is.
        $strand = dbSafeText(trim(strip_tags($_POST['strand'] ?? '')), SAFE_LEN_SHORT);
        $sub_strand = dbSafeText(trim(strip_tags($_POST['sub_strand'] ?? '')), SAFE_LEN_SHORT);
        $content_standard = dbSafeText(trim(strip_tags($_POST['content_standard'] ?? '')), SAFE_LEN_SHORT);
        $indicator = dbSafeText(trim(strip_tags($_POST['indicator'] ?? '')), SAFE_LEN_SHORT);
        $objectives = dbSafeText(trim(strip_tags($_POST['objectives'] ?? '')), SAFE_LEN_SHORT);
        $reference = dbSafeText(trim(strip_tags($_POST['reference'] ?? '')), SAFE_LEN_SHORT);
        $resources = dbSafeText(trim(strip_tags($_POST['resources'] ?? '')), SAFE_LEN_SHORT);
        $keywords = dbSafeText(trim(strip_tags($_POST['keywords'] ?? '')), SAFE_LEN_SHORT);
        $starter = dbSafeText(trim(strip_tags($_POST['starter'] ?? '')), SAFE_LEN_SHORT);
        $presentation = dbSafeText(trim(strip_tags($_POST['presentation'] ?? '', '<p><br><strong><em><ul><ol><li>')), SAFE_LEN_LONG);
        $summary = dbSafeText(trim(strip_tags($_POST['summary'] ?? '')), SAFE_LEN_SHORT);
        $source_resource_ids = trim(strip_tags($_POST['source_resource_ids'] ?? ''));
        $ai_generated = !empty($_POST['ai_generated']) ? 1 : 0;

        $save_errors = [];
        if (!$week || $week < 1 || $week > 16) $save_errors[] = "Invalid week number (1-16 required).";
        if (!$class_id || $class_id <= 0) $save_errors[] = "Please select a schedule to set the class.";
        if (!$term_id || $term_id <= 0) $save_errors[] = "Please select a term.";
        if (empty($objectives)) $save_errors[] = "Objectives are required.";
        if (empty($reference)) $save_errors[] = "Reference is required.";
        if (empty($resources)) $save_errors[] = "Resources are required.";
        if (empty($keywords)) $save_errors[] = "Keywords are required.";
        if (empty($starter)) $save_errors[] = "Starter activity is required.";
        if (empty($presentation)) $save_errors[] = "Main presentation is required.";
        if (empty($summary)) $save_errors[] = "Summary is required.";

        if (!empty($save_errors)) {
            echo json_encode(['success' => false, 'errors' => $save_errors]);
            exit();
        }

        $conn->begin_transaction();
        try {
            if ($action === 'update' && $plan_id > 0) {
                // Verify the schedule (and therefore class/subject) actually belongs to this teacher.
                $sched_check_sql = "SELECT id FROM class_schedules WHERE class_id = ? AND teacher_id = ? LIMIT 1";
                $sched_check_stmt = $conn->prepare($sched_check_sql);
                $sched_check_stmt->bind_param("ii", $class_id, $teacher_id);
                $sched_check_stmt->execute();
                if ($sched_check_stmt->get_result()->num_rows === 0) {
                    $sched_check_stmt->close();
                    throw new Exception("Selected class is not assigned to you.");
                }
                $sched_check_stmt->close();

                $update_sql = "UPDATE teacher_notes SET
                    week = ?, class_id = ?, term_id = ?, subject_id = ?,
                    strand = ?, sub_strand = ?, content_standard = ?, indicator = ?,
                    objectives = ?, reference = ?, resources = ?, keywords = ?,
                    starter = ?, presentation = ?, summary = ?,
                    source_resource_ids = ?, ai_generated = ?, updated_at = NOW()
                    WHERE id = ? AND teacher_id = ? AND format = 'ges'";
                $update_stmt = $conn->prepare($update_sql);
                $update_stmt->bind_param(
                    "iiiissssssssssssii",
                    $week, $class_id, $term_id, $subject_id,
                    $strand, $sub_strand, $content_standard, $indicator,
                    $objectives, $reference, $resources, $keywords,
                    $starter, $presentation, $summary,
                    $source_resource_ids, $ai_generated,
                    $plan_id, $teacher_id
                );
                if (!$update_stmt->execute()) {
                    throw new Exception($update_stmt->error);
                }
                $update_stmt->close();
                $conn->commit();
                echo json_encode(['success' => true, 'plan_id' => $plan_id, 'message' => 'GES lesson plan updated successfully!']);
            } else {
                $insert_sql = "INSERT INTO teacher_notes (
                    teacher_id, week, class_id, term_id, subject_id,
                    strand, sub_strand, content_standard, indicator,
                    objectives, reference, resources, keywords, starter, presentation,
                    summary, format, source_resource_ids, ai_generated, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'ges', ?, ?, NOW())";
                $insert_stmt = $conn->prepare($insert_sql);
                $insert_stmt->bind_param(
                    "iiiiissssssssssssi",
                    $teacher_id, $week, $class_id, $term_id, $subject_id,
                    $strand, $sub_strand, $content_standard, $indicator,
                    $objectives, $reference, $resources, $keywords, $starter, $presentation,
                    $summary, $source_resource_ids, $ai_generated
                );
                if (!$insert_stmt->execute()) {
                    throw new Exception($insert_stmt->error);
                }
                $new_id = $insert_stmt->insert_id;
                $insert_stmt->close();
                $conn->commit();
                echo json_encode(['success' => true, 'plan_id' => $new_id, 'message' => 'GES lesson plan created successfully!']);
            }
        } catch (Exception $e) {
            $conn->rollback();
            error_log("save_plan exception: " . $e->getMessage());
            echo json_encode(['success' => false, 'error' => friendlyDbError($e->getMessage())]);
        }
        exit();
    }

    // ---- AJAX delete ----
    if ($ajax_action === 'delete_plan') {
        $plan_id = filter_input(INPUT_POST, 'plan_id', FILTER_VALIDATE_INT);
        if (!$plan_id || $plan_id <= 0) {
            echo json_encode(['success' => false, 'error' => 'Invalid lesson plan ID.']);
            exit();
        }
        $conn->begin_transaction();
        try {
            $check_stmt = $conn->prepare("SELECT id FROM teacher_notes WHERE id = ? AND teacher_id = ? AND format = 'ges'");
            $check_stmt->bind_param("ii", $plan_id, $teacher_id);
            $check_stmt->execute();
            if ($check_stmt->get_result()->num_rows === 0) {
                $check_stmt->close();
                $conn->rollback();
                echo json_encode(['success' => false, 'error' => 'Lesson plan not found or unauthorized.']);
                exit();
            }
            $check_stmt->close();
            $delete_stmt = $conn->prepare("DELETE FROM teacher_notes WHERE id = ? AND teacher_id = ? AND format = 'ges'");
            $delete_stmt->bind_param("ii", $plan_id, $teacher_id);
            if (!$delete_stmt->execute()) {
                throw new Exception($delete_stmt->error);
            }
            $delete_stmt->close();
            $conn->commit();
            echo json_encode(['success' => true, 'message' => 'GES lesson plan deleted successfully!']);
        } catch (Exception $e) {
            $conn->rollback();
            error_log("delete_plan exception: " . $e->getMessage());
            echo json_encode(['success' => false, 'error' => 'Error deleting lesson plan. Please try again.']);
        }
        exit();
    }

    echo json_encode(['success' => false, 'error' => 'Unknown action.']);
    exit();
}

/**
 * Get fallback suggestion when AI is unavailable
 */
function getFallbackSuggestion($type, $context) {
    $focus = htmlspecialchars($context['indicator'] ?? $context['content_standard'] ?? 'your topic');
    $fallbacks = [
        'objectives' => "1. Understand the key concepts of {$focus}\n2. Apply knowledge of {$focus} to solve problems\n3. Analyze and evaluate information related to {$focus}\n4. Create/design solutions using understanding of {$focus}",
        'starter' => "1. Begin with a quick review of prior knowledge (2 mins)\n2. Show a relevant image/object and ask students: 'What do you notice?' (2 mins)\n3. Students share their observations with a partner (1 min)\n4. Briefly introduce today's topic and learning objectives",
        'presentation' => "Introduction (5 mins):\n- Review prior learning\n- State learning objectives\n- Engage students with a thought-provoking question\n\nMain Activity (20 mins):\n- Teacher demonstration/modeling\n- Guided practice with whole class\n- Independent/small group work with differentiated tasks\n- Circulate and provide support\n\nGroup Work (10 mins):\n- Mixed-ability groups work on application tasks\n- Higher ability: Extension questions\n- Lower ability: Scaffolded support\n\nPlenary (5 mins):\n- Groups share findings\n- Address misconceptions\n- Summarize key points",
        'resources' => "Teacher Resources:\n- Whiteboard/chalkboard and markers/chalk\n- Teacher's guide/reference book\n- Visual aids (charts, diagrams)\n\nStudent Resources:\n- Exercise books/pencils\n- Worksheets (if prepared)\n- Manipulatives or hands-on materials\n\nLocal Materials:\n- Items from the local environment relevant to the topic\n- Recycled materials for creative activities",
        'keywords' => "Key concepts for {$focus}:\n\n1. Main concept: The primary idea being taught\n2. Related term: Connected vocabulary\n3. Process word: Action or method term\n4. Description word: Adjective describing features\n5. Application term: How the concept is used\n6. Evaluation word: Term for assessing understanding",
        'summary' => "In today's lesson on {$focus}, students explored key concepts and developed their understanding through hands-on activities. They practiced applying their knowledge and collaborated with peers to solve problems. The skills learned today connect to real-life situations in Ghana and will be built upon in future lessons.",
    ];
    return $fallbacks[$type] ?? "Please enter content manually for this section.";
}

// Fetch all available terms
$terms_sql = "SELECT id, term_name, year, start_date, end_date
              FROM academic_calendar
              ORDER BY year DESC, start_date ASC";
$terms_stmt = $conn->prepare($terms_sql);
$terms_stmt->execute();
$available_terms = $terms_stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
$terms_stmt->close();

// If no terms found, show error
if (empty($available_terms)) {
    error_log("No academic terms found in database");
    $_SESSION['error_msg'] = "No academic terms configured. Please contact administrator.";
    header("Location: /login.php");
    exit();
}

// Determine default term: current or next upcoming
$default_term_id = 0;
$default_academic_year = '';

$current_term_sql = "SELECT id, year FROM academic_calendar WHERE CURDATE() BETWEEN start_date AND end_date ORDER BY start_date DESC LIMIT 1";
$current_term_stmt = $conn->prepare($current_term_sql);
$current_term_stmt->execute();
$current_term_result = $current_term_stmt->get_result();
if ($current_term_result->num_rows > 0) {
    $row = $current_term_result->fetch_assoc();
    $default_term_id = $row['id'];
    $default_academic_year = $row['year'];
}
$current_term_stmt->close();

if (!$default_term_id) {
    $next_term_sql = "SELECT id, year FROM academic_calendar WHERE start_date > CURDATE() ORDER BY start_date ASC LIMIT 1";
    $next_term_stmt = $conn->prepare($next_term_sql);
    $next_term_stmt->execute();
    $next_term_result = $next_term_stmt->get_result();
    if ($next_term_result->num_rows > 0) {
        $row = $next_term_result->fetch_assoc();
        $default_term_id = $row['id'];
        $default_academic_year = $row['year'];
    }
    $next_term_stmt->close();
}

if (!$default_term_id && !empty($available_terms)) {
    $latest = $available_terms[0];
    $default_term_id = $latest['id'];
    $default_academic_year = $latest['year'];
}

// Fetch schedules for the default term's academic year
$schedules = [];
if ($default_academic_year) {
    $sched_sql = "SELECT cs.id, c.class_name, s.subject_name, cs.day_of_week, cs.start_time, cs.end_time
                  FROM class_schedules cs
                  JOIN classes c ON cs.class_id = c.id
                  LEFT JOIN subjects s ON cs.subject_id = s.id
                  WHERE cs.teacher_id = ? AND cs.academic_year = ?
                  ORDER BY c.class_name, s.subject_name,
                           FIELD(cs.day_of_week, 'Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'),
                           cs.start_time";
    $sched_stmt = $conn->prepare($sched_sql);
    $sched_stmt->bind_param("is", $teacher_id, $default_academic_year);
    $sched_stmt->execute();
    $sched_result = $sched_stmt->get_result();
    while ($row = $sched_result->fetch_assoc()) {
        $display_class = getDisplayClassName($row['class_name']);
        $subject_name = $row['subject_name'] ?? 'N/A';
        $schedules[] = [
            'id' => $row['id'],
            'class_name' => $row['class_name'],
            'display_class' => $display_class,
            'subject_name' => $subject_name,
            'day_of_week' => $row['day_of_week'],
            'start_time' => $row['start_time'],
            'end_time' => $row['end_time'],
            'label' => formatScheduleLabel($display_class, $subject_name, $row['day_of_week'], $row['start_time'], $row['end_time'])
        ];
    }
    $sched_stmt->close();
}

// Fetch existing lesson plans
$plans = [];
if ($default_academic_year) {
    $plans_sql = "SELECT tn.id, tn.week, tn.indicator, tn.content_standard, c.class_name, s.subject_name,
                  ac.year AS academic_year, ac.term_name, tn.created_at, tn.ai_generated
                  FROM teacher_notes tn
                  JOIN classes c ON tn.class_id = c.id
                  LEFT JOIN subjects s ON tn.subject_id = s.id
                  JOIN academic_calendar ac ON tn.term_id = ac.id
                  WHERE tn.teacher_id = ? AND tn.format = 'ges'
                  ORDER BY tn.created_at DESC LIMIT 100";
    $plans_stmt = $conn->prepare($plans_sql);
    $plans_stmt->bind_param("i", $teacher_id);
    $plans_stmt->execute();
    $plans = $plans_stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
    $plans_stmt->close();
}

// CSRF token
$csrf_token = bin2hex(random_bytes(32));
$csrf_sql = "INSERT INTO csrf_tokens (user_id, token, created_at, expires_at) VALUES (?, ?, NOW(), DATE_ADD(NOW(), INTERVAL 24 HOUR))";
$csrf_stmt = $conn->prepare($csrf_sql);
$csrf_stmt->bind_param("is", $teacher_id, $csrf_token);
$csrf_stmt->execute();
$csrf_stmt->close();
$conn->query("DELETE FROM csrf_tokens WHERE expires_at < NOW()");

// =====================================================================================
// Legacy non-AJAX form fallback (used only if JavaScript is unavailable in the browser).
// The primary save/update/delete path is the AJAX handlers above.
// =====================================================================================
$errors = [];
$success_msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['ajax_action'])) {
    $posted_csrf = filter_input(INPUT_POST, 'csrf_token', FILTER_UNSAFE_RAW) ?? '';
    if (!preg_match('/^[a-f0-9]{64}$/', $posted_csrf)) {
        $errors[] = "Invalid CSRF token. Please refresh the page and try again.";
    } else {
        $csrf_check_sql = "SELECT id FROM csrf_tokens WHERE user_id = ? AND token = ? AND expires_at > NOW()";
        $csrf_check_stmt = $conn->prepare($csrf_check_sql);
        $csrf_check_stmt->bind_param("is", $teacher_id, $posted_csrf);
        $csrf_check_stmt->execute();
        if ($csrf_check_stmt->get_result()->num_rows === 0) {
            $errors[] = "Your session has expired. Please refresh the page and try again.";
        }
        $csrf_check_stmt->close();
    }

    if (empty($errors)) {
        $action = $_POST['action'] ?? '';
        if ($action === 'submit' || $action === 'update') {
            $plan_id = filter_input(INPUT_POST, 'plan_id', FILTER_VALIDATE_INT) ?? 0;
            $week = filter_input(INPUT_POST, 'week', FILTER_VALIDATE_INT);
            $class_id = filter_input(INPUT_POST, 'class_id', FILTER_VALIDATE_INT);
            $term_id = filter_input(INPUT_POST, 'term_id', FILTER_VALIDATE_INT);
            $subject_id = filter_input(INPUT_POST, 'subject_id', FILTER_VALIDATE_INT) ?: null;

            $strand = dbSafeText(trim(strip_tags($_POST['strand'] ?? '')), SAFE_LEN_SHORT);
            $sub_strand = dbSafeText(trim(strip_tags($_POST['sub_strand'] ?? '')), SAFE_LEN_SHORT);
            $content_standard = dbSafeText(trim(strip_tags($_POST['content_standard'] ?? '')), SAFE_LEN_SHORT);
            $indicator = dbSafeText(trim(strip_tags($_POST['indicator'] ?? '')), SAFE_LEN_SHORT);
            $objectives = dbSafeText(trim(strip_tags($_POST['objectives'] ?? '')), SAFE_LEN_SHORT);
            $reference = dbSafeText(trim(strip_tags($_POST['reference'] ?? '')), SAFE_LEN_SHORT);
            $resources = dbSafeText(trim(strip_tags($_POST['resources'] ?? '')), SAFE_LEN_SHORT);
            $keywords = dbSafeText(trim(strip_tags($_POST['keywords'] ?? '')), SAFE_LEN_SHORT);
            $starter = dbSafeText(trim(strip_tags($_POST['starter'] ?? '')), SAFE_LEN_SHORT);
            $presentation = dbSafeText(trim(strip_tags($_POST['presentation'] ?? '', '<p><br><strong><em><ul><ol><li>')), SAFE_LEN_LONG);
            $summary = dbSafeText(trim(strip_tags($_POST['summary'] ?? '')), SAFE_LEN_SHORT);
            $source_resource_ids = trim(strip_tags($_POST['source_resource_ids'] ?? ''));
            $ai_generated = !empty($_POST['ai_generated']) ? 1 : 0;

            if (!$week || $week < 1 || $week > 16) $errors[] = "Invalid week number (1-16 required).";
            if (!$class_id || $class_id <= 0) $errors[] = "Please select a schedule to set the class.";
            if (!$term_id || $term_id <= 0) $errors[] = "Please select a term.";
            if (empty($objectives)) $errors[] = "Objectives are required.";
            if (empty($reference)) $errors[] = "Reference is required.";
            if (empty($resources)) $errors[] = "Resources are required.";
            if (empty($keywords)) $errors[] = "Keywords are required.";
            if (empty($starter)) $errors[] = "Starter is required.";
            if (empty($presentation)) $errors[] = "Presentation is required.";
            if (empty($summary)) $errors[] = "Summary is required.";

            if (empty($errors)) {
                $conn->begin_transaction();
                try {
                    if ($action === 'update' && $plan_id > 0) {
                        $update_sql = "UPDATE teacher_notes SET
                            week = ?, class_id = ?, term_id = ?, subject_id = ?,
                            strand = ?, sub_strand = ?, content_standard = ?, indicator = ?,
                            objectives = ?, reference = ?, resources = ?, keywords = ?,
                            starter = ?, presentation = ?, summary = ?,
                            source_resource_ids = ?, ai_generated = ?, updated_at = NOW()
                            WHERE id = ? AND teacher_id = ? AND format = 'ges'";
                        $update_stmt = $conn->prepare($update_sql);
                        $update_stmt->bind_param(
                            "iiiissssssssssssii",
                            $week, $class_id, $term_id, $subject_id,
                            $strand, $sub_strand, $content_standard, $indicator,
                            $objectives, $reference, $resources, $keywords,
                            $starter, $presentation, $summary,
                            $source_resource_ids, $ai_generated,
                            $plan_id, $teacher_id
                        );
                        if ($update_stmt->execute()) {
                            $success_msg = "GES lesson plan updated successfully!";
                        } else {
                            throw new Exception($update_stmt->error);
                        }
                        $update_stmt->close();
                    } else {
                        $insert_sql = "INSERT INTO teacher_notes (
                            teacher_id, week, class_id, term_id, subject_id,
                            strand, sub_strand, content_standard, indicator,
                            objectives, reference, resources, keywords, starter, presentation,
                            summary, format, source_resource_ids, ai_generated, created_at
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'ges', ?, ?, NOW())";
                        $insert_stmt = $conn->prepare($insert_sql);
                        $insert_stmt->bind_param(
                            "iiiiissssssssssssi",
                            $teacher_id, $week, $class_id, $term_id, $subject_id,
                            $strand, $sub_strand, $content_standard, $indicator,
                            $objectives, $reference, $resources, $keywords, $starter, $presentation,
                            $summary, $source_resource_ids, $ai_generated
                        );
                        if ($insert_stmt->execute()) {
                            $success_msg = "GES lesson plan created successfully!";
                        } else {
                            throw new Exception($insert_stmt->error);
                        }
                        $insert_stmt->close();
                    }
                    $plans_stmt = $conn->prepare($plans_sql);
                    $plans_stmt->bind_param("i", $teacher_id);
                    $plans_stmt->execute();
                    $plans = $plans_stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
                    $plans_stmt->close();
                    $conn->commit();
                } catch (Exception $e) {
                    $conn->rollback();
                    $errors[] = friendlyDbError($e->getMessage());
                    error_log("Save exception: " . $e->getMessage());
                }
            }
        } elseif ($action === 'delete') {
            $plan_id = filter_input(INPUT_POST, 'plan_id', FILTER_VALIDATE_INT);
            if (!$plan_id || $plan_id <= 0) {
                $errors[] = "Invalid lesson plan ID.";
            } else {
                $conn->begin_transaction();
                try {
                    $check_sql = "SELECT id FROM teacher_notes WHERE id = ? AND teacher_id = ? AND format = 'ges'";
                    $check_stmt = $conn->prepare($check_sql);
                    $check_stmt->bind_param("ii", $plan_id, $teacher_id);
                    $check_stmt->execute();
                    if ($check_stmt->get_result()->num_rows === 0) {
                        $errors[] = "Lesson plan not found or unauthorized.";
                        $conn->rollback();
                    } else {
                        $check_stmt->close();
                        $delete_sql = "DELETE FROM teacher_notes WHERE id = ? AND teacher_id = ? AND format = 'ges'";
                        $delete_stmt = $conn->prepare($delete_sql);
                        $delete_stmt->bind_param("ii", $plan_id, $teacher_id);
                        if ($delete_stmt->execute()) {
                            $success_msg = "GES lesson plan deleted successfully!";
                            if (isset($plans_sql)) {
                                $plans_stmt = $conn->prepare($plans_sql);
                                $plans_stmt->bind_param("i", $teacher_id);
                                $plans_stmt->execute();
                                $plans = $plans_stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
                                $plans_stmt->close();
                            }
                            $conn->commit();
                        } else {
                            throw new Exception("Failed to delete lesson plan.");
                        }
                        $delete_stmt->close();
                    }
                } catch (Exception $e) {
                    $conn->rollback();
                    $errors[] = "Error deleting lesson plan: " . $e->getMessage();
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="GES Lesson plan creation for QueensPa School teachers, with AI-assisted drafting from uploaded source documents.">
    <meta name="keywords" content="QueensPa School, lesson plan, teacher portal, GES">
    <meta name="author" content="QueensPa School">
    <meta name="robots" content="noindex, nofollow">
    <title>GES Lesson Plan - QueensPa School</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        :root {
            --primary-color: #28a745;
            --primary-hover: #218838;
            --ai-color: #8b5cf6;
            --ai-hover: #6d28d9;
            --text-color: #333;
            --bg-color: #f8f9fa;
            --shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }
        body {
            background: linear-gradient(135deg, #f8f9fa, #e9ecef);
            color: var(--text-color);
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        .container { max-width: 1400px; padding: 2rem; }
        .card {
            border: none;
            border-radius: 12px;
            background: #fff;
            box-shadow: var(--shadow);
            transition: transform 0.3s ease, box-shadow 0.3s ease;
            margin-bottom: 2rem;
        }
        .card:hover { transform: translateY(-5px); box-shadow: 0 8px 24px rgba(0, 0, 0, 0.15); }
        .card-header {
            background: linear-gradient(135deg, var(--primary-color), #34ce57);
            border-radius: 12px 12px 0 0;
            padding: 1.5rem;
            color: #fff;
        }
        .card-header h2, .card-header h3 { margin: 0; font-size: 1.8rem; font-weight: 600; }
        .btn-primary { background-color: var(--primary-color); border-color: var(--primary-color); border-radius: 8px; padding: 0.75rem 1.5rem; font-weight: 500; transition: all 0.3s ease; }
        .btn-primary:hover { background-color: var(--primary-hover); border-color: var(--primary-hover); }
        .btn-secondary { border-radius: 8px; padding: 0.75rem 1.5rem; font-weight: 500; }
        .btn-warning { border-radius: 8px; padding: 0.75rem 1.5rem; font-weight: 500; }
        .btn-danger { border-radius: 8px; padding: 0.75rem 1.5rem; font-weight: 500; }
        .btn-info { border-radius: 8px; padding: 0.75rem 1.5rem; font-weight: 500; }
        .btn-ai { background: linear-gradient(135deg, var(--ai-color), #a78bfa); color: #fff; border: none; border-radius: 8px; font-weight: 500; }
        .btn-ai:hover { background: linear-gradient(135deg, var(--ai-hover), var(--ai-color)); color: #fff; }
        .btn-ai:disabled { opacity: .6; }
        .btn-sm { padding: 0.5rem 0.75rem; font-size: 0.85rem; }
        .btn-xs { padding: 0.3rem 0.6rem; font-size: 0.75rem; border-radius: 6px; }
        .form-control, .form-select {
            border-radius: 8px; border: 1px solid #ced4da; padding: 0.75rem; font-size: 1rem;
            transition: border-color 0.3s ease, box-shadow 0.3s ease;
        }
        .form-control:focus, .form-select:focus {
            border-color: var(--primary-color); box-shadow: 0 0 0 3px rgba(40, 167, 69, 0.2); outline: none;
        }
        .alert { border-radius: 8px; padding: 1rem; display: flex; align-items: flex-start; gap: .5rem; margin-bottom: 1.5rem; }
        .section-card { background: #f9f9f9; border-radius: 8px; padding: 1.5rem; margin-bottom: 1.5rem; }
        .schedule-info { background-color: #e9ecef; padding: 1rem; border-radius: 8px; margin-bottom: 1rem; }
        .form-label { font-weight: 500; margin-bottom: 0.5rem; font-size: 0.95rem; }
        .invalid-feedback { display: none; font-size: 0.85rem; }
        .form-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 1.5rem; }
        .character-counter { font-size: 0.85rem; color: #666; }
        .character-counter.warning { color: var(--primary-color); }
        #loading-overlay {
            display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%;
            background: rgba(0, 0, 0, 0.5); z-index: 9999; justify-content: center; align-items: center;
            flex-direction: column; gap: .75rem;
        }
        .term-badge-current { background-color: #28a745; color: white; font-size: 0.7rem; padding: 2px 8px; border-radius: 20px; margin-left: 8px; }
        .ges-badge { background: linear-gradient(135deg, #166534, #22c55e); color: #fff; padding: .25rem .75rem; border-radius: 20px; font-size: .75rem; font-weight: 600; display: inline-flex; align-items: center; gap: .25rem; }
        .ai-badge { background: linear-gradient(135deg, var(--ai-color), var(--ai-hover)); color: #fff; padding: .25rem .75rem; border-radius: 20px; font-size: .75rem; font-weight: 600; display: inline-flex; align-items: center; gap: .25rem; }
        .schedule-day-badge { background: linear-gradient(135deg, #0891b2, #06b6d4); color: white; padding: .2rem .6rem; border-radius: 20px; font-size: .7rem; font-weight: 700; letter-spacing: .03em; display: inline-flex; align-items: center; gap: .25rem; }
        .ai-suggestion-box { background: linear-gradient(135deg, #f5f3ff, #ede9fe); border: 2px solid #c4b5fd; border-radius: 12px; padding: 1rem; margin-top: 0.5rem; position: relative; }
        .ai-suggestion-box::before {
            content: 'AI Suggestion'; position: absolute; top: -12px; left: 1rem;
            background: var(--ai-color); color: white; padding: 0.25rem 0.75rem; border-radius: 20px; font-size: 0.75rem; font-weight: 600;
        }
        .ai-fallback-box { background: linear-gradient(135deg, #fef3c7, #fde68a); border: 2px solid #f59e0b; border-radius: 12px; padding: 1rem; margin-top: 0.5rem; position: relative; }
        .ai-fallback-box::before {
            content: 'Template (Offline)'; position: absolute; top: -12px; left: 1rem;
            background: #f59e0b; color: white; padding: 0.25rem 0.75rem; border-radius: 20px; font-size: 0.75rem; font-weight: 600;
        }
        .dropzone {
            border: 2px dashed #86efac; border-radius: 12px; padding: 1.5rem; text-align: center;
            color: #15803d; cursor: pointer; background: #f0fdf4; transition: all .2s ease;
        }
        .dropzone.dragover { background: #dcfce7; border-color: #22c55e; }
        .source-chip {
            display: inline-flex; align-items: center; gap: .4rem; background: #ecfdf5; border: 1px solid #a7f3d0; color: #065f46;
            padding: .35rem .7rem; border-radius: 999px; font-size: .8rem; font-weight: 600; cursor: pointer;
        }
        .source-chip.selected { background: #16a34a; color: #fff; border-color: #16a34a; }
        .source-chip .fa-times { cursor: pointer; }
        .save-toast {
            position: fixed; top: 20px; right: 20px; z-index: 10000; min-width: 280px; max-width: 420px;
            padding: 1rem 1.25rem; border-radius: 12px; box-shadow: 0 10px 25px -5px rgba(0,0,0,0.2);
            display: flex; align-items: flex-start; gap: 0.75rem; animation: slideInRight 0.3s ease;
        }
        .save-toast.success { background: #ecfdf5; border: 1px solid #6ee7b7; color: #065f46; }
        .save-toast.error { background: #fef2f2; border: 1px solid #fca5a5; color: #991b1b; }
        @keyframes slideInRight { from { transform: translateX(120%); opacity: 0; } to { transform: translateX(0); opacity: 1; } }
        .view-detail { background: linear-gradient(135deg, #f0fdf4, #dcfce7); border: 1px solid #bbf7d0; border-radius: 12px; padding: 1.25rem; margin-bottom: 1rem; }
        .view-detail h4 { font-size: 1.1rem; font-weight: 700; color: #1f2937; margin-bottom: .75rem; display: flex; align-items: center; gap: .5rem; }
        .view-detail p { color: #374151; line-height: 1.7; white-space: pre-wrap; }
        .pulse { animation: pulse 2s infinite; }
        @keyframes pulse { 0%,100% { opacity: 1; } 50% { opacity: 0.5; } }
        @media (max-width: 991.98px) { .container { padding: 1rem; } .form-grid { grid-template-columns: 1fr; } }
        @media (min-width: 992px) { .basic-info { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 2rem; } }
        @media (max-width: 575.98px) { .btn { font-size: 0.9rem; padding: 0.6rem 1rem; } .card-header h2, .card-header h3 { font-size: 1.5rem; } }
    </style>
</head>
<body>
    <div id="loading-overlay" role="status" aria-label="Loading">
        <div class="spinner-border text-light" style="width: 3rem; height: 3rem;" role="status">
            <span class="visually-hidden">Loading...</span>
        </div>
        <p class="text-white fw-semibold" id="loading-message">Processing...</p>
    </div>

    <div id="toastContainer"></div>

    <!-- View Modal -->
    <div class="modal fade" id="viewModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-book-open text-success me-2"></i>View GES Lesson Plan</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body" id="viewContent"></div>
            </div>
        </div>
    </div>

    <!-- Edit Modal -->
    <div class="modal fade" id="editModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-edit text-warning me-2"></i>Edit GES Lesson Plan</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form id="editLessonPlanForm">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                        <input type="hidden" name="action" value="update">
                        <input type="hidden" id="edit_plan_id" name="plan_id">
                        <input type="hidden" id="edit_class_id" name="class_id">
                        <input type="hidden" id="edit_subject_id" name="subject_id">
                        <input type="hidden" id="edit_source_resource_ids" name="source_resource_ids">
                        <input type="hidden" id="edit_ai_generated" name="ai_generated" value="0">

                        <div class="basic-info mb-4">
                            <div>
                                <label class="form-label" for="edit_term_id">Academic Term <span class="text-danger">*</span></label>
                                <select class="form-select" id="edit_term_id" name="term_id" required>
                                    <option value="">Select Term</option>
                                    <?php foreach ($available_terms as $term): ?>
                                        <option value="<?php echo $term['id']; ?>"><?php echo htmlspecialchars($term['term_name'] . ' (' . $term['year'] . ')'); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div>
                                <label class="form-label" for="edit_week">Week <span class="text-danger">*</span></label>
                                <select class="form-select" id="edit_week" name="week" required>
                                    <option value="">Select Week</option>
                                    <?php for ($i = 1; $i <= 16; $i++): ?><option value="<?php echo $i; ?>">Week <?php echo $i; ?></option><?php endfor; ?>
                                </select>
                            </div>
                        </div>

                        <div class="section-card">
                            <h5 class="mb-3"><i class="fas fa-graduation-cap me-2"></i>Curriculum Details <small class="text-muted">(Optional)</small></h5>
                            <div class="form-grid">
                                <div><label class="form-label" for="edit_strand">Strand</label><input type="text" class="form-control" id="edit_strand" name="strand"></div>
                                <div><label class="form-label" for="edit_sub_strand">Sub-Strand</label><input type="text" class="form-control" id="edit_sub_strand" name="sub_strand"></div>
                                <div><label class="form-label" for="edit_content_standard">Content Standard</label><input type="text" class="form-control" id="edit_content_standard" name="content_standard"></div>
                                <div><label class="form-label" for="edit_indicator">Indicator</label><input type="text" class="form-control" id="edit_indicator" name="indicator"></div>
                            </div>
                        </div>

                        <div class="section-card">
                            <h5 class="mb-3"><i class="fas fa-tasks me-2"></i>Lesson Details</h5>
                            <div class="mb-3">
                                <label class="form-label" for="edit_objectives">Objectives <span class="text-danger">*</span></label>
                                <div class="d-flex gap-2"><textarea class="form-control" id="edit_objectives" name="objectives" rows="3" required></textarea>
                                <button type="button" class="btn btn-ai btn-xs align-self-start" onclick="getAISuggestion('objectives','edit')"><i class="fas fa-robot"></i> AI</button></div>
                                <div id="edit_objectives_ai" class="ai-suggestion-box d-none"></div>
                            </div>
                            <div class="mb-3"><label class="form-label" for="edit_reference">Reference <span class="text-danger">*</span></label><textarea class="form-control" id="edit_reference" name="reference" rows="2" required></textarea></div>
                            <div class="mb-3">
                                <label class="form-label" for="edit_resources">Resources <span class="text-danger">*</span></label>
                                <div class="d-flex gap-2"><textarea class="form-control" id="edit_resources" name="resources" rows="2" required></textarea>
                                <button type="button" class="btn btn-ai btn-xs align-self-start" onclick="getAISuggestion('resources','edit')"><i class="fas fa-robot"></i> AI</button></div>
                                <div id="edit_resources_ai" class="ai-suggestion-box d-none"></div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label" for="edit_keywords">Keywords <span class="text-danger">*</span></label>
                                <div class="d-flex gap-2"><input class="form-control" id="edit_keywords" name="keywords" required>
                                <button type="button" class="btn btn-ai btn-xs align-self-start" onclick="getAISuggestion('keywords','edit')"><i class="fas fa-robot"></i> AI</button></div>
                                <div id="edit_keywords_ai" class="ai-suggestion-box d-none"></div>
                            </div>
                        </div>

                        <div class="section-card">
                            <h5 class="mb-3"><i class="fas fa-chalkboard-teacher me-2"></i>Lesson Delivery</h5>
                            <div class="mb-3">
                                <label class="form-label" for="edit_starter">Starter / Introduction <span class="text-danger">*</span></label>
                                <div class="d-flex gap-2"><textarea class="form-control" id="edit_starter" name="starter" rows="3" required></textarea>
                                <button type="button" class="btn btn-ai btn-xs align-self-start" onclick="getAISuggestion('starter','edit')"><i class="fas fa-robot"></i> AI</button></div>
                                <div id="edit_starter_ai" class="ai-suggestion-box d-none"></div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label" for="edit_presentation">Main Lesson / Presentation <span class="text-danger">*</span></label>
                                <div class="d-flex gap-2"><textarea class="form-control" id="edit_presentation" name="presentation" rows="6" maxlength="20000" required></textarea>
                                <button type="button" class="btn btn-ai btn-xs align-self-start" onclick="getAISuggestion('presentation','edit')"><i class="fas fa-robot"></i> AI</button></div>
                                <div id="edit_presentation_ai" class="ai-suggestion-box d-none"></div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label" for="edit_summary">Summary / Plenary <span class="text-danger">*</span></label>
                                <div class="d-flex gap-2"><textarea class="form-control" id="edit_summary" name="summary" rows="3" required></textarea>
                                <button type="button" class="btn btn-ai btn-xs align-self-start" onclick="getAISuggestion('summary','edit')"><i class="fas fa-robot"></i> AI</button></div>
                                <div id="edit_summary_ai" class="ai-suggestion-box d-none"></div>
                            </div>
                        </div>

                        <div class="d-flex gap-2 mt-4">
                            <button type="submit" class="btn btn-warning" id="edit-submit-btn"><i class="fas fa-save me-2"></i>Update Lesson Plan</button>
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-2"></i>Cancel</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Delete Modal -->
    <div class="modal fade" id="deleteModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-sm">
            <div class="modal-content text-center p-3">
                <div class="modal-body">
                    <i class="fas fa-exclamation-triangle text-danger" style="font-size:3rem;"></i>
                    <h5 class="fw-bold mt-3">Confirm Deletion</h5>
                    <p class="text-muted">Are you sure you want to delete this lesson plan? This cannot be undone.</p>
                    <form id="deleteForm" class="d-flex justify-content-center gap-2">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                        <input type="hidden" id="delete_plan_id" name="plan_id">
                        <button type="submit" class="btn btn-danger"><i class="fas fa-trash me-1"></i>Delete</button>
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i>Cancel</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <?php include $_SERVER['DOCUMENT_ROOT'] . '/templates/teacher_header.php'; ?>
    <div class="container my-5">
        <div class="alert alert-info animate__animated animate__fadeIn">
            <i class="fas fa-calendar me-2"></i>
            <div class="flex-grow-1">
                <strong>Academic Year:</strong> <?php echo htmlspecialchars($default_academic_year ?: 'N/A'); ?>
                <span class="ges-badge ms-2"><i class="fas fa-graduation-cap"></i> GES</span>
                <span class="ai-badge ms-2"><i class="fas fa-robot"></i> AI Ready</span>
                <?php if (!$default_term_id): ?><span class="text-danger ms-2"><i class="fas fa-exclamation-triangle"></i> No active or upcoming term found.</span><?php endif; ?>
            </div>
        </div>

        <?php if (!empty($errors)): ?>
            <div class="alert alert-danger alert-dismissible fade show animate__animated animate__shakeX" role="alert">
                <?php foreach ($errors as $error): ?><p class="mb-0"><?php echo htmlspecialchars($error); ?></p><?php endforeach; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>
        <?php if ($success_msg): ?>
            <div class="alert alert-success alert-dismissible fade show animate__animated animate__fadeIn" role="alert">
                <?php echo htmlspecialchars($success_msg); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <!-- Existing Lesson Plans Section -->
        <section class="card mb-5 animate__animated animate__fadeIn">
            <div class="card-header"><h2><i class="fas fa-book-open me-2"></i>My GES Lesson Plans</h2></div>
            <div class="card-body">
                <div id="plansTableWrapper">
                <?php if (empty($plans)): ?>
                    <div class="alert alert-info" id="noPlansAlert"><i class="fas fa-info-circle me-2"></i>No lesson plans created yet. Create your first lesson plan below!</div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-striped table-hover">
                            <thead class="table-light">
                                <tr><th>Class</th><th>Subject</th><th>Indicator</th><th>Week</th><th>Term</th><th>Academic Year</th><th>Created At</th><th>Actions</th></tr>
                            </thead>
                            <tbody id="plansTableBody">
                                <?php foreach ($plans as $plan): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars(getDisplayClassName($plan['class_name'])); ?></td>
                                        <td><?php echo htmlspecialchars($plan['subject_name'] ?? 'N/A'); ?></td>
                                        <td><?php echo htmlspecialchars(substr($plan['indicator'] ?? 'N/A', 0, 40)); ?>
                                            <?php if (!empty($plan['ai_generated'])): ?><span class="ai-badge ms-1" style="font-size:.6rem;padding:.1rem .5rem;"><i class="fas fa-robot"></i></span><?php endif; ?>
                                        </td>
                                        <td><span class="badge bg-success">Week <?php echo htmlspecialchars($plan['week']); ?></span></td>
                                        <td><?php echo htmlspecialchars($plan['term_name'] ?? 'N/A'); ?></td>
                                        <td><?php echo htmlspecialchars($plan['academic_year'] ?? 'N/A'); ?></td>
                                        <td><?php echo htmlspecialchars(date('M d, Y', strtotime($plan['created_at']))); ?></td>
                                        <td>
                                            <div class="d-flex gap-1">
                                                <button type="button" class="btn btn-info btn-sm text-white" onclick="viewPlan(<?php echo $plan['id']; ?>)" title="View"><i class="fas fa-eye"></i></button>
                                                <button type="button" class="btn btn-warning btn-sm" onclick="editPlan(<?php echo $plan['id']; ?>)" title="Edit"><i class="fas fa-edit"></i></button>
                                                <button type="button" class="btn btn-danger btn-sm" onclick="deletePlan(<?php echo $plan['id']; ?>)" title="Delete"><i class="fas fa-trash"></i></button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
                </div>
            </div>
        </section>

        <!-- Create New Lesson Plan Section -->
        <section class="card mb-5 animate__animated animate__fadeIn">
            <div class="card-header"><h2><i class="fas fa-plus-circle me-2"></i>Create New GES Lesson Plan</h2></div>
            <div class="card-body">
                <?php if (empty($schedules)): ?>
                    <div class="alert alert-warning" role="alert"><i class="fas fa-exclamation-triangle me-2"></i>No active schedules available. Please contact your administrator.</div>
                <?php else: ?>
                    <form id="gesLessonPlanForm" novalidate>
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                        <input type="hidden" name="action" value="submit">

                        <!-- Basic Information -->
                        <div class="section-card">
                            <h5 class="mb-3"><i class="fas fa-info-circle me-2"></i>Basic Information</h5>
                            <div class="basic-info mb-4">
                                <div>
                                    <label class="form-label" for="term_id">Academic Term <span class="text-danger">*</span></label>
                                    <select class="form-select" id="term_id" name="term_id" required>
                                        <option value="">Select Term</option>
                                        <?php foreach ($available_terms as $term): ?>
                                            <option value="<?php echo $term['id']; ?>" <?php echo ($term['id'] == $default_term_id) ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($term['term_name'] . ' (' . $term['year'] . ')'); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <small class="text-muted">The current term is automatically selected.</small>
                                </div>
                                <div>
                                    <label class="form-label" for="week">Week <span class="text-danger">*</span></label>
                                    <select class="form-select" id="week" name="week" required>
                                        <option value="">Select Week</option>
                                        <?php for ($i = 1; $i <= 16; $i++): ?><option value="<?php echo $i; ?>">Week <?php echo $i; ?></option><?php endfor; ?>
                                    </select>
                                </div>
                                <div>
                                    <label class="form-label" for="schedule_id">Schedule <span class="text-danger">*</span></label>
                                    <select class="form-select" id="schedule_id" name="schedule_id" required>
                                        <option value="">Select Schedule</option>
                                        <?php foreach ($schedules as $schedule): ?>
                                            <option value="<?php echo $schedule['id']; ?>"><?php echo htmlspecialchars($schedule['label']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <input type="hidden" id="class_id" name="class_id">
                            <input type="hidden" id="subject_id" name="subject_id">
                            <input type="hidden" id="source_resource_ids" name="source_resource_ids" value="">
                            <input type="hidden" id="ai_generated" name="ai_generated" value="0">
                            <div id="schedule-container"></div>
                        </div>

                        <!-- Curriculum Details (Optional) -->
                        <div class="section-card">
                            <h5 class="mb-3"><i class="fas fa-graduation-cap me-2"></i>Curriculum Details <small class="text-muted">(Optional — leave blank to let AI decide from your sources)</small></h5>
                            <div class="form-grid">
                                <div><label class="form-label" for="strand">Strand</label><input type="text" class="form-control" id="strand" name="strand" placeholder="Enter strand"></div>
                                <div><label class="form-label" for="sub_strand">Sub-Strand</label><input type="text" class="form-control" id="sub_strand" name="sub_strand" placeholder="Enter sub-strand"></div>
                                <div><label class="form-label" for="content_standard">Content Standard</label><input type="text" class="form-control" id="content_standard" name="content_standard" placeholder="Enter content standard"></div>
                                <div><label class="form-label" for="indicator">Indicator</label><input type="text" class="form-control" id="indicator" name="indicator" placeholder="Enter indicator"></div>
                            </div>
                        </div>

                        <!-- NEW: Lesson Sources -->
                        <div class="section-card" style="background:#f0fdf4;border:2px solid #bbf7d0;">
                            <h5 class="mb-1"><i class="fas fa-folder-open text-success me-2"></i>Lesson Sources</h5>
                            <p class="text-muted small mb-3">Upload a textbook page, syllabus excerpt, worksheet, or notes (PDF, DOCX, TXT, or a photo) &mdash; in any language. The AI will read it carefully and build the lesson plan from it. Every file you upload is saved to your resource library, so you can reuse it for future weeks without re-uploading.</p>

                            <div id="sourceDropzone" class="dropzone">
                                <i class="fas fa-cloud-upload-alt" style="font-size:2rem;"></i>
                                <p class="fw-semibold mb-1 mt-2">Click to choose files or drag &amp; drop here</p>
                                <p class="text-muted small mb-0">PDF, DOCX, TXT, JPG, PNG, WEBP — up to <?php echo round($effective_max_upload_bytes / 1024 / 1024, 1); ?>MB each</p>
                                <input type="file" id="sourceFileInput" multiple accept=".pdf,.docx,.txt,.jpg,.jpeg,.png,.webp" class="d-none">
                            </div>
                            <div id="uploadProgressList" class="mt-3 d-flex flex-column gap-2"></div>

                            <div class="mt-4">
                                <div class="d-flex align-items-center justify-content-between mb-2">
                                    <h6 class="fw-semibold mb-0"><i class="fas fa-book-reader me-1"></i>Your saved sources for this class/subject</h6>
                                    <button type="button" class="btn btn-secondary btn-xs" onclick="loadResourceLibrary()"><i class="fas fa-sync"></i> Refresh</button>
                                </div>
                                <div id="resourceLibraryList" class="d-flex flex-wrap gap-2">
                                    <p class="text-muted small mb-0">Select a schedule above to see matching saved sources.</p>
                                </div>
                            </div>

                            <div class="mt-4">
                                <label class="form-label" for="plan_language">Lesson plan language</label>
                                <select id="plan_language" class="form-select" style="max-width:320px;">
                                    <option value="">Match the source document(s) / English</option>
                                    <option value="English">English</option>
                                    <option value="Twi">Twi</option>
                                    <option value="Ewe">Ewe</option>
                                    <option value="Ga">Ga</option>
                                    <option value="Dagbani">Dagbani</option>
                                    <option value="French">French</option>
                                    <option value="Hausa">Hausa</option>
                                    <option value="Other">Other (type below)</option>
                                </select>
                                <input type="text" id="plan_language_other" class="form-control mt-2 d-none" placeholder="Type the language name" style="max-width:320px;">
                                <p class="text-muted small mt-1 mb-0"><i class="fas fa-language me-1"></i>The AI can read source documents and write the plan in most languages. Pick one here, or leave it to auto-match your uploaded source.</p>
                            </div>

                            <div class="mt-4 d-flex align-items-center justify-content-between flex-wrap gap-3">
                                <div id="selectedSourcesSummary" class="text-success fw-semibold small">No sources selected — AI will use general GES curriculum knowledge instead.</div>
                                <button type="button" class="btn btn-ai pulse" id="generateFullPlanBtn" onclick="generateCompletePlan()"><i class="fas fa-magic me-1"></i> Generate Complete Plan</button>
                            </div>
                            <div id="complete_plan_ai" class="ai-suggestion-box d-none mt-3"></div>
                        </div>

                        <!-- Lesson Details -->
                        <div class="section-card">
                            <h5 class="mb-3"><i class="fas fa-tasks me-2"></i>Lesson Details</h5>
                            <div class="mb-3">
                                <label class="form-label" for="objectives">Objectives <span class="text-danger">*</span></label>
                                <div class="d-flex gap-2">
                                    <textarea class="form-control" id="objectives" name="objectives" rows="3" placeholder="Enter lesson objectives (what students will be able to do by the end of the lesson)" required></textarea>
                                    <button type="button" class="btn btn-ai btn-xs align-self-start" onclick="getAISuggestion('objectives','create')"><i class="fas fa-robot"></i> AI</button>
                                </div>
                                <div id="objectives_ai" class="ai-suggestion-box d-none mt-2"></div>
                                <div class="invalid-feedback">Objectives are required.</div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label" for="reference">Reference <span class="text-danger">*</span></label>
                                <textarea class="form-control" id="reference" name="reference" rows="2" placeholder="Enter teaching references (textbooks, curriculum guides, etc.)" required></textarea>
                                <div class="invalid-feedback">Reference is required.</div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label" for="resources">Resources <span class="text-danger">*</span></label>
                                <div class="d-flex gap-2">
                                    <textarea class="form-control" id="resources" name="resources" rows="2" placeholder="Enter teaching and learning resources (materials needed)" required></textarea>
                                    <button type="button" class="btn btn-ai btn-xs align-self-start" onclick="getAISuggestion('resources','create')"><i class="fas fa-robot"></i> AI</button>
                                </div>
                                <div id="resources_ai" class="ai-suggestion-box d-none mt-2"></div>
                                <div class="invalid-feedback">Resources are required.</div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label" for="keywords">Keywords <span class="text-danger">*</span></label>
                                <div class="d-flex gap-2">
                                    <input class="form-control" id="keywords" name="keywords" placeholder="e.g., critical thinking, collaboration, problem-solving" required>
                                    <button type="button" class="btn btn-ai btn-xs align-self-start" onclick="getAISuggestion('keywords','create')"><i class="fas fa-robot"></i> AI</button>
                                </div>
                                <div id="keywords_ai" class="ai-suggestion-box d-none mt-2"></div>
                                <div class="invalid-feedback">Keywords are required.</div>
                            </div>
                        </div>

                        <!-- Lesson Delivery -->
                        <div class="section-card">
                            <h5 class="mb-3"><i class="fas fa-chalkboard-teacher me-2"></i>Lesson Delivery</h5>
                            <div class="mb-3">
                                <label class="form-label" for="starter">Starter / Introduction <span class="text-danger">*</span></label>
                                <div class="d-flex gap-2">
                                    <textarea class="form-control" id="starter" name="starter" rows="3" placeholder="Enter lesson starter activity (5-10 minutes opening activity)" required></textarea>
                                    <button type="button" class="btn btn-ai btn-xs align-self-start" onclick="getAISuggestion('starter','create')"><i class="fas fa-robot"></i> AI</button>
                                </div>
                                <div id="starter_ai" class="ai-suggestion-box d-none mt-2"></div>
                                <div class="invalid-feedback">Starter is required.</div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label" for="presentation">Main Lesson / Presentation <span class="text-danger">*</span></label>
                                <div class="d-flex gap-2">
                                    <textarea class="form-control" id="presentation" name="presentation" rows="6" maxlength="20000" placeholder="Enter the main lesson content, teaching steps, and learning activities" required></textarea>
                                    <button type="button" class="btn btn-ai btn-xs align-self-start" onclick="getAISuggestion('presentation','create')"><i class="fas fa-robot"></i> AI</button>
                                </div>
                                <div id="presentation_ai" class="ai-suggestion-box d-none mt-2"></div>
                                <small id="presentation-counter" class="character-counter">0/20000 characters</small>
                                <div class="invalid-feedback">Presentation is required.</div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label" for="summary">Summary / Plenary <span class="text-danger">*</span></label>
                                <div class="d-flex gap-2">
                                    <textarea class="form-control" id="summary" name="summary" rows="3" placeholder="Enter lesson summary and conclusion" required></textarea>
                                    <button type="button" class="btn btn-ai btn-xs align-self-start" onclick="getAISuggestion('summary','create')"><i class="fas fa-robot"></i> AI</button>
                                </div>
                                <div id="summary_ai" class="ai-suggestion-box d-none mt-2"></div>
                                <div class="invalid-feedback">Summary is required.</div>
                            </div>
                        </div>

                        <!-- Form Actions -->
                        <div class="d-flex gap-2 mt-4">
                            <button type="submit" class="btn btn-primary" id="submit-btn"><i class="fas fa-save me-2"></i>Save Lesson Plan</button>
                            <a href="weekly_plan_ges.php" class="btn btn-secondary"><i class="fas fa-undo me-2"></i>Reset</a>
                        </div>
                    </form>
                <?php endif; ?>
            </div>
        </section>
    </div>

    <?php include $_SERVER['DOCUMENT_ROOT'] . '/templates/teacher_footer.php'; ?>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    const csrfToken = '<?php echo htmlspecialchars($csrf_token); ?>';
    const effectiveMaxUploadBytes = <?php echo (int)$effective_max_upload_bytes; ?>;
    let aiRequestTimeout = null;
    const AI_TIMEOUT = 45000;
    let currentSchedule = null;
    let selectedResourceIds = new Set();
    let libraryResources = [];

    const viewModal = new bootstrap.Modal(document.getElementById('viewModal'));
    const editModal = new bootstrap.Modal(document.getElementById('editModal'));
    const deleteModal = new bootstrap.Modal(document.getElementById('deleteModal'));

    function showLoading(msg) {
        $('#loading-message').text(msg || 'Processing...');
        $('#loading-overlay').css('display', 'flex');
    }
    function hideLoading() { $('#loading-overlay').css('display', 'none'); }

    function escapeHtml(text) {
        const d = document.createElement('div');
        d.textContent = text == null ? '' : text;
        return d.innerHTML;
    }

    function showToast(message, type) {
        type = type || 'success';
        const id = 'toast_' + Date.now();
        const icon = type === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle';
        $('#toastContainer').append(
            `<div id="${id}" class="save-toast ${type}"><i class="fas ${icon} fs-5"></i><div class="flex-grow-1">${message}</div></div>`
        );
        setTimeout(() => $('#' + id).fadeOut(300, function() { $(this).remove(); }), 4500);
    }

    function formatTime12JS(time) {
        if (!time) return '';
        const parts = time.split(':');
        let h = parseInt(parts[0], 10);
        const m = parts[1] || '00';
        const ampm = h >= 12 ? 'PM' : 'AM';
        h = h % 12 || 12;
        return h + ':' + m + ' ' + ampm;
    }
    function formatTimeRangeJS(start, end) { return formatTime12JS(start) + ' - ' + formatTime12JS(end); }

    function loadSchedules(termId) {
        if (!termId) {
            $('#schedule_id').empty().append('<option value="">Select a schedule</option>');
            $('#class_id, #subject_id').val('');
            return;
        }
        showLoading('Loading schedules...');
        $.get(window.location.pathname + '?action=get_schedules', { term_id: termId }, function(response) {
            hideLoading();
            const sel = $('#schedule_id');
            sel.empty().append('<option value="">Select a schedule</option>');
            if (response.schedules && response.schedules.length) {
                response.schedules.forEach(s => sel.append(`<option value="${s.id}">${escapeHtml(s.label)}</option>`));
            } else {
                sel.append('<option value="">No schedules for this term</option>');
            }
            $('#schedule-container').empty();
            $('#class_id, #subject_id').val('');
            currentSchedule = null;
        }, 'json').fail(function() { hideLoading(); alert('Error loading schedules.'); });
    }

    $('#term_id').on('change', function() { loadSchedules($(this).val()); });

    $('#schedule_id').on('change', function() {
        const id = $(this).val();
        if (!id) { $('#class_id, #subject_id').val(''); $('#schedule-container').empty(); currentSchedule = null; return; }
        showLoading('Loading schedule details...');
        $.get(window.location.pathname + '?action=get_schedule_details', { schedule_id: id }, function(r) {
            hideLoading();
            if (r.error) {
                $('#schedule-container').html('<div class="alert alert-warning mt-2">' + escapeHtml(r.error) + '</div>');
            } else if (r.schedule) {
                currentSchedule = r.schedule;
                $('#class_id').val(r.schedule.class_id);
                $('#subject_id').val(r.schedule.subject_id || '');
                $('#schedule-container').html(
                    '<div class="schedule-info mt-3"><h6 class="text-success fw-semibold"><i class="fas fa-check-circle"></i> Selected: ' + escapeHtml(r.schedule.display_class_name) + ' - ' + escapeHtml(r.schedule.subject_name) + '</h6>' +
                    '<p class="mb-0 small d-flex align-items-center gap-2 flex-wrap">' +
                    '<span class="schedule-day-badge"><i class="fas fa-calendar-day"></i> ' + escapeHtml(r.schedule.day_of_week) + '</span>' +
                    '<span><strong>Time:</strong> ' + escapeHtml(formatTimeRangeJS(r.schedule.start_time, r.schedule.end_time)) + '</span></p></div>'
                );
                loadResourceLibrary();
            }
        }, 'json').fail(function() { hideLoading(); });
    });

    $('#plan_language').on('change', function() { $('#plan_language_other').toggleClass('d-none', $(this).val() !== 'Other'); });

    function getSelectedPlanLanguage() {
        const sel = $('#plan_language').val();
        if (sel === 'Other') return $('#plan_language_other').val().trim();
        return sel || '';
    }

    // ================= SOURCES / RESOURCE LIBRARY =================

    function loadResourceLibrary() {
        const subjectId = $('#subject_id').val() || 0;
        const classId = $('#class_id').val() || 0;
        $.get(window.location.pathname + '?action=list_resources', { subject_id: subjectId, class_id: classId }, function(r) {
            libraryResources = r.resources || [];
            renderResourceLibrary();
        }, 'json');
    }

    function renderResourceLibrary() {
        const box = $('#resourceLibraryList');
        if (!libraryResources.length) {
            box.html('<p class="text-muted small mb-0">No saved sources yet for this class/subject. Upload one above.</p>');
        } else {
            box.html(libraryResources.map(res => {
                const sel = selectedResourceIds.has(res.id) ? 'selected' : '';
                return `<span class="source-chip ${sel}" data-id="${res.id}" onclick="toggleResource(${res.id})">
                            <i class="fas fa-file-alt"></i> ${escapeHtml(res.title)}
                            <i class="fas fa-times" title="Remove from library" onclick="event.stopPropagation(); deleteResource(${res.id})"></i>
                        </span>`;
            }).join(''));
        }
        updateSelectedSourcesSummary();
    }

    function toggleResource(id) {
        if (selectedResourceIds.has(id)) selectedResourceIds.delete(id); else selectedResourceIds.add(id);
        renderResourceLibrary();
    }

    function updateSelectedSourcesSummary() {
        const n = selectedResourceIds.size;
        $('#selectedSourcesSummary').text(
            n === 0 ? 'No sources selected — AI will use general GES curriculum knowledge instead.'
                    : n + ' source' + (n > 1 ? 's' : '') + ' selected — AI will ground the plan in these documents.'
        );
        $('#source_resource_ids').val(JSON.stringify(Array.from(selectedResourceIds)));
    }

    function deleteResource(id) {
        if (!confirm('Remove this saved source permanently?')) return;
        $.post(window.location.pathname, { ajax_action: 'delete_resource', resource_id: id, csrf_token: csrfToken }, function(r) {
            if (r.success) { selectedResourceIds.delete(id); loadResourceLibrary(); }
            else { alert(r.error || 'Could not delete resource.'); }
        }, 'json');
    }

    function uploadSourceFile(file) {
        const rowId = 'upl_' + Math.random().toString(36).slice(2);

        if (effectiveMaxUploadBytes > 0 && file.size > effectiveMaxUploadBytes) {
            const limitMb = (effectiveMaxUploadBytes / 1024 / 1024).toFixed(1);
            $('#uploadProgressList').append(
                `<div id="${rowId}" class="small text-muted"><i class="fas fa-exclamation-circle text-danger"></i> ${escapeHtml(file.name)} — too large. Server limit is currently ${limitMb}MB.</div>`
            );
            return;
        }

        $('#uploadProgressList').append(
            `<div id="${rowId}" class="small text-muted"><i class="fas fa-spinner fa-spin text-success"></i> ${escapeHtml(file.name)} — uploading...</div>`
        );
        const fd = new FormData();
        fd.append('ajax_action', 'upload_resource');
        fd.append('csrf_token', csrfToken);
        fd.append('file', file);
        fd.append('title', file.name.replace(/\.[^/.]+$/, ''));
        fd.append('subject_id', $('#subject_id').val() || '');
        fd.append('class_id', $('#class_id').val() || '');
        fd.append('term_id', $('#term_id').val() || '');
        fd.append('week', $('#week').val() || '');

        $.ajax({
            url: window.location.pathname, type: 'POST', data: fd, processData: false, contentType: false, dataType: 'json',
            success: function(r) {
                if (r.success) {
                    $('#' + rowId).html(`<i class="fas fa-check-circle text-success"></i> ${escapeHtml(file.name)} — saved to your sources.`);
                    selectedResourceIds.add(r.resource.id);
                    loadResourceLibrary();
                } else {
                    $('#' + rowId).html(`<i class="fas fa-exclamation-circle text-danger"></i> ${escapeHtml(file.name)} — ${escapeHtml(r.error || 'upload failed')}`);
                }
            },
            error: function() {
                $('#' + rowId).html(`<i class="fas fa-exclamation-circle text-danger"></i> ${escapeHtml(file.name)} — network error during upload.`);
            }
        });
    }

    const dz = document.getElementById('sourceDropzone');
    const fileInput = document.getElementById('sourceFileInput');
    dz.addEventListener('click', () => fileInput.click());
    fileInput.addEventListener('change', (e) => { Array.from(e.target.files).forEach(uploadSourceFile); fileInput.value = ''; });
    ['dragover', 'dragenter'].forEach(evt => dz.addEventListener(evt, (e) => { e.preventDefault(); dz.classList.add('dragover'); }));
    ['dragleave', 'drop'].forEach(evt => dz.addEventListener(evt, (e) => { e.preventDefault(); dz.classList.remove('dragover'); }));
    dz.addEventListener('drop', (e) => { Array.from(e.dataTransfer.files).forEach(uploadSourceFile); });

    // ================= AI: single-field suggestions =================

    function getAISuggestion(type, formType, retryCount) {
        retryCount = retryCount || 0;
        const prefix = formType === 'edit' ? 'edit_' : '';
        const indicator = document.getElementById(prefix + 'indicator')?.value?.trim() || '';
        const contentStandard = document.getElementById(prefix + 'content_standard')?.value?.trim() || '';
        if (!indicator && !contentStandard) {
            alert('Please enter a Content Standard or Indicator first (or use "Generate Complete Plan", which fills these in automatically).');
            return;
        }
        const aiBox = document.getElementById(prefix + type + '_ai');
        if (!aiBox) return;
        aiBox.className = 'ai-suggestion-box mt-2';
        aiBox.classList.remove('d-none');
        aiBox.innerHTML = '<div class="d-flex align-items-center gap-2 text-purple"><i class="fas fa-spinner fa-spin"></i> AI generating... Please wait up to 45 seconds</div>';
        const btn = event && event.target ? event.target.closest('button') : null;
        if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>'; }
        showLoading('AI generating ' + type + '...');
        const classSelect = document.getElementById('schedule_id');
        const classText = classSelect?.options[classSelect.selectedIndex]?.text || 'Class';
        const context = JSON.stringify({ indicator: indicator, content_standard: contentStandard, class_name: classText.split(' - ')[0] || 'Class', language: getSelectedPlanLanguage() });

        if (aiRequestTimeout) clearTimeout(aiRequestTimeout);
        aiRequestTimeout = setTimeout(function() {
            hideLoading();
            if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fas fa-robot"></i> AI'; }
            if (retryCount < 2) {
                aiBox.innerHTML = '<div class="text-warning"><i class="fas fa-clock"></i> Request timed out. Retrying... (Attempt ' + (retryCount + 2) + ')</div>';
                getAISuggestion(type, formType, retryCount + 1);
            } else {
                aiBox.className = 'ai-fallback-box mt-2';
                aiBox.innerHTML = '<div><p class="fw-semibold mb-2"><i class="fas fa-clock"></i> AI timed out after multiple retries. Please try again.</p></div>' +
                    '<button type="button" onclick="getAISuggestion(\'' + type + '\', \'' + formType + '\', 0)" class="btn btn-warning btn-xs"><i class="fas fa-redo"></i> Retry AI</button>';
            }
        }, AI_TIMEOUT);

        $.ajax({
            url: window.location.pathname + '?action=ai_suggestion', type: 'GET', data: { type: type, context: context }, dataType: 'json', timeout: AI_TIMEOUT,
            success: function(response) {
                if (aiRequestTimeout) clearTimeout(aiRequestTimeout);
                hideLoading();
                if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fas fa-robot"></i> AI'; }
                if (response.error) {
                    if (retryCount < 2) {
                        aiBox.innerHTML = '<div class="text-warning"><i class="fas fa-exclamation-triangle"></i> ' + escapeHtml(response.error) + ' Retrying... (Attempt ' + (retryCount + 2) + ')</div>';
                        getAISuggestion(type, formType, retryCount + 1);
                    } else {
                        aiBox.className = 'ai-fallback-box mt-2';
                        aiBox.innerHTML = '<div class="text-warning"><i class="fas fa-exclamation-triangle"></i> ' + escapeHtml(response.error) + '</div>' +
                            '<button type="button" onclick="getAISuggestion(\'' + type + '\', \'' + formType + '\', 0)" class="btn btn-warning btn-xs mt-2"><i class="fas fa-redo"></i> Retry AI</button>';
                    }
                } else if (response.suggestion) {
                    const isFallback = !!response.notice;
                    aiBox.className = isFallback ? 'ai-fallback-box mt-2' : 'ai-suggestion-box mt-2';
                    aiBox.innerHTML = (response.notice ? '<p class="text-warning small mb-2"><i class="fas fa-info-circle"></i> ' + escapeHtml(response.notice) + '</p>' : '') +
                        '<div style="white-space:pre-wrap;">' + escapeHtml(response.suggestion) + '</div>' +
                        '<button type="button" onclick="applySuggestion(\'' + type + '\', \'' + formType + '\')" class="btn btn-primary btn-xs mt-2"><i class="fas fa-check"></i> Apply</button>' +
                        '<button type="button" onclick="getAISuggestion(\'' + type + '\', \'' + formType + '\', 0)" class="btn btn-warning btn-xs mt-2 ms-2"><i class="fas fa-redo"></i> Regenerate</button>' +
                        '<button type="button" onclick="document.getElementById(\'' + prefix + type + '_ai\').classList.add(\'d-none\')" class="btn btn-secondary btn-xs mt-2 ms-2"><i class="fas fa-times"></i> Dismiss</button>';
                }
            },
            error: function() {
                if (aiRequestTimeout) clearTimeout(aiRequestTimeout);
                hideLoading();
                if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fas fa-robot"></i> AI'; }
                if (retryCount < 2) {
                    aiBox.innerHTML = '<div class="text-warning"><i class="fas fa-exclamation-triangle"></i> Network error. Retrying... (Attempt ' + (retryCount + 2) + ')</div>';
                    getAISuggestion(type, formType, retryCount + 1);
                } else {
                    aiBox.className = 'ai-fallback-box mt-2';
                    aiBox.innerHTML = '<div><p class="fw-semibold mb-2"><i class="fas fa-exclamation-triangle"></i> AI service unavailable after multiple attempts.</p></div>' +
                        '<button type="button" onclick="getAISuggestion(\'' + type + '\', \'' + formType + '\', 0)" class="btn btn-warning btn-xs"><i class="fas fa-redo"></i> Retry AI</button>';
                }
            }
        });
    }

    function applySuggestion(type, formType) {
        const prefix = formType === 'edit' ? 'edit_' : '';
        const aiBox = document.getElementById(prefix + type + '_ai');
        const target = document.getElementById(prefix + type);
        if (aiBox && target) {
            const textDiv = aiBox.querySelector('div[style*="white-space:pre-wrap"]');
            const text = textDiv ? textDiv.textContent.trim() : '';
            target.value = text;
            $(target).trigger('input');
            aiBox.classList.add('d-none');
        }
    }

    // ================= SMART COMPLETE-PLAN GENERATION =================

    function generateCompletePlan() {
        if (!currentSchedule) { alert('Please select a term and a schedule (class & subject) first.'); return; }
        const week = $('#week').val();
        if (!week) { alert('Please select a week first.'); return; }

        const aiBox = document.getElementById('complete_plan_ai');
        const btn = document.getElementById('generateFullPlanBtn');
        aiBox.className = 'ai-suggestion-box mt-3';
        aiBox.classList.remove('d-none');
        aiBox.innerHTML = '<div class="d-flex align-items-center gap-2"><i class="fas fa-spinner fa-spin"></i> Reading your sources and drafting the full lesson plan... (up to 60 seconds)</div>';
        if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Generating...'; }
        showLoading('AI generating complete plan...');

        const payload = {
            ajax_action: 'generate_plan', csrf_token: csrfToken, week: week,
            class_name: currentSchedule.display_class_name, subject_name: currentSchedule.subject_name,
            day_of_week: currentSchedule.day_of_week, duration: formatTimeRangeJS(currentSchedule.start_time, currentSchedule.end_time),
            strand: $('#strand').val().trim(), sub_strand: $('#sub_strand').val().trim(),
            content_standard: $('#content_standard').val().trim(), indicator: $('#indicator').val().trim(),
            resource_ids: JSON.stringify(Array.from(selectedResourceIds)), language: getSelectedPlanLanguage(),
        };

        $.ajax({
            url: window.location.pathname, type: 'POST', data: payload, dataType: 'json', timeout: 90000,
            success: function(response) {
                hideLoading();
                if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fas fa-magic me-1"></i> Generate Complete Plan'; }
                if (response.success) {
                    applyGeneratedPlan(response.plan, response.used_resource_ids || []);
                    aiBox.className = 'ai-suggestion-box mt-3';
                    aiBox.innerHTML = '<div class="text-success"><i class="fas fa-check-circle"></i> Complete plan generated and filled into the form below. Review it, then click <strong>Save Lesson Plan</strong>.</div>' +
                        '<button type="button" onclick="generateCompletePlan()" class="btn btn-warning btn-xs mt-2"><i class="fas fa-redo"></i> Regenerate</button>' +
                        '<button type="button" onclick="document.getElementById(\'complete_plan_ai\').classList.add(\'d-none\')" class="btn btn-secondary btn-xs mt-2 ms-2"><i class="fas fa-times"></i> Dismiss</button>';
                } else {
                    aiBox.className = 'ai-fallback-box mt-3';
                    aiBox.innerHTML = '<div><i class="fas fa-exclamation-triangle"></i> ' + escapeHtml(response.error || 'Generation failed.') + '</div>' +
                        '<button type="button" onclick="generateCompletePlan()" class="btn btn-warning btn-xs mt-2"><i class="fas fa-redo"></i> Retry</button>';
                }
            },
            error: function() {
                hideLoading();
                if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fas fa-magic me-1"></i> Generate Complete Plan'; }
                aiBox.className = 'ai-fallback-box mt-3';
                aiBox.innerHTML = '<div>Network or server error while generating. Please try again.</div>' +
                    '<button type="button" onclick="generateCompletePlan()" class="btn btn-warning btn-xs mt-2"><i class="fas fa-redo"></i> Retry</button>';
            }
        });
    }

    function applyGeneratedPlan(plan, usedResourceIds) {
        $('#strand').val(plan.strand || '');
        $('#sub_strand').val(plan.sub_strand || '');
        $('#content_standard').val(plan.content_standard || '');
        $('#indicator').val(plan.indicator || '');
        if (plan.objectives) $('#objectives').val(plan.objectives);
        if (plan.reference) $('#reference').val(plan.reference);
        if (plan.resources) $('#resources').val(plan.resources);
        if (plan.keywords) $('#keywords').val(plan.keywords);
        if (plan.starter) $('#starter').val(plan.starter);
        if (plan.presentation) { $('#presentation').val(plan.presentation); $('#presentation').trigger('input'); }
        if (plan.summary) $('#summary').val(plan.summary);
        $('#source_resource_ids').val(JSON.stringify(usedResourceIds || []));
        $('#ai_generated').val('1');
    }

    // ================= ONE-CLICK AJAX SAVE (create + update) =================

    function buildPlanPayload(isUpdate) {
        return {
            ajax_action: 'save_plan', csrf_token: csrfToken, action: isUpdate ? 'update' : 'submit',
            plan_id: isUpdate ? $('#edit_plan_id').val() : 0,
            term_id: isUpdate ? $('#edit_term_id').val() : $('#term_id').val(),
            week: isUpdate ? $('#edit_week').val() : $('#week').val(),
            class_id: isUpdate ? $('#edit_class_id').val() : $('#class_id').val(),
            subject_id: isUpdate ? $('#edit_subject_id').val() : $('#subject_id').val(),
            strand: isUpdate ? $('#edit_strand').val() : $('#strand').val(),
            sub_strand: isUpdate ? $('#edit_sub_strand').val() : $('#sub_strand').val(),
            content_standard: isUpdate ? $('#edit_content_standard').val() : $('#content_standard').val(),
            indicator: isUpdate ? $('#edit_indicator').val() : $('#indicator').val(),
            objectives: isUpdate ? $('#edit_objectives').val() : $('#objectives').val(),
            reference: isUpdate ? $('#edit_reference').val() : $('#reference').val(),
            resources: isUpdate ? $('#edit_resources').val() : $('#resources').val(),
            keywords: isUpdate ? $('#edit_keywords').val() : $('#keywords').val(),
            starter: isUpdate ? $('#edit_starter').val() : $('#starter').val(),
            presentation: isUpdate ? $('#edit_presentation').val() : $('#presentation').val(),
            summary: isUpdate ? $('#edit_summary').val() : $('#summary').val(),
            source_resource_ids: isUpdate ? $('#edit_source_resource_ids').val() : $('#source_resource_ids').val(),
            ai_generated: isUpdate ? $('#edit_ai_generated').val() : $('#ai_generated').val(),
        };
    }

    function reloadPlansTable() {
        $.get(window.location.pathname + '?action=list_plans', {}, function(r) {
            if (!r || !r.rows) return;
            if (!r.rows.length) {
                $('#plansTableWrapper').html('<div class="alert alert-info" id="noPlansAlert"><i class="fas fa-info-circle me-2"></i>No lesson plans created yet. Create your first lesson plan below!</div>');
                return;
            }
            let rowsHtml = '';
            r.rows.forEach(p => {
                rowsHtml += '<tr>' +
                    '<td>' + escapeHtml(p.display_class_name) + '</td>' +
                    '<td>' + escapeHtml(p.subject_name || 'N/A') + '</td>' +
                    '<td>' + escapeHtml((p.indicator || 'N/A').substring(0, 40)) + (p.ai_generated ? ' <span class="ai-badge ms-1" style="font-size:.6rem;padding:.1rem .5rem;"><i class="fas fa-robot"></i></span>' : '') + '</td>' +
                    '<td><span class="badge bg-success">Week ' + p.week + '</span></td>' +
                    '<td>' + escapeHtml(p.term_name) + '</td>' +
                    '<td>' + escapeHtml(p.academic_year) + '</td>' +
                    '<td>' + escapeHtml(p.created_at_display) + '</td>' +
                    '<td><div class="d-flex gap-1">' +
                        '<button type="button" class="btn btn-info btn-sm text-white" onclick="viewPlan(' + p.id + ')"><i class="fas fa-eye"></i></button>' +
                        '<button type="button" class="btn btn-warning btn-sm" onclick="editPlan(' + p.id + ')"><i class="fas fa-edit"></i></button>' +
                        '<button type="button" class="btn btn-danger btn-sm" onclick="deletePlan(' + p.id + ')"><i class="fas fa-trash"></i></button>' +
                    '</div></td>' +
                '</tr>';
            });
            $('#plansTableWrapper').html(
                '<div class="table-responsive"><table class="table table-striped table-hover"><thead class="table-light"><tr>' +
                '<th>Class</th><th>Subject</th><th>Indicator</th><th>Week</th><th>Term</th><th>Academic Year</th><th>Created At</th><th>Actions</th>' +
                '</tr></thead><tbody id="plansTableBody">' + rowsHtml + '</tbody></table></div>'
            );
        }, 'json');
    }

    $('#gesLessonPlanForm').on('submit', function(e) {
        e.preventDefault();
        const $btn = $('#submit-btn');
        $btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Saving...');
        showLoading('Saving lesson plan...');
        $.post(window.location.pathname, buildPlanPayload(false), function(r) {
            hideLoading();
            $btn.prop('disabled', false).html('<i class="fas fa-save me-2"></i>Save Lesson Plan');
            if (r.success) {
                showToast(r.message || 'Lesson plan saved successfully!', 'success');
                reloadPlansTable();
                document.getElementById('gesLessonPlanForm').reset();
                $('#schedule-container').empty();
                $('#complete_plan_ai, #objectives_ai, #resources_ai, #keywords_ai, #starter_ai, #presentation_ai, #summary_ai').addClass('d-none');
                $('#presentation-counter').text('0/20000 characters');
                currentSchedule = null;
                selectedResourceIds = new Set();
                $('#source_resource_ids').val('');
                $('#ai_generated').val('0');
                window.scrollTo({ top: 0, behavior: 'smooth' });
            } else if (r.errors && r.errors.length) {
                showToast(r.errors.join('<br>'), 'error');
            } else {
                showToast(r.error || 'Could not save the lesson plan.', 'error');
            }
        }, 'json').fail(function() {
            hideLoading();
            $btn.prop('disabled', false).html('<i class="fas fa-save me-2"></i>Save Lesson Plan');
            showToast('Network error while saving. Please try again.', 'error');
        });
    });

    $('#editLessonPlanForm').on('submit', function(e) {
        e.preventDefault();
        const $btn = $('#edit-submit-btn');
        $btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Saving...');
        showLoading('Saving lesson plan...');
        $.post(window.location.pathname, buildPlanPayload(true), function(r) {
            hideLoading();
            $btn.prop('disabled', false).html('<i class="fas fa-save me-2"></i>Update Lesson Plan');
            if (r.success) {
                showToast(r.message || 'Lesson plan updated successfully!', 'success');
                reloadPlansTable();
                editModal.hide();
            } else if (r.errors && r.errors.length) {
                showToast(r.errors.join('<br>'), 'error');
            } else {
                showToast(r.error || 'Could not update the lesson plan.', 'error');
            }
        }, 'json').fail(function() {
            hideLoading();
            $btn.prop('disabled', false).html('<i class="fas fa-save me-2"></i>Update Lesson Plan');
            showToast('Network error while saving. Please try again.', 'error');
        });
    });

    $('#deleteForm').on('submit', function(e) {
        e.preventDefault();
        showLoading('Deleting...');
        $.post(window.location.pathname, { ajax_action: 'delete_plan', csrf_token: csrfToken, plan_id: $('#delete_plan_id').val() }, function(r) {
            hideLoading();
            deleteModal.hide();
            if (r.success) { showToast(r.message || 'Lesson plan deleted.', 'success'); reloadPlansTable(); }
            else { showToast(r.error || 'Could not delete the lesson plan.', 'error'); }
        }, 'json').fail(function() {
            hideLoading();
            deleteModal.hide();
            showToast('Network error while deleting. Please try again.', 'error');
        });
    });

    function viewPlan(id) {
        showLoading('Loading...');
        $.get(window.location.pathname + '?action=view_plan', { plan_id: id }, function(r) {
            hideLoading();
            if (r.error) { alert(r.error); return; }
            const p = r.plan, s = r.schedule;
            let c = '<div class="view-detail"><h4><i class="fas fa-info-circle text-success"></i> Overview</h4>' +
                '<div class="row small"><div class="col-md-4"><strong>Class:</strong> ' + escapeHtml(p.display_class_name) + '</div>' +
                '<div class="col-md-4"><strong>Subject:</strong> ' + escapeHtml(p.subject_name || 'N/A') + '</div>' +
                '<div class="col-md-4"><strong>Week:</strong> ' + p.week + '</div></div></div>';
            if (s) c += '<div class="view-detail"><h4><i class="fas fa-clock text-info"></i> Schedule</h4><p><span class="schedule-day-badge">' + escapeHtml(s.day_of_week) + '</span> &nbsp; ' + escapeHtml(formatTimeRangeJS(s.start_time, s.end_time)) + '</p></div>';
            if (p.strand || p.sub_strand || p.content_standard || p.indicator) {
                c += '<div class="view-detail"><h4>Curriculum Alignment</h4>' +
                    (p.strand ? '<p><strong>Strand:</strong> ' + escapeHtml(p.strand) + '</p>' : '') +
                    (p.sub_strand ? '<p><strong>Sub-strand:</strong> ' + escapeHtml(p.sub_strand) + '</p>' : '') +
                    (p.content_standard ? '<p><strong>Content Standard:</strong> ' + escapeHtml(p.content_standard) + '</p>' : '') +
                    (p.indicator ? '<p><strong>Indicator:</strong> ' + escapeHtml(p.indicator) + '</p>' : '') + '</div>';
            }
            c += '<div class="view-detail"><h4>Objectives</h4><p>' + escapeHtml(p.objectives || 'N/A') + '</p></div>' +
                '<div class="view-detail"><h4>Reference</h4><p>' + escapeHtml(p.reference || 'N/A') + '</p></div>' +
                '<div class="view-detail"><h4>Resources</h4><p>' + escapeHtml(p.resources || 'N/A') + '</p></div>' +
                '<div class="view-detail"><h4>Keywords</h4><p>' + escapeHtml(p.keywords || 'N/A') + '</p></div>' +
                '<div class="view-detail"><h4>Starter</h4><p>' + escapeHtml(p.starter || 'N/A') + '</p></div>' +
                '<div class="view-detail"><h4>Presentation</h4><p>' + escapeHtml(p.presentation || 'N/A') + '</p></div>' +
                '<div class="view-detail"><h4>Summary</h4><p>' + escapeHtml(p.summary || 'N/A') + '</p></div>';
            $('#viewContent').html(c);
            viewModal.show();
        }, 'json').fail(function() { hideLoading(); alert('Error loading plan.'); });
    }

    function editPlan(id) {
        showLoading('Loading...');
        $.get(window.location.pathname + '?action=get_plan_for_edit', { plan_id: id }, function(r) {
            hideLoading();
            if (r.error) { alert(r.error); return; }
            const p = r.plan;
            $('#edit_plan_id').val(p.id);
            $('#edit_class_id').val(p.class_id);
            $('#edit_subject_id').val(p.subject_id);
            $('#edit_term_id').val(p.term_id);
            $('#edit_week').val(p.week);
            $('#edit_strand').val(p.strand || '');
            $('#edit_sub_strand').val(p.sub_strand || '');
            $('#edit_content_standard').val(p.content_standard || '');
            $('#edit_indicator').val(p.indicator || '');
            $('#edit_objectives').val(p.objectives || '');
            $('#edit_reference').val(p.reference || '');
            $('#edit_resources').val(p.resources || '');
            $('#edit_keywords').val(p.keywords || '');
            $('#edit_starter').val(p.starter || '');
            $('#edit_presentation').val(p.presentation || '');
            $('#edit_summary').val(p.summary || '');
            $('#edit_source_resource_ids').val(p.source_resource_ids || '');
            $('#edit_ai_generated').val(p.ai_generated || 0);
            $('.ai-suggestion-box, .ai-fallback-box').addClass('d-none');
            editModal.show();
        }, 'json').fail(function() { hideLoading(); alert('Error loading plan.'); });
    }

    function deletePlan(id) {
        $('#delete_plan_id').val(id);
        deleteModal.show();
    }

    $(document).ready(function() {
        const defaultTerm = $('#term_id').val();
        if (defaultTerm) loadSchedules(defaultTerm);

        $('[data-bs-toggle="tooltip"]').tooltip();
        setTimeout(() => { $('.alert:not(#toastContainer *)').alert('close'); }, 6000);

        const presentationTextarea = document.getElementById('presentation');
        const counter = document.getElementById('presentation-counter');
        function updateCounter() {
            if (!presentationTextarea || !counter) return;
            const length = presentationTextarea.value.length;
            counter.textContent = `${length}/20000 characters`;
            counter.classList.toggle('warning', length > 18000);
        }
        if (presentationTextarea) {
            presentationTextarea.addEventListener('input', updateCounter);
            updateCounter();
        }

        hideLoading();
    });

    window.addEventListener('beforeunload', function(e) {
        const hasContent = $('#objectives').val() || $('#presentation').val();
        const editOpen = document.getElementById('editModal').classList.contains('show');
        if (hasContent && !editOpen) {
            e.preventDefault();
            e.returnValue = 'You have unsaved changes.';
            return e.returnValue;
        }
    });
    </script>
</body>
</html>
<?php
mysqli_close($conn);
ob_end_flush();
?>