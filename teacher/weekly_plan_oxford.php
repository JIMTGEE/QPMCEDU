<?php
ob_start();

// FIX: session.gc_maxlifetime and session_set_cookie_params() are session-related
// ini settings — PHP refuses to apply them (and emits a warning) once a session is
// already active. They must run BEFORE session_start(), not after. Previously these
// were placed after session_start(), which silently failed to extend the session
// lifetime/cookie and logged: "Session ini settings cannot be changed when a session
// is active" / "session_set_cookie_params(): Session cookie parameters cannot be
// changed when a session is active".
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
// PHP empties $_POST and $_FILES entirely and sets no error flag either script
// can inspect via $_FILES[...]['error']. Without this check, such a request
// silently falls through this entire file and ends up rendering the full HTML
// page back as the "AJAX" response — which the front-end can't parse as JSON,
// surfacing to the user as a vague "network error during upload."
// (Note: post_max_size/upload_max_filesize can only be raised in php.ini,
// a .user.ini, or the web server config — ini_set() here runs too late,
// since the body has already been parsed and rejected by the time this
// script starts executing.)
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
    // $_POST/$_FILES came back empty for some other reason (e.g. no Content-Length
    // header, or a genuinely empty submission) — let normal handling continue;
    // downstream checks (isset($_POST['ajax_action']), UPLOAD_ERR_OK, etc.) will
    // report their own specific errors.
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

// FIX: force the connection charset to utf8mb4 so text in any language (Twi, Ewe,
// Ga, French, Arabic, Chinese, emoji, etc.) round-trips correctly. Without this,
// PHP/mysqli falls back to whatever the server default is (often latin1), which
// silently corrupts non-Latin characters on save ("mojibake") even though the
// tables themselves may already be utf8mb4. This must run on every connection,
// including the reconnect path above.
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

// Class name mapping
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
// SAFE-SAVE HELPERS
// =====================================================================================
// The teacher_notes columns (especially `keywords`) were originally sized for short,
// hand-typed text. AI-generated content (bulleted keyword lists, long presentations,
// etc.) can legitimately run much longer, which previously surfaced as a raw MySQL
// error: "Data too long for column 'keywords' at row 1". Two things fix this together:
//   1) An admin should widen the affected columns to TEXT/MEDIUMTEXT — see the
//      accompanying widen_teacher_notes_columns.sql migration.
//   2) In the meantime (and as permanent defense-in-depth against any single
//      pathological AI response), every field is safely truncated to a generous
//      character budget — using mb_substr so multi-byte characters (needed for
//      Twi, Ewe, Ga, French accents, etc.) are never split mid-character — before
//      it ever reaches the database, and any remaining DB error is translated into
//      a message that names the field instead of leaking raw SQL text.

// Character budgets per field family. These map to: SHORT ~ VARCHAR/TEXT single-line
// or short multi-line fields, LONG ~ the main lesson body. Generous but bounded, so
// a single AI hiccup can never produce a multi-megabyte insert.
define('SAFE_LEN_SHORT', 8000);
define('SAFE_LEN_LONG', 20000);

/**
 * Truncate text to a safe character budget for a DB column, preserving whole
 * multi-byte characters (important for non-Latin scripts and accented text).
 */
function dbSafeText($text, $maxChars = SAFE_LEN_SHORT) {
    $text = (string)$text;
    if (mb_strlen($text, 'UTF-8') <= $maxChars) {
        return $text;
    }
    return mb_substr($text, 0, $maxChars, 'UTF-8') . "\n…[truncated — please shorten this field]";
}

/**
 * Turns a raw mysqli "Data too long for column 'X' at row Y" error into a
 * clear, actionable message for the teacher, instead of leaking SQL internals.
 */
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
// db.php) rather than hardcoding it here, and rotate this key — it's easy to leak a
// hardcoded key by accident (e.g. pasting a file like this one somewhere).
$config = include $_SERVER['DOCUMENT_ROOT'] . '/config/gemini.php';
$api_key = $config['api_key'];

// FIX (reliability overhaul — see callGeminiWithParts() below): Google periodically
// repoints "-latest" aliases and retires older dated models (Gemini 2.0 Flash and
// 2.0 Flash-Lite were both shut down on 2026-06-01; anything from the 1.x family
// already returns a hard 404). "gemini-flash-latest" now resolves to a Gemini 3.x
// release, so the fallback list is ordered newest-first with 2.5-flash kept only as
// a last-resort safety net — and isModernGeminiModel() below detects the 3.x request
// shape correctly instead of assuming only literal "gemini-3..." names need it.
$gemini_models = [
    'gemini-flash-latest',   // official "current" Flash alias — recommended default
    'gemini-3.8-flash',
    'gemini-3.7-flash',
    'gemini-3.6-flash',
    'gemini-3.5-flash',
    'gemini-2.5-flash',      // legacy fallback only, in case every 3.x route is down
];

// =====================================================================================
// SOURCE / RESOURCE UPLOAD CONFIGURATION
// =====================================================================================
$resource_upload_dir = $_SERVER['DOCUMENT_ROOT'] . '/uploads/resources/' . $teacher_id . '/';
if (!is_dir($resource_upload_dir)) {
    @mkdir($resource_upload_dir, 0755, true);
}
$resource_max_bytes = 35 * 1024 * 1024; // 35MB — comfortable headroom under Gemini's 50MB PDF inline cap
// The real ceiling is whichever is smallest: our own app limit, or what php.ini
// will actually let through. Surfacing the true number (rather than always
// claiming a hardcoded figure) avoids sending uploads that php.ini will silently reject.
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
    // Preserve paragraph breaks, then strip remaining markup.
    $xml = str_replace(['</w:p>', '<w:tab/>'], ["\n", "\t"], $xml);
    $text = strip_tags($xml);
    return trim(preg_replace('/[ \t]+\n/', "\n", $text));
}

/**
 * Given a stored resource row, build the Gemini "part" that best represents it:
 * - PDFs and images are sent as inline_data so Gemini reads them natively
 *   (layout, diagrams, scanned pages, etc.) rather than relying on OCR text.
 * - DOCX/TXT are sent as plain text (already extracted at upload time).
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

    // DOCX / TXT / fallback: use previously extracted text.
    $text = trim((string)($resource['extracted_text'] ?? ''));
    if ($text === '') {
        return null;
    }
    // Guard against extremely long documents blowing the prompt budget.
    if (mb_strlen($text) > 60000) {
        $text = mb_substr($text, 0, 60000) . "\n...[truncated]";
    }
    return ['label_text' => ['text' => $label . $text]];
}

/**
 * FIX: whether a model id needs the Gemini 3.x request shape — thinkingConfig.
 * thinkingLevel, and NO temperature/topP/topK (3.x ignores them and Google's docs
 * say not to set them) — versus the older thinkingBudget/temperature shape that
 * only genuinely legacy dated models (gemini-2.5-flash and earlier) still expect.
 *
 * This used to be a literal `strpos($model, 'gemini-3') === 0` check, which broke
 * silently for the "gemini-flash-latest" alias: that alias doesn't start with
 * "gemini-3" as a string, but Google has it pointing at a 3.x release, so every
 * call made through it was being sent the legacy thinkingBudget/temperature shape.
 * Mixing that legacy shape into a 3.x request is what was producing sporadic
 * rejected requests — exactly the "works for a few, then fails" pattern reported.
 * "-latest" and "-preview" aliases always resolve to Google's current-generation
 * model, so they're treated as modern here regardless of their literal name.
 */
function isModernGeminiModel($model) {
    return strpos($model, 'gemini-3') === 0
        || strpos($model, '-latest') !== false
        || strpos($model, '-preview') !== false;
}

/**
 * Builds the per-model generationConfig.
 *
 * gemini-3.x models (and the "-latest"/"-preview" aliases, which resolve to a 3.x
 * release) use thinkingConfig.thinkingLevel and must NOT also receive the legacy
 * thinkingBudget/temperature/topP params — Google's API rejects thinkingLevel and
 * thinkingBudget together, and 3.x otherwise just ignores temperature/topP.
 * $thinkingLevel lets callers trade speed for depth per-call: 'minimal' for a
 * short single-field suggestion, 'low' for the full multi-document lesson plan
 * (enough reasoning to ground it in the attached sources without the extra
 * latency of 'medium'/'high').
 *
 * Legacy 2.5-flash (kept only as a last-resort fallback) still honors
 * temperature/topP and disables thinking via thinkingBudget = 0.
 */
function buildGenerationConfigForModel($model, $responseSchema, $maxOutputTokens = 8192, $thinkingLevel = 'low') {
    $config = ['maxOutputTokens' => $maxOutputTokens];
    if ($responseSchema) {
        $config['responseMimeType'] = 'application/json';
        $config['responseSchema'] = $responseSchema;
    }
    if (isModernGeminiModel($model)) {
        $config['thinkingConfig'] = ['thinkingLevel' => $thinkingLevel];
    } else {
        // legacy 2.5-flash fallback
        $config['temperature'] = 0.4;
        $config['topP'] = 0.95;
        $config['thinkingConfig'] = ['thinkingBudget' => 0]; // disables thinking on Flash/Lite
    }
    return $config;
}

/**
 * Turns a failed Gemini call into a short, honest, non-technical reason so the
 * teacher isn't just told "please try again later" every single time regardless
 * of what actually went wrong.
 */
function summarizeGeminiFailure($lastHttpCode, $lastErrorDetail) {
    if ($lastHttpCode === 429) {
        return "The AI service is getting a lot of requests right now. Please wait a minute and try again.";
    }
    if (in_array($lastHttpCode, [500, 502, 503, 504], true)) {
        return "The AI service is temporarily unavailable. Please try again in a moment.";
    }
    if ($lastHttpCode === 400) {
        return "The AI service rejected the request" . ($lastErrorDetail ? " ({$lastErrorDetail})" : "") . ". Try again, or use fewer/smaller source documents.";
    }
    if (in_array($lastHttpCode, [401, 403], true)) {
        return "The AI service rejected the request due to an authentication problem. Please contact an administrator.";
    }
    if ($lastHttpCode === 404) {
        return "The AI model is currently unavailable. Please try again shortly.";
    }
    return "AI service did not respond after multiple attempts. Please try again later.";
}

/**
 * Calls Gemini with arbitrary "parts" (text and/or inline_data files) and,
 * optionally, a strict JSON responseSchema so the model must return
 * structured data instead of free text we'd have to parse with regex.
 *
 * FIX (reliability overhaul): this used to retry every model twice with a fixed
 * short delay regardless of *why* it failed, which was both slow (burning the
 * request's time budget retrying failures — like a bad param — that could never
 * succeed) and fragile (a single 25s timeout for every request, even ones with
 * multi-megabyte inline PDFs/images attached, which genuinely need longer). Now:
 *   - the per-attempt timeout scales up when the request actually carries inline
 *     file data, since those calls take Gemini longer to read;
 *   - only 429 (rate limited) and 5xx (transient server errors) are retried —
 *     honoring the Retry-After header when Google sends one — while 400/404/401/403
 *     fail fast to the next model instead of wasting two retries on something that
 *     will never succeed;
 *   - a finishReason of MAX_TOKENS (the response got cut off mid-JSON) is treated
 *     as a failure rather than a false "success" that would later fail to parse
 *     with a confusing error, since $maxOutputTokens is now called with a much
 *     larger budget (8192) specifically to avoid this for the full multi-field plan;
 *   - a hard overall wall-clock budget keeps the whole function from running long
 *     enough to hit PHP's execution-time limit or the browser's own AJAX timeout.
 */
function callGeminiWithParts($parts, $api_key, $models, $responseSchema = null, $maxOutputTokens = 8192, $thinkingLevel = 'low', $overallTimeBudgetSeconds = 100) {
    $hasInlineFiles = false;
    foreach ($parts as $part) {
        if (isset($part['inline_data'])) { $hasInlineFiles = true; break; }
    }
    // Document-heavy requests (attached PDFs/images) genuinely take Gemini longer
    // to read than a short text-only prompt — a single fixed 25s timeout for both
    // was cutting off document-grounded generations before Gemini could finish.
    $curlTimeout = $hasInlineFiles ? 55 : 25;

    $deadline = microtime(true) + $overallTimeBudgetSeconds;
    $lastHttpCode = 0;
    $lastErrorDetail = '';

    foreach ($models as $model) {
        if (microtime(true) > $deadline) break;

        $maxAttemptsThisModel = 3; // only reached for 429/5xx — see below
        for ($attempt = 1; $attempt <= $maxAttemptsThisModel; $attempt++) {
            if (microtime(true) > $deadline) break 2;

            $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent";
            $data = [
                'contents' => [[ 'parts' => $parts ]],
                'generationConfig' => buildGenerationConfigForModel($model, $responseSchema, $maxOutputTokens, $thinkingLevel),
                'safetySettings' => [
                    ['category' => 'HARM_CATEGORY_HARASSMENT',  'threshold' => 'BLOCK_MEDIUM_AND_ABOVE'],
                    ['category' => 'HARM_CATEGORY_HATE_SPEECH', 'threshold' => 'BLOCK_MEDIUM_AND_ABOVE'],
                ],
            ];

            $responseHeaders = [];
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_HTTPHEADER     => [
                    'Content-Type: application/json',
                    'X-goog-api-key: ' . $api_key,
                ],
                CURLOPT_POSTFIELDS     => json_encode($data),
                CURLOPT_TIMEOUT        => $curlTimeout,
                CURLOPT_CONNECTTIMEOUT => 15,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS      => 3,
                CURLOPT_HEADERFUNCTION => function ($curlHandle, $headerLine) use (&$responseHeaders) {
                    $bits = explode(':', $headerLine, 2);
                    if (count($bits) === 2) {
                        $responseHeaders[strtolower(trim($bits[0]))] = trim($bits[1]);
                    }
                    return strlen($headerLine);
                },
            ]);

            $response   = curl_exec($ch);
            $http_code  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curl_error = curl_error($ch);
            curl_close($ch);
            $lastHttpCode = $http_code;

            error_log("Gemini(parts) model={$model} attempt={$attempt}/{$maxAttemptsThisModel} HTTP={$http_code}" . ($curl_error ? " curl_error={$curl_error}" : ''));

            if ($http_code === 200 && $response) {
                $result = json_decode($response, true);
                $finishReason = $result['candidates'][0]['finishReason'] ?? null;
                $text = $result['candidates'][0]['content']['parts'][0]['text'] ?? '';
                $text = trim((string)$text);

                if ($text !== '' && $finishReason !== 'MAX_TOKENS') {
                    return ['success' => true, 'text' => $text];
                }
                if ($finishReason === 'MAX_TOKENS') {
                    // Cut off mid-response — would otherwise fail JSON parsing
                    // downstream with a confusing "could not be understood" error.
                    $lastErrorDetail = 'response was cut off before it finished';
                    error_log("Gemini {$model}: finishReason=MAX_TOKENS, treating as failure.");
                } elseif ($finishReason && $finishReason !== 'STOP') {
                    $lastErrorDetail = "model stopped early ({$finishReason})";
                    error_log("Gemini {$model}: finishReason={$finishReason}");
                }
                // 200 but nothing usable — no point retrying the same model.
                break;
            }

            if ($response) {
                $errBody = json_decode($response, true);
                $lastErrorDetail = $errBody['error']['message'] ?? '';
                error_log("Gemini error body ({$model}): " . substr($response, 0, 2000));
            } elseif ($curl_error) {
                $lastErrorDetail = $curl_error;
                error_log("Gemini curl error ({$model}): {$curl_error}");
            }

            if ($http_code === 429) {
                // Rate limited — genuinely transient, so retry this model. Honor
                // Retry-After if Google sent one, otherwise back off progressively.
                $retryAfter = isset($responseHeaders['retry-after']) ? (float)$responseHeaders['retry-after'] : null;
                $wait = $retryAfter !== null ? min(max($retryAfter, 0.5), 8) : (1.5 * $attempt);
                if (microtime(true) + $wait > $deadline) break 2;
                usleep((int)($wait * 1000000));
                continue;
            }
            if (in_array($http_code, [500, 502, 503, 504], true)) {
                // Transient server-side failure — brief backoff, retry this model.
                $wait = 1.2 * $attempt;
                if (microtime(true) + $wait > $deadline) break 2;
                usleep((int)($wait * 1000000));
                continue;
            }
            // 400 (bad request/safety block), 401/403 (auth), 404 (model
            // unavailable) — retrying the same model won't change the outcome,
            // so move straight on to the next model in the fallback list.
            break;
        }
    }

    return ['success' => false, 'error' => summarizeGeminiFailure($lastHttpCode, $lastErrorDetail)];
}

/**
 * Legacy single-string-prompt caller, kept for the existing per-field
 * "AI suggestion" buttons (objectives / starter / resources / keywords / summary).
 * These are single, focused fields — 'minimal' thinking and a smaller token
 * budget keep them fast without sacrificing the (low) complexity they need.
 */
function callGeminiAI($prompt, $api_key, $models) {
    return callGeminiWithParts([['text' => $prompt]], $api_key, $models, null, 2048, 'minimal', 45);
}

// The strict JSON schema the full-plan generator must return.
function completePlanSchema() {
    return [
        'type' => 'OBJECT',
        'properties' => [
            'unit'             => ['type' => 'STRING'],
            'topic'            => ['type' => 'STRING'],
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
        'required' => ['unit','topic','objectives','reference','resources','keywords','starter','presentation','summary'],
    ];
}

/**
 * Builds the full instruction prompt for a complete, ready-to-use lesson plan,
 * meticulously grounded in any attached source documents.
 */
function buildCompletePlanInstruction($context, $hasSources) {
    $week = (int)($context['week'] ?? 0);
    $class_name = $context['class_name'] ?? 'the class';
    $subject = $context['subject_name'] ?? '';
    $topic = trim($context['topic'] ?? '');
    $unit = trim($context['unit'] ?? '');
    $day = $context['day_of_week'] ?? '';
    $duration = $context['duration'] ?? '40 minutes';
    $language = trim($context['language'] ?? '');

    $topicLine = $topic
        ? "The teacher has specified the Topic as \"{$topic}\"."
        : "The teacher has NOT specified a Topic — you must infer the single most appropriate Topic for Week {$week} from the source material provided (or, if no source material is provided, from standard Oxford International Primary Curriculum scope and sequence for {$class_name} / {$subject}).";
    $unitLine = $unit
        ? "The teacher has specified the Unit as \"{$unit}\"."
        : "The teacher has NOT specified a Unit — infer a suitable Unit title consistent with the Topic you choose.";

    $sourceInstruction = $hasSources
        ? "You have been given one or more SOURCE DOCUMENTs below (textbook pages, syllabus excerpts, worksheets, notes, images, etc), which may be written in any language. Read every one of them carefully and in full before writing anything — recognise the language(s) they are written in and do not misread or mistranslate any of the content. Ground the Objectives, Presentation content, Keywords, and Resources firmly in what these documents actually say — do not invent facts, numbers, vocabulary, or examples that are not supported by the sources or well-established general curriculum knowledge. Where the sources give specific page numbers, exercises, diagrams, or terminology, reuse them precisely."
        : "No source documents were provided for this lesson. Base the plan on standard Oxford International Primary Curriculum expectations for this class/subject/week, and on materials realistically available in a well-resourced Ghanaian classroom.";

    $languageInstruction = $language !== ''
        ? "Write the ENTIRE lesson plan (every field) in {$language}, using correct spelling, grammar, and natural phrasing for that language. If any curriculum-specific English terms have no natural equivalent, you may keep the English term in parentheses."
        : "Write the lesson plan in the same language as the source documents provided, if any (matching their spelling and terminology accurately); otherwise write it in clear, natural English.";

    return <<<PROMPT
You are an expert, meticulous Oxford International Primary Curriculum teacher-trainer working in Ghana. You are producing a complete, ready-to-teach weekly lesson plan for a class teacher, who will read it once and use it directly in the classroom — it must be accurate, specific, and usable with zero further editing required (though the teacher may still tweak it).

CONTEXT:
- Class: {$class_name}
- Subject: {$subject}
- Week: {$week} of the term
- Scheduled: {$day}, {$duration}
- {$topicLine}
- {$unitLine}

{$sourceInstruction}

LANGUAGE: {$languageInstruction} Read carefully regardless of language, and do not mix languages within a field unless the teacher's source material itself mixes them.

REQUIREMENTS FOR EACH FIELD:
- unit: short unit title.
- topic: short, specific lesson topic (not the same as the unit).
- strand / sub_strand / content_standard / indicator: concise curriculum-alignment lines (strand = broad curriculum area, sub_strand = narrower area, content_standard = the standard being addressed, indicator = the specific measurable indicator for this lesson). If the source material states these explicitly, use its exact wording; otherwise write the most reasonable Oxford-aligned equivalent.
- objectives: exactly 3-4 numbered SMART (Specific, Measurable, Achievable, Relevant, Time-bound) learning objectives, each starting "By the end of the lesson, learners will be able to...".
- reference: the specific textbook/page/source being used — cite the source document title(s) given to you if any, plus page/section if identifiable; otherwise a plausible Oxford International Primary reference.
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

    // NEW: list this teacher's uploaded sources, optionally filtered by subject/class
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
                        WHERE tn.id = ? AND tn.teacher_id = ? AND tn.format = 'oxford'";
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
            $plan_sql = "SELECT * FROM teacher_notes WHERE id = ? AND teacher_id = ? AND format = 'oxford'";
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

    // AI suggestion (single field — unchanged behaviour, kept for the per-field AI buttons)
    if ($_GET['action'] === 'ai_suggestion') {
        set_time_limit(120);
        $type = $_GET['type'] ?? '';
        $context = json_decode($_GET['context'] ?? '{}', true);
        $response = ['suggestion' => '', 'error' => '', 'notice' => ''];

        $prompts = [
            'objectives' => "As an experienced Oxford curriculum teacher in Ghana, create 3-4 SMART (Specific, Measurable, Achievable, Relevant, Time-bound) learning objectives for a lesson on '{$context['topic']}' for {$context['class_name']} (Unit: {$context['unit']}). Focus on the Oxford International Primary curriculum standards. Format as a numbered list with clear, concise objectives.",
            'starter' => "Create an engaging 5-minute starter activity for an Oxford curriculum lesson on '{$context['topic']}' (Unit: {$context['unit']}) for {$context['class_name']}. The activity should be practical, activate prior knowledge, use minimal resources, and be suitable for a typical Ghanaian classroom. Include step-by-step instructions.",
            'presentation' => "Create a detailed lesson presentation outline for an Oxford curriculum lesson on '{$context['topic']}' (Unit: {$context['unit']}) for {$context['class_name']}. Structure it as: 1) Introduction (5 mins), 2) Main Teaching Activity with clear teacher instructions and student activities (20 mins), 3) Group/Pair Work with differentiation for different ability levels (10 mins), 4) Plenary/Assessment (5 mins). Include specific questions to ask students and expected answers.",
            'resources' => "List specific teaching and learning resources needed for an Oxford curriculum lesson on '{$context['topic']}' (Unit: {$context['unit']}) for {$context['class_name']}. Include: 1) Teacher resources, 2) Student resources, 3) Digital resources (if applicable), 4) Locally available materials. Focus on resources commonly available in Ghanaian schools.",
            'keywords' => "List 5-8 key vocabulary words with simple, child-friendly definitions for an Oxford curriculum lesson on '{$context['topic']}' (Unit: {$context['unit']}) for {$context['class_name']}. Format as 'Word: Simple definition'. Include subject-specific terminology from the Oxford International Primary curriculum.",
            'summary' => "Create a concise lesson summary (3-4 sentences) for an Oxford curriculum lesson on '{$context['topic']}' (Unit: {$context['unit']}) for {$context['class_name']}. Include: 1) Key concepts learned, 2) Skills developed, 3) Real-life applications in Ghana, 4) Next lesson connection.",
        ];

        $prompt = $prompts[$type] ?? '';
        $language = trim($context['language'] ?? '');
        if ($prompt !== '' && $language !== '') {
            $prompt .= " Write your entire response in {$language}, with correct spelling and natural phrasing for that language.";
        }
        if (empty($prompt)) {
            $response['error'] = 'Invalid suggestion type.';
        } elseif (empty($context['topic'])) {
            $response['error'] = 'Please enter a topic first to get AI suggestions.';
        } elseif (empty($context['unit'])) {
            $response['error'] = 'Please enter a unit first to get AI suggestions.';
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

    // NEW: refresh the "My Oxford Lesson Plans" table without a full page reload.
    if ($_GET['action'] === 'list_plans') {
        $response = ['rows' => []];
        $plans_sql_local = "SELECT tn.id, tn.week, tn.topic, tn.unit, c.class_name, s.subject_name,
                      ac.year AS academic_year, ac.term_name, tn.created_at, tn.ai_generated
                      FROM teacher_notes tn
                      JOIN classes c ON tn.class_id = c.id
                      LEFT JOIN subjects s ON tn.subject_id = s.id
                      JOIN academic_calendar ac ON tn.term_id = ac.id
                      WHERE tn.teacher_id = ? AND tn.format = 'oxford'
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
                'topic' => $row['topic'],
                'unit' => $row['unit'],
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
        // Belt-and-braces: also reject anything the server's own ini limits
        // wouldn't actually have allowed through, in case app/server limits diverge.
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

        // Extract text where we can (DOCX/TXT). PDFs/images are read natively by Gemini later.
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
        $relative_web_path = str_replace($_SERVER['DOCUMENT_ROOT'], '', $dest_path);

        // FIX: mime_type is now inserted atomically as part of the main INSERT,
        // instead of via a separate best-effort UPDATE query that silently failed
        // when the mime_type column didn't exist (which broke PDFs/images downstream,
        // since resourceToGeminiPart() and list_resources both depend on it).
        $insert_sql = "INSERT INTO learning_resources
            (title, description, resource_type, mime_type, file_path, extracted_text, file_size, subject_id, class_id, teacher_id, term_id, week, academic_year, access_level, tags, is_approved, created_by, created_at)
            VALUES (?, ?, 'document', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'private', ?, 1, ?, NOW())";
        $academic_year = $_POST['academic_year'] ?? '';
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
        set_time_limit(180);

        $week = filter_input(INPUT_POST, 'week', FILTER_VALIDATE_INT);
        $class_name = trim($_POST['class_name'] ?? '');
        $subject_name = trim($_POST['subject_name'] ?? '');
        $day_of_week = trim($_POST['day_of_week'] ?? '');
        $duration = trim($_POST['duration'] ?? '40 minutes');
        $topic = trim(strip_tags($_POST['topic'] ?? ''));
        $unit = trim(strip_tags($_POST['unit'] ?? ''));
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
            'topic' => $topic,
            'unit' => $unit,
            'day_of_week' => $day_of_week,
            'duration' => $duration,
            'language' => $language,
        ];

        $parts = [['text' => buildCompletePlanInstruction($context, !empty($resources))]];
        foreach ($resources as $resource) {
            $piece = resourceToGeminiPart($resource);
            if ($piece === null) continue;
            $parts[] = $piece['label_text'];
            if (isset($piece['file_part'])) $parts[] = $piece['file_part'];
        }

        // FIX: maxOutputTokens raised from 4096 to 8192 — the full multi-field JSON
        // schema (objectives + reference + resources + keywords + starter +
        // presentation + summary + 4 curriculum-alignment fields) could genuinely
        // exceed 4096 tokens for a detailed lesson, which was silently truncating
        // the JSON mid-field; callGeminiWithParts() now also treats a MAX_TOKENS
        // finish as a real failure instead of returning the truncated text as a
        // false "success". thinkingLevel 'low' keeps this fast while still reading
        // the attached source documents carefully; overall time budget 140s leaves
        // headroom under the set_time_limit(180) above and the client's AJAX timeout.
        $ai_result = callGeminiWithParts($parts, $gemini_api_key, $gemini_models, completePlanSchema(), 8192, 'low', 140);

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

    // ---- NEW: one-click AJAX save (create or update), used by the Save/Update buttons ----
    if ($ajax_action === 'save_plan') {
        $action = $_POST['action'] ?? 'submit';
        $plan_id = filter_input(INPUT_POST, 'plan_id', FILTER_VALIDATE_INT) ?: 0;
        $week = filter_input(INPUT_POST, 'week', FILTER_VALIDATE_INT);
        $class_id = filter_input(INPUT_POST, 'class_id', FILTER_VALIDATE_INT);
        $term_id = filter_input(INPUT_POST, 'term_id', FILTER_VALIDATE_INT);
        $subject_id = filter_input(INPUT_POST, 'subject_id', FILTER_VALIDATE_INT) ?? 0;

        // Every text field is safely truncated (multi-byte-aware) before it ever
        // reaches the database — this is what prevents "Data too long" crashes
        // regardless of how verbose the AI-generated content is.
        $unit = dbSafeText(trim(strip_tags($_POST['unit'] ?? '')), SAFE_LEN_SHORT);
        $topic = dbSafeText(trim(strip_tags($_POST['topic'] ?? '')), SAFE_LEN_SHORT);
        $objectives = dbSafeText(trim(strip_tags($_POST['objectives'] ?? '')), SAFE_LEN_SHORT);
        $reference = dbSafeText(trim(strip_tags($_POST['reference'] ?? '')), SAFE_LEN_SHORT);
        $resources = dbSafeText(trim(strip_tags($_POST['resources'] ?? '')), SAFE_LEN_SHORT);
        $keywords = dbSafeText(trim(strip_tags($_POST['keywords'] ?? '')), SAFE_LEN_SHORT);
        $starter = dbSafeText(trim(strip_tags($_POST['starter'] ?? '')), SAFE_LEN_SHORT);
        $presentation = dbSafeText(trim(strip_tags($_POST['presentation'] ?? '', '<p><br><strong><em><ul><ol><li>')), SAFE_LEN_LONG);
        $summary = dbSafeText(trim(strip_tags($_POST['summary'] ?? '')), SAFE_LEN_SHORT);
        $strand = dbSafeText(trim(strip_tags($_POST['strand'] ?? '')), SAFE_LEN_SHORT);
        $sub_strand = dbSafeText(trim(strip_tags($_POST['sub_strand'] ?? '')), SAFE_LEN_SHORT);
        $content_standard = dbSafeText(trim(strip_tags($_POST['content_standard'] ?? '')), SAFE_LEN_SHORT);
        $indicator = dbSafeText(trim(strip_tags($_POST['indicator'] ?? '')), SAFE_LEN_SHORT);
        $source_resource_ids = trim(strip_tags($_POST['source_resource_ids'] ?? ''));
        $ai_generated = !empty($_POST['ai_generated']) ? 1 : 0;

        $save_errors = [];
        if (!$week || $week < 1 || $week > 16) $save_errors[] = "Invalid week number (1-16 required).";
        if (!$class_id || $class_id <= 0) $save_errors[] = "Please select a schedule to set the class.";
        if (!$term_id || $term_id <= 0) $save_errors[] = "Please select a term.";
        if (empty($unit)) $save_errors[] = "Unit is required.";
        if (empty($topic)) $save_errors[] = "Topic is required.";
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
                $update_sql = "UPDATE teacher_notes SET
                    week = ?, class_id = ?, term_id = ?, subject_id = ?,
                    unit = ?, topic = ?, objectives = ?, reference = ?,
                    resources = ?, keywords = ?, starter = ?, presentation = ?,
                    summary = ?, strand = ?, sub_strand = ?, content_standard = ?,
                    indicator = ?, source_resource_ids = ?, ai_generated = ?, updated_at = NOW()
                    WHERE id = ? AND teacher_id = ? AND format = 'oxford'";
                $update_stmt = $conn->prepare($update_sql);
                $update_stmt->bind_param(
                    "iiiissssssssssssssiii",
                    $week, $class_id, $term_id, $subject_id,
                    $unit, $topic, $objectives, $reference,
                    $resources, $keywords, $starter, $presentation,
                    $summary, $strand, $sub_strand, $content_standard,
                    $indicator, $source_resource_ids, $ai_generated,
                    $plan_id, $teacher_id
                );
                if (!$update_stmt->execute()) {
                    throw new Exception($update_stmt->error);
                }
                $update_stmt->close();
                $conn->commit();
                echo json_encode(['success' => true, 'plan_id' => $plan_id, 'message' => 'Oxford lesson plan updated successfully!']);
            } else {
                $insert_sql = "INSERT INTO teacher_notes (
                    teacher_id, week, class_id, term_id, subject_id, unit, topic,
                    objectives, reference, resources, keywords, starter, presentation,
                    summary, format, strand, sub_strand, content_standard, indicator,
                    source_resource_ids, ai_generated, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'oxford', ?, ?, ?, ?, ?, ?, NOW())";
                $insert_stmt = $conn->prepare($insert_sql);
                $insert_stmt->bind_param(
                    "iiiiissssssssssssssi",
                    $teacher_id, $week, $class_id, $term_id, $subject_id,
                    $unit, $topic, $objectives, $reference,
                    $resources, $keywords, $starter, $presentation,
                    $summary, $strand, $sub_strand, $content_standard, $indicator,
                    $source_resource_ids, $ai_generated
                );
                if (!$insert_stmt->execute()) {
                    throw new Exception($insert_stmt->error);
                }
                $new_id = $insert_stmt->insert_id;
                $insert_stmt->close();
                $conn->commit();
                echo json_encode(['success' => true, 'plan_id' => $new_id, 'message' => 'Oxford lesson plan created successfully!']);
            }
        } catch (Exception $e) {
            $conn->rollback();
            error_log("save_plan exception: " . $e->getMessage());
            echo json_encode(['success' => false, 'error' => friendlyDbError($e->getMessage())]);
        }
        exit();
    }

    // ---- NEW: AJAX delete, matching the rest of the one-click save/update flow ----
    if ($ajax_action === 'delete_plan') {
        $plan_id = filter_input(INPUT_POST, 'plan_id', FILTER_VALIDATE_INT);
        if (!$plan_id || $plan_id <= 0) {
            echo json_encode(['success' => false, 'error' => 'Invalid lesson plan ID.']);
            exit();
        }
        $conn->begin_transaction();
        try {
            $check_stmt = $conn->prepare("SELECT id FROM teacher_notes WHERE id = ? AND teacher_id = ? AND format = 'oxford'");
            $check_stmt->bind_param("ii", $plan_id, $teacher_id);
            $check_stmt->execute();
            if ($check_stmt->get_result()->num_rows === 0) {
                $check_stmt->close();
                $conn->rollback();
                echo json_encode(['success' => false, 'error' => 'Lesson plan not found or unauthorized.']);
                exit();
            }
            $check_stmt->close();
            $delete_stmt = $conn->prepare("DELETE FROM teacher_notes WHERE id = ? AND teacher_id = ? AND format = 'oxford'");
            $delete_stmt->bind_param("ii", $plan_id, $teacher_id);
            if (!$delete_stmt->execute()) {
                throw new Exception($delete_stmt->error);
            }
            $delete_stmt->close();
            $conn->commit();
            echo json_encode(['success' => true, 'message' => 'Oxford lesson plan deleted successfully!']);
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
    $topic = htmlspecialchars($context['topic'] ?? 'your topic');
    $fallbacks = [
        'objectives' => "1. Understand the key concepts of {$topic}\n2. Apply knowledge of {$topic} to solve problems\n3. Analyze and evaluate information related to {$topic}\n4. Create/design solutions using understanding of {$topic}",
        'starter' => "1. Begin with a quick review of prior knowledge (2 mins)\n2. Show a relevant image/object and ask students: 'What do you notice?' (2 mins)\n3. Students share their observations with a partner (1 min)\n4. Briefly introduce today's topic and learning objectives",
        'presentation' => "Introduction (5 mins):\n- Review prior learning\n- State learning objectives\n- Engage students with a thought-provoking question\n\nMain Activity (20 mins):\n- Teacher demonstration/modeling\n- Guided practice with whole class\n- Independent/small group work with differentiated tasks\n- Circulate and provide support\n\nGroup Work (10 mins):\n- Mixed-ability groups work on application tasks\n- Higher ability: Extension questions\n- Lower ability: Scaffolded support\n\nPlenary (5 mins):\n- Groups share findings\n- Address misconceptions\n- Summarize key points",
        'resources' => "Teacher Resources:\n- Whiteboard/chalkboard and markers/chalk\n- Teacher's guide/reference book\n- Visual aids (charts, diagrams)\n\nStudent Resources:\n- Exercise books/pencils\n- Worksheets (if prepared)\n- Manipulatives or hands-on materials\n\nLocal Materials:\n- Items from the local environment relevant to the topic\n- Recycled materials for creative activities",
        'keywords' => "Key concepts for {$topic}:\n\n1. Main concept: The primary idea being taught\n2. Related term: Connected vocabulary\n3. Process word: Action or method term\n4. Description word: Adjective describing features\n5. Application term: How the concept is used\n6. Evaluation word: Term for assessing understanding",
        'summary' => "In today's lesson on {$topic}, students explored key concepts and developed their understanding through hands-on activities. They practiced applying their knowledge and collaborated with peers to solve problems. The skills learned today connect to real-life situations in Ghana and will be built upon in future lessons.",
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
    $plans_sql = "SELECT tn.id, tn.week, tn.topic, tn.unit, c.class_name, s.subject_name,
                  ac.year AS academic_year, ac.term_name, tn.created_at, tn.objectives, tn.ai_generated
                  FROM teacher_notes tn
                  JOIN classes c ON tn.class_id = c.id
                  LEFT JOIN subjects s ON tn.subject_id = s.id
                  JOIN academic_calendar ac ON tn.term_id = ac.id
                  WHERE tn.teacher_id = ? AND tn.format = 'oxford'
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

// Handle form submissions (save/update/delete)
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
            $subject_id = filter_input(INPUT_POST, 'subject_id', FILTER_VALIDATE_INT) ?? 0;

            // Safely truncated (multi-byte-aware) the same way the AJAX save path is,
            // so this legacy fallback (used only if JavaScript is unavailable) can
            // never hit the raw "Data too long" database error either.
            $unit = dbSafeText(trim(strip_tags($_POST['unit'] ?? '')), SAFE_LEN_SHORT);
            $topic = dbSafeText(trim(strip_tags($_POST['topic'] ?? '')), SAFE_LEN_SHORT);
            $objectives = dbSafeText(trim(strip_tags($_POST['objectives'] ?? '')), SAFE_LEN_SHORT);
            $reference = dbSafeText(trim(strip_tags($_POST['reference'] ?? '')), SAFE_LEN_SHORT);
            $resources = dbSafeText(trim(strip_tags($_POST['resources'] ?? '')), SAFE_LEN_SHORT);
            $keywords = dbSafeText(trim(strip_tags($_POST['keywords'] ?? '')), SAFE_LEN_SHORT);
            $starter = dbSafeText(trim(strip_tags($_POST['starter'] ?? '')), SAFE_LEN_SHORT);
            $presentation = dbSafeText(trim(strip_tags($_POST['presentation'] ?? '', '<p><br><strong><em><ul><ol><li>')), SAFE_LEN_LONG);
            $summary = dbSafeText(trim(strip_tags($_POST['summary'] ?? '')), SAFE_LEN_SHORT);
            $strand = dbSafeText(trim(strip_tags($_POST['strand'] ?? '')), SAFE_LEN_SHORT);
            $sub_strand = dbSafeText(trim(strip_tags($_POST['sub_strand'] ?? '')), SAFE_LEN_SHORT);
            $content_standard = dbSafeText(trim(strip_tags($_POST['content_standard'] ?? '')), SAFE_LEN_SHORT);
            $indicator = dbSafeText(trim(strip_tags($_POST['indicator'] ?? '')), SAFE_LEN_SHORT);
            $source_resource_ids = trim(strip_tags($_POST['source_resource_ids'] ?? ''));
            $ai_generated = !empty($_POST['ai_generated']) ? 1 : 0;

            if (!$week || $week < 1 || $week > 16) $errors[] = "Invalid week number (1-16 required).";
            if (!$class_id || $class_id <= 0) $errors[] = "Please select a schedule to set the class.";
            if (!$term_id || $term_id <= 0) $errors[] = "Please select a term.";
            if (empty($unit)) $errors[] = "Unit is required.";
            if (empty($topic)) $errors[] = "Topic is required.";
            if (empty($objectives)) $errors[] = "Objectives are required.";
            if (empty($reference)) $errors[] = "Reference is required.";
            if (empty($resources)) $errors[] = "Resources are required.";
            if (empty($keywords)) $errors[] = "Keywords are required.";
            if (empty($starter)) $errors[] = "Starter activity is required.";
            if (empty($presentation)) $errors[] = "Main presentation is required.";
            if (empty($summary)) $errors[] = "Summary is required.";

            if (empty($errors)) {
                $conn->begin_transaction();
                try {
                    if ($action === 'update' && $plan_id > 0) {
                        $update_sql = "UPDATE teacher_notes SET 
                            week = ?, class_id = ?, term_id = ?, subject_id = ?, 
                            unit = ?, topic = ?, objectives = ?, reference = ?, 
                            resources = ?, keywords = ?, starter = ?, presentation = ?, 
                            summary = ?, strand = ?, sub_strand = ?, content_standard = ?,
                            indicator = ?, source_resource_ids = ?, ai_generated = ?, updated_at = NOW()
                            WHERE id = ? AND teacher_id = ? AND format = 'oxford'";
                        $update_stmt = $conn->prepare($update_sql);
                        $update_stmt->bind_param(
                            "iiiissssssssssssssiii",
                            $week, $class_id, $term_id, $subject_id,
                            $unit, $topic, $objectives, $reference,
                            $resources, $keywords, $starter, $presentation,
                            $summary, $strand, $sub_strand, $content_standard,
                            $indicator, $source_resource_ids, $ai_generated,
                            $plan_id, $teacher_id
                        );
                        if ($update_stmt->execute()) {
                            $success_msg = "Oxford lesson plan updated successfully!";
                        } else {
                            throw new Exception($update_stmt->error);
                        }
                        $update_stmt->close();
                    } else {
                        $insert_sql = "INSERT INTO teacher_notes (
                            teacher_id, week, class_id, term_id, subject_id, unit, topic,
                            objectives, reference, resources, keywords, starter, presentation, 
                            summary, format, strand, sub_strand, content_standard, indicator,
                            source_resource_ids, ai_generated, created_at
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'oxford', ?, ?, ?, ?, ?, ?, NOW())";
                        $insert_stmt = $conn->prepare($insert_sql);
                        $insert_stmt->bind_param(
                            "iiiiissssssssssssssi",
                            $teacher_id, $week, $class_id, $term_id, $subject_id,
                            $unit, $topic, $objectives, $reference,
                            $resources, $keywords, $starter, $presentation,
                            $summary, $strand, $sub_strand, $content_standard, $indicator,
                            $source_resource_ids, $ai_generated
                        );
                        if ($insert_stmt->execute()) {
                            $success_msg = "Oxford lesson plan created successfully!";
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
                    $check_sql = "SELECT id FROM teacher_notes WHERE id = ? AND teacher_id = ? AND format = 'oxford'";
                    $check_stmt = $conn->prepare($check_sql);
                    $check_stmt->bind_param("ii", $plan_id, $teacher_id);
                    $check_stmt->execute();
                    if ($check_stmt->get_result()->num_rows === 0) {
                        $errors[] = "Lesson plan not found or unauthorized.";
                        $conn->rollback();
                    } else {
                        $check_stmt->close();
                        $delete_sql = "DELETE FROM teacher_notes WHERE id = ? AND teacher_id = ? AND format = 'oxford'";
                        $delete_stmt = $conn->prepare($delete_sql);
                        $delete_stmt->bind_param("ii", $plan_id, $teacher_id);
                        if ($delete_stmt->execute()) {
                            $success_msg = "Oxford lesson plan deleted successfully!";
                            $plans_stmt = $conn->prepare($plans_sql);
                            $plans_stmt->bind_param("i", $teacher_id);
                            $plans_stmt->execute();
                            $plans = $plans_stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
                            $plans_stmt->close();
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
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Oxford Lesson Plan management with AI assistance">
    <title>Oxford Lesson Plans | QueensPa School</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        :root {
            --primary: #ff4500;
            --primary-hover: #e03e00;
            --secondary: #1e293b;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #f0f4f8 0%, #e2e8f0 100%);
            min-height: 100vh;
            color: #1f2937;
            line-height: 1.6;
        }
        .card {
            background: white;
            border-radius: 16px;
            box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1), 0 2px 4px -1px rgba(0,0,0,0.06);
            transition: all 0.3s ease;
            border: 1px solid #e5e7eb;
        }
        .card:hover {
            box-shadow: 0 20px 25px -5px rgba(0,0,0,0.1), 0 10px 10px -5px rgba(0,0,0,0.04);
        }
        .card-header {
            background: linear-gradient(135deg, var(--primary) 0%, #ff6b3d 100%);
            color: white;
            padding: 1.5rem;
            border-radius: 16px 16px 0 0;
            font-weight: 700;
            font-size: 1.25rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.625rem 1.25rem;
            border-radius: 10px;
            font-weight: 600;
            font-size: 0.875rem;
            transition: all 0.3s ease;
            border: none;
            cursor: pointer;
            text-decoration: none;
        }
        .btn:hover { transform: translateY(-2px); }
        .btn:disabled { opacity: 0.5; cursor: not-allowed; transform: none !important; }
        .btn-primary { background: linear-gradient(135deg, var(--primary), #ff6b3d); color: white; }
        .btn-primary:hover { box-shadow: 0 10px 20px -5px rgba(255,69,0,0.4); }
        .btn-secondary { background: #6b7280; color: white; }
        .btn-danger { background: linear-gradient(135deg, var(--danger), #f87171); color: white; }
        .btn-warning { background: linear-gradient(135deg, var(--warning), #fbbf24); color: white; }
        .btn-info { background: linear-gradient(135deg, #3b82f6, #60a5fa); color: white; }
        .btn-ai { background: linear-gradient(135deg, #8b5cf6, #a78bfa); color: white; }
        .btn-xs { padding: 0.25rem 0.75rem; font-size: 0.75rem; border-radius: 6px; }
        .form-control {
            width: 100%;
            padding: 0.75rem 1rem;
            border: 2px solid #d1d5db;
            border-radius: 10px;
            font-size: 0.95rem;
            transition: all 0.3s ease;
            background: white;
        }
        .form-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(255, 69, 0, 0.1);
        }
        textarea.form-control { resize: vertical; min-height: 100px; }
        .alert {
            padding: 1rem 1.25rem;
            border-radius: 12px;
            margin-bottom: 1rem;
            display: flex;
            align-items: flex-start;
            gap: 0.75rem;
        }
        .alert-success { background: #ecfdf5; border: 1px solid #6ee7b7; color: #065f46; }
        .alert-error { background: #fef2f2; border: 1px solid #fca5a5; color: #991b1b; }
        .alert-info { background: #eff6ff; border: 1px solid #93c5fd; color: #1e40af; }
        .alert-warning { background: #fffbeb; border: 1px solid #fcd34d; color: #92400e; }
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0; top: 0;
            width: 100%; height: 100%;
            background-color: rgba(0,0,0,0.5);
            backdrop-filter: blur(4px);
            animation: fadeIn 0.3s ease;
        }
        @keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }
        .modal-content {
            background: white;
            margin: 2% auto;
            padding: 2rem;
            border-radius: 20px;
            width: 95%;
            max-width: 1000px;
            animation: slideUp 0.3s ease;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: 0 25px 50px -12px rgba(0,0,0,0.25);
        }
        @keyframes slideUp { from { transform: translateY(50px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }
        .modal-sm { max-width: 500px; }
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
            padding-bottom: 1rem;
            border-bottom: 2px solid #e5e7eb;
        }
        .modal-close {
            background: none;
            border: none;
            font-size: 1.5rem;
            color: #9ca3af;
            cursor: pointer;
            transition: color 0.3s;
            width: 40px; height: 40px;
            display: flex; align-items: center; justify-content: center;
            border-radius: 50%;
        }
        .modal-close:hover { color: var(--danger); background: #f3f4f6; }
        .table-container {
            overflow-x: auto;
            border-radius: 12px;
            border: 1px solid #e5e7eb;
        }
        table { width: 100%; border-collapse: separate; border-spacing: 0; }
        thead th {
            background: #f9fafb;
            padding: 1rem;
            font-weight: 600;
            font-size: 0.875rem;
            color: #374151;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            border-bottom: 2px solid #e5e7eb;
            position: sticky; top: 0; z-index: 10;
        }
        tbody td {
            padding: 0.875rem 1rem;
            border-bottom: 1px solid #f3f4f6;
            font-size: 0.9rem;
        }
        tbody tr:hover { background-color: #f8fafc; }
        .ai-suggestion-box {
            background: linear-gradient(135deg, #f5f3ff, #ede9fe);
            border: 2px solid #c4b5fd;
            border-radius: 12px;
            padding: 1rem;
            margin-top: 0.5rem;
            position: relative;
        }
        .ai-suggestion-box::before {
            content: 'AI Suggestion';
            position: absolute; top: -12px; left: 1rem;
            background: #8b5cf6; color: white;
            padding: 0.25rem 0.75rem; border-radius: 20px;
            font-size: 0.75rem; font-weight: 600;
        }
        .ai-fallback-box {
            background: linear-gradient(135deg, #fef3c7, #fde68a);
            border: 2px solid #f59e0b;
            border-radius: 12px;
            padding: 1rem;
            margin-top: 0.5rem;
            position: relative;
        }
        .ai-fallback-box::before {
            content: 'Template (Offline)';
            position: absolute; top: -12px; left: 1rem;
            background: #f59e0b; color: white;
            padding: 0.25rem 0.75rem; border-radius: 20px;
            font-size: 0.75rem; font-weight: 600;
        }
        .oxford-badge {
            background: linear-gradient(135deg, #1e3a8a, #3b82f6);
            color: white;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            display: inline-flex; align-items: center; gap: 0.25rem;
        }
        .ai-badge {
            background: linear-gradient(135deg, #8b5cf6, #6d28d9);
            color: white;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            display: inline-flex; align-items: center; gap: 0.25rem;
        }
        .schedule-day-badge {
            background: linear-gradient(135deg, #0891b2, #06b6d4);
            color: white;
            padding: 0.2rem 0.6rem;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 700;
            letter-spacing: 0.03em;
            display: inline-flex; align-items: center; gap: 0.25rem;
        }
        .pulse { animation: pulse 2s infinite; }
        @keyframes pulse { 0%,100% { opacity: 1; } 50% { opacity: 0.5; } }
        .loading-overlay {
            display: none;
            position: fixed; top: 0; left: 0;
            width: 100%; height: 100%;
            background: rgba(0,0,0,0.7);
            z-index: 9999;
            justify-content: center; align-items: center;
            flex-direction: column; gap: 1rem;
        }
        .loading-spinner {
            width: 60px; height: 60px;
            border: 4px solid rgba(255,255,255,0.3);
            border-top-color: var(--primary);
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }
        @keyframes spin { to { transform: rotate(360deg); } }
        .grid-2 { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 1rem; }
        .view-detail {
            background: linear-gradient(135deg, #f0f9ff, #e0f2fe);
            border: 1px solid #bae6fd;
            border-radius: 12px;
            padding: 1.25rem;
            margin-bottom: 1rem;
        }
        .view-detail h4 {
            font-size: 1.1rem; font-weight: 700;
            color: #1f2937; margin-bottom: 0.75rem;
            display: flex; align-items: center; gap: 0.5rem;
        }
        .view-detail p { color: #374151; line-height: 1.7; white-space: pre-wrap; }
        .required::after { content: ' *'; color: var(--danger); }
        .source-chip {
            display: inline-flex; align-items: center; gap: 0.4rem;
            background: #eef2ff; border: 1px solid #c7d2fe; color: #3730a3;
            padding: 0.35rem 0.7rem; border-radius: 999px; font-size: 0.8rem; font-weight: 600;
        }
        .source-chip.selected { background: #4f46e5; color: white; border-color: #4f46e5; }
        .source-chip .fa-times { cursor: pointer; }
        .dropzone {
            border: 2px dashed #c4b5fd; border-radius: 12px; padding: 1.5rem;
            text-align: center; color: #6d28d9; cursor: pointer; background: #faf5ff;
            transition: all .2s ease;
        }
        .dropzone.dragover { background: #ede9fe; border-color: #7c3aed; }
        .save-toast {
            position: fixed; top: 20px; right: 20px; z-index: 10000;
            min-width: 280px; max-width: 420px;
            padding: 1rem 1.25rem; border-radius: 12px;
            box-shadow: 0 10px 25px -5px rgba(0,0,0,0.2);
            display: flex; align-items: flex-start; gap: 0.75rem;
            animation: slideInRight 0.3s ease;
        }
        .save-toast.success { background: #ecfdf5; border: 1px solid #6ee7b7; color: #065f46; }
        .save-toast.error { background: #fef2f2; border: 1px solid #fca5a5; color: #991b1b; }
        @keyframes slideInRight { from { transform: translateX(120%); opacity: 0; } to { transform: translateX(0); opacity: 1; } }
        @media (max-width: 768px) {
            .modal-content { margin: 0; border-radius: 0; width: 100%; height: 100%; max-height: 100vh; }
            .grid-2 { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body class="min-h-screen">
    <!-- Loading Overlay -->
    <div id="loading-overlay" class="loading-overlay">
        <div class="loading-spinner"></div>
        <p class="text-white font-semibold text-lg">Processing...</p>
        <p class="text-white text-sm" id="loading-message"></p>
    </div>

    <!-- Toast container for instant save feedback -->
    <div id="toastContainer"></div>

    <!-- View Modal -->
    <div id="viewModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="text-2xl font-bold"><i class="fas fa-book-open text-orange-500"></i> View Oxford Lesson Plan</h3>
                <button onclick="closeViewModal()" class="modal-close"><i class="fas fa-times"></i></button>
            </div>
            <div id="viewContent"></div>
        </div>
    </div>

    <!-- Edit Modal -->
    <div id="editModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="text-2xl font-bold"><i class="fas fa-edit text-yellow-500"></i> Edit Oxford Lesson Plan</h3>
                <button onclick="closeEditModal()" class="modal-close"><i class="fas fa-times"></i></button>
            </div>
            <form id="editLessonPlanForm" class="space-y-4">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                <input type="hidden" name="action" value="update">
                <input type="hidden" id="edit_plan_id" name="plan_id">
                <input type="hidden" id="edit_class_id" name="class_id">
                <input type="hidden" id="edit_subject_id" name="subject_id">
                <input type="hidden" id="edit_department_id" name="department_id">
                <input type="hidden" id="edit_schedule_id" name="schedule_id">
                <input type="hidden" id="edit_strand" name="strand">
                <input type="hidden" id="edit_sub_strand" name="sub_strand">
                <input type="hidden" id="edit_content_standard" name="content_standard">
                <input type="hidden" id="edit_indicator" name="indicator">
                <input type="hidden" id="edit_source_resource_ids" name="source_resource_ids">
                <input type="hidden" id="edit_ai_generated" name="ai_generated" value="0">

                <div class="bg-blue-50 p-4 rounded-xl mb-4">
                    <span class="oxford-badge"><i class="fas fa-graduation-cap"></i> Oxford Curriculum</span>
                    <span class="text-sm text-gray-600 ml-2">Uses <strong>Unit</strong> and <strong>Topic</strong> only</span>
                </div>

                <div class="grid-2">
                    <div>
                        <label for="edit_term_id" class="block text-sm font-medium text-gray-700 required">Term</label>
                        <select id="edit_term_id" name="term_id" class="form-control" required>
                            <option value="">Select Term</option>
                            <?php foreach ($available_terms as $term): ?>
                                <option value="<?php echo $term['id']; ?>"><?php echo htmlspecialchars($term['term_name'] . ' (' . $term['year'] . ')'); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label for="edit_week" class="block text-sm font-medium text-gray-700 required">Week</label>
                        <select id="edit_week" name="week" class="form-control" required>
                            <option value="">Select Week</option>
                            <?php for ($i = 1; $i <= 16; $i++): ?>
                                <option value="<?php echo $i; ?>">Week <?php echo $i; ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                </div>

                <div class="grid-2">
                    <div>
                        <label for="edit_unit" class="block text-sm font-medium text-gray-700 required">Unit</label>
                        <input type="text" id="edit_unit" name="unit" class="form-control" placeholder="e.g., Unit 5: Living Things" required>
                    </div>
                    <div>
                        <label for="edit_topic" class="block text-sm font-medium text-gray-700 required">Topic</label>
                        <input type="text" id="edit_topic" name="topic" class="form-control" placeholder="e.g., Classification of Animals" required>
                    </div>
                </div>

                <div>
                    <label for="edit_objectives" class="block text-sm font-medium text-gray-700 required">Objectives</label>
                    <div class="flex gap-2">
                        <textarea id="edit_objectives" name="objectives" class="form-control flex-1" rows="4" required maxlength="8000"></textarea>
                        <button type="button" onclick="getAISuggestion('objectives', 'edit')" class="btn btn-ai btn-xs self-start" id="edit-ai-objectives-btn"><i class="fas fa-robot"></i> AI</button>
                    </div>
                    <div id="edit_objectives_ai" class="ai-suggestion-box hidden mt-2"></div>
                </div>

                <div>
                    <label for="edit_reference" class="block text-sm font-medium text-gray-700 required">Reference</label>
                    <textarea id="edit_reference" name="reference" class="form-control" rows="3" required maxlength="8000"></textarea>
                </div>

                <div>
                    <label for="edit_resources" class="block text-sm font-medium text-gray-700 required">Resources</label>
                    <div class="flex gap-2">
                        <textarea id="edit_resources" name="resources" class="form-control flex-1" rows="3" required maxlength="8000"></textarea>
                        <button type="button" onclick="getAISuggestion('resources', 'edit')" class="btn btn-ai btn-xs self-start" id="edit-ai-resources-btn"><i class="fas fa-robot"></i> AI</button>
                    </div>
                    <div id="edit_resources_ai" class="ai-suggestion-box hidden mt-2"></div>
                </div>

                <div>
                    <label for="edit_keywords" class="block text-sm font-medium text-gray-700 required">Keywords</label>
                    <div class="flex gap-2">
                        <textarea id="edit_keywords" name="keywords" class="form-control flex-1" rows="3" required maxlength="8000"></textarea>
                        <button type="button" onclick="getAISuggestion('keywords', 'edit')" class="btn btn-ai btn-xs self-start" id="edit-ai-keywords-btn"><i class="fas fa-robot"></i> AI</button>
                    </div>
                    <div id="edit_keywords_ai" class="ai-suggestion-box hidden mt-2"></div>
                </div>

                <div>
                    <label for="edit_starter" class="block text-sm font-medium text-gray-700 required">Starter</label>
                    <div class="flex gap-2">
                        <textarea id="edit_starter" name="starter" class="form-control flex-1" rows="3" required maxlength="8000"></textarea>
                        <button type="button" onclick="getAISuggestion('starter', 'edit')" class="btn btn-ai btn-xs self-start" id="edit-ai-starter-btn"><i class="fas fa-robot"></i> AI</button>
                    </div>
                    <div id="edit_starter_ai" class="ai-suggestion-box hidden mt-2"></div>
                </div>

                <div>
                    <label for="edit_presentation" class="block text-sm font-medium text-gray-700 required">Main Presentation</label>
                    <div class="flex gap-2">
                        <textarea id="edit_presentation" name="presentation" class="form-control flex-1" rows="6" required maxlength="20000"></textarea>
                        <button type="button" onclick="getAISuggestion('presentation', 'edit')" class="btn btn-ai btn-xs self-start" id="edit-ai-presentation-btn"><i class="fas fa-robot"></i> AI</button>
                    </div>
                    <div id="edit_presentation_ai" class="ai-suggestion-box hidden mt-2"></div>
                </div>

                <div>
                    <label for="edit_summary" class="block text-sm font-medium text-gray-700 required">Summary / Plenary</label>
                    <div class="flex gap-2">
                        <textarea id="edit_summary" name="summary" class="form-control flex-1" rows="3" required maxlength="8000"></textarea>
                        <button type="button" onclick="getAISuggestion('summary', 'edit')" class="btn btn-ai btn-xs self-start" id="edit-ai-summary-btn"><i class="fas fa-robot"></i> AI</button>
                    </div>
                    <div id="edit_summary_ai" class="ai-suggestion-box hidden mt-2"></div>
                </div>

                <div class="flex gap-3 pt-4 border-t border-gray-200">
                    <button type="submit" id="edit-submit-btn" class="btn btn-warning"><i class="fas fa-save"></i> Update Lesson Plan</button>
                    <button type="button" onclick="closeEditModal()" class="btn btn-secondary"><i class="fas fa-times"></i> Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Delete Modal -->
    <div id="deleteModal" class="modal">
        <div class="modal-content modal-sm text-center">
            <i class="fas fa-exclamation-triangle text-red-500 text-6xl mb-4"></i>
            <h3 class="text-xl font-bold text-gray-800 mb-2">Confirm Deletion</h3>
            <p class="text-gray-600 mb-6">Are you sure you want to delete this lesson plan? This cannot be undone.</p>
            <form id="deleteForm" class="flex justify-center gap-3">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                <input type="hidden" id="delete_plan_id" name="plan_id">
                <button type="submit" class="btn btn-danger"><i class="fas fa-trash"></i> Delete</button>
                <button type="button" onclick="closeDeleteModal()" class="btn btn-secondary"><i class="fas fa-times"></i> Cancel</button>
            </form>
        </div>
    </div>

    <?php include $_SERVER['DOCUMENT_ROOT'] . '/templates/teacher_header.php'; ?>

    <div class="container mx-auto px-4 py-8">
        <!-- Status Bar -->
        <div class="alert alert-info mb-6">
            <i class="fas fa-calendar text-2xl"></i>
            <div class="flex-1">
                <div class="flex justify-between items-center flex-wrap gap-2">
                    <div>
                        <strong>Academic Year:</strong> <?php echo htmlspecialchars($default_academic_year ?: 'N/A'); ?>
                        <span class="oxford-badge ml-2"><i class="fas fa-graduation-cap"></i> Oxford</span>
                        <span class="ai-badge ml-2"><i class="fas fa-robot"></i> AI Ready</span>
                    </div>
                    <?php if (!$default_term_id): ?>
                        <span class="text-red-600"><i class="fas fa-exclamation-triangle"></i> No active or upcoming term found.</span>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <?php if (!empty($errors)): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-circle text-xl"></i>
                <div><?php foreach ($errors as $error): ?><p><?php echo htmlspecialchars($error); ?></p><?php endforeach; ?></div>
            </div>
        <?php endif; ?>

        <?php if ($success_msg): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle text-xl"></i>
                <p><?php echo htmlspecialchars($success_msg); ?></p>
            </div>
        <?php endif; ?>

        <!-- Existing Plans Table -->
        <section class="card mb-8">
            <div class="card-header"><i class="fas fa-list"></i> My Oxford Lesson Plans</div>
            <div class="p-6">
                <div id="plansTableWrapper">
                <?php if (empty($plans)): ?>
                    <div class="alert alert-info" id="noPlansAlert"><i class="fas fa-info-circle"></i><p>No plans created yet. Create your first plan below!</p></div>
                <?php else: ?>
                    <div class="table-container">
                        <table>
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Class</th>
                                    <th>Subject</th>
                                    <th>Topic</th>
                                    <th>Unit</th>
                                    <th>Week</th>
                                    <th>Term</th>
                                    <th>Year</th>
                                    <th>Created</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody id="plansTableBody">
                                <?php foreach ($plans as $index => $plan): ?>
                                    <tr>
                                        <td><?php echo $index + 1; ?></td>
                                        <td class="font-medium"><?php echo htmlspecialchars(getDisplayClassName($plan['class_name'])); ?></td>
                                        <td><?php echo htmlspecialchars($plan['subject_name'] ?? 'N/A'); ?></td>
                                        <td><?php echo htmlspecialchars(substr($plan['topic'] ?? 'N/A', 0, 30)); ?>
                                            <?php if (!empty($plan['ai_generated'])): ?><span class="ai-badge ml-1" style="font-size:0.6rem;padding:.1rem .5rem;"><i class="fas fa-robot"></i></span><?php endif; ?>
                                        </td>
                                        <td><?php echo htmlspecialchars($plan['unit'] ?? 'N/A'); ?></td>
                                        <td>Week <?php echo $plan['week']; ?></td>
                                        <td><?php echo htmlspecialchars($plan['term_name']); ?></td>
                                        <td><?php echo htmlspecialchars($plan['academic_year']); ?></td>
                                        <td class="text-sm"><?php echo date('M d, Y', strtotime($plan['created_at'])); ?></td>
                                        <td>
                                            <div class="flex gap-1">
                                                <button onclick="viewPlan(<?php echo $plan['id']; ?>)" class="btn btn-info btn-xs"><i class="fas fa-eye"></i></button>
                                                <button onclick="editPlan(<?php echo $plan['id']; ?>)" class="btn btn-warning btn-xs"><i class="fas fa-edit"></i></button>
                                                <button onclick="deletePlan(<?php echo $plan['id']; ?>)" class="btn btn-danger btn-xs"><i class="fas fa-trash"></i></button>
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

        <!-- Create Form -->
        <section id="createSection" class="card mb-8">
            <div class="card-header"><i class="fas fa-plus-circle"></i> Create New Oxford Lesson Plan</div>
            <div class="p-6">
                <?php if (empty($schedules) && !$default_term_id): ?>
                    <div class="alert alert-warning"><i class="fas fa-exclamation-triangle"></i><p>No schedules found for the selected term. Please select a different term or contact administrator.</p></div>
                <?php else: ?>
                    <form id="oxfordLessonPlanForm" class="space-y-6">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                        <input type="hidden" name="action" value="submit">
                        <input type="hidden" id="class_id" name="class_id">
                        <input type="hidden" id="subject_id" name="subject_id">
                        <input type="hidden" id="department_id" name="department_id">
                        <input type="hidden" id="strand" name="strand">
                        <input type="hidden" id="sub_strand" name="sub_strand">
                        <input type="hidden" id="content_standard" name="content_standard">
                        <input type="hidden" id="indicator" name="indicator">
                        <input type="hidden" id="source_resource_ids" name="source_resource_ids" value="">
                        <input type="hidden" id="ai_generated" name="ai_generated" value="0">

                        <div class="bg-blue-50 p-4 rounded-xl"><span class="oxford-badge"><i class="fas fa-graduation-cap"></i> Oxford Curriculum</span><span class="text-sm text-gray-600 ml-2">Uses <strong>Unit</strong> and <strong>Topic</strong> only</span></div>

                        <div class="bg-gray-50 p-6 rounded-xl">
                            <h3 class="text-lg font-bold mb-4"><i class="fas fa-info-circle text-blue-500"></i> Basic Information</h3>
                            <div class="grid-2">
                                <div>
                                    <label for="term_id" class="block text-sm font-medium text-gray-700 required">Term</label>
                                    <select id="term_id" name="term_id" class="form-control" required>
                                        <option value="">Select Term</option>
                                        <?php foreach ($available_terms as $term): ?>
                                            <option value="<?php echo $term['id']; ?>" <?php echo ($term['id'] == $default_term_id) ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($term['term_name'] . ' (' . $term['year'] . ')'); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div>
                                    <label for="week" class="block text-sm font-medium text-gray-700 required">Week</label>
                                    <select id="week" name="week" class="form-control" required>
                                        <option value="">Select Week</option>
                                        <?php for ($i = 1; $i <= 16; $i++): ?>
                                            <option value="<?php echo $i; ?>">Week <?php echo $i; ?></option>
                                        <?php endfor; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="mt-4">
                                <label for="schedule_id" class="block text-sm font-medium text-gray-700 required">Class & Subject</label>
                                <select id="schedule_id" name="schedule_id" class="form-control" required>
                                    <option value="">Select a schedule</option>
                                    <?php foreach ($schedules as $sched): ?>
                                        <option value="<?php echo $sched['id']; ?>">
                                            <?php echo htmlspecialchars($sched['label']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="text-xs text-gray-500 mt-1"><i class="fas fa-info-circle"></i> Each option shows the day and time of that class, so you can tell classes with the same subject apart.</p>
                            </div>
                            <div id="schedule-container" class="mt-4"></div>
                        </div>

                        <div class="bg-gray-50 p-6 rounded-xl">
                            <h3 class="text-lg font-bold mb-4"><i class="fas fa-book text-purple-500"></i> Oxford Curriculum Details</h3>
                            <div class="grid-2">
                                <div>
                                    <label for="unit" class="block text-sm font-medium text-gray-700">Unit <span class="text-xs text-gray-400 font-normal">(leave blank to let AI decide from your sources)</span></label>
                                    <input type="text" id="unit" name="unit" class="form-control" placeholder="e.g., Unit 5: Living Things">
                                </div>
                                <div>
                                    <label for="topic" class="block text-sm font-medium text-gray-700">Topic <span class="text-xs text-gray-400 font-normal">(leave blank to let AI decide from your sources)</span></label>
                                    <input type="text" id="topic" name="topic" class="form-control" placeholder="e.g., Classification of Animals">
                                </div>
                            </div>
                        </div>

                        <!-- ============================= NEW: SOURCES / RESOURCE LIBRARY ============================= -->
                        <div class="bg-indigo-50 p-6 rounded-xl border-2 border-indigo-200">
                            <h3 class="text-lg font-bold mb-1"><i class="fas fa-folder-open text-indigo-600"></i> Lesson Sources</h3>
                            <p class="text-sm text-gray-600 mb-4">Upload a textbook page, syllabus excerpt, worksheet, or notes (PDF, DOCX, TXT, or a photo) &mdash; in any language. The AI will read it carefully and build the lesson plan from it. Every file you upload is saved to your resource library, so you can reuse it for future weeks without re-uploading.</p>

                            <div id="sourceDropzone" class="dropzone">
                                <i class="fas fa-cloud-upload-alt text-3xl mb-2"></i>
                                <p class="font-semibold">Click to choose files or drag & drop here</p>
                                <p class="text-xs text-gray-500 mt-1">PDF, DOCX, TXT, JPG, PNG, WEBP — up to <?php echo round($effective_max_upload_bytes / 1024 / 1024, 1); ?>MB each</p>
                                <input type="file" id="sourceFileInput" multiple accept=".pdf,.docx,.txt,.jpg,.jpeg,.png,.webp" class="hidden">
                            </div>
                            <div id="uploadProgressList" class="mt-3 space-y-2"></div>

                            <div class="mt-4">
                                <div class="flex items-center justify-between mb-2">
                                    <h4 class="text-sm font-semibold text-gray-700"><i class="fas fa-book-reader"></i> Your saved sources for this class/subject</h4>
                                    <button type="button" onclick="loadResourceLibrary()" class="btn btn-secondary btn-xs"><i class="fas fa-sync"></i> Refresh</button>
                                </div>
                                <div id="resourceLibraryList" class="flex flex-wrap gap-2">
                                    <p class="text-xs text-gray-400">Select a schedule above to see matching saved sources.</p>
                                </div>
                            </div>

                            <div class="mt-4">
                                <label for="plan_language" class="block text-sm font-medium text-gray-700">Lesson plan language</label>
                                <select id="plan_language" class="form-control" style="max-width:280px;">
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
                                <input type="text" id="plan_language_other" class="form-control mt-2 hidden" placeholder="Type the language name">
                                <p class="text-xs text-gray-500 mt-1"><i class="fas fa-language"></i> The AI can read source documents and write the plan in most languages. Pick one here, or leave it to auto-match your uploaded source.</p>
                            </div>

                            <div class="mt-4 flex items-center justify-between flex-wrap gap-3">
                                <div id="selectedSourcesSummary" class="text-sm text-indigo-700 font-medium">No sources selected — AI will use general Oxford curriculum knowledge instead.</div>
                                <button type="button" onclick="generateCompletePlan()" class="btn btn-ai pulse" id="generateFullPlanBtn"><i class="fas fa-magic"></i> Generate Complete Plan</button>
                            </div>
                            <div id="complete_plan_ai" class="ai-suggestion-box hidden mt-4"></div>
                        </div>
                        <!-- =========================================================================================== -->

                        <div class="bg-gray-50 p-6 rounded-xl">
                            <h3 class="text-lg font-bold mb-4"><i class="fas fa-pencil-alt text-green-500"></i> Lesson Details</h3>
                            <div class="space-y-4">
                                <div>
                                    <label for="objectives" class="block text-sm font-medium text-gray-700 required">Objectives</label>
                                    <div class="flex gap-2">
                                        <textarea id="objectives" name="objectives" class="form-control flex-1" rows="4" required maxlength="8000" placeholder="SMART learning objectives..."></textarea>
                                        <button type="button" onclick="getAISuggestion('objectives', 'create')" class="btn btn-ai btn-xs self-start" id="ai-objectives-btn"><i class="fas fa-robot"></i> AI</button>
                                    </div>
                                    <div id="objectives_ai" class="ai-suggestion-box hidden mt-2"></div>
                                </div>
                                <div>
                                    <label for="reference" class="block text-sm font-medium text-gray-700 required">Reference</label>
                                    <textarea id="reference" name="reference" class="form-control" rows="3" required maxlength="8000" placeholder="e.g., Oxford International Primary Science Book 5, Pages 45-50"></textarea>
                                </div>
                                <div>
                                    <label for="resources" class="block text-sm font-medium text-gray-700 required">Resources</label>
                                    <div class="flex gap-2">
                                        <textarea id="resources" name="resources" class="form-control flex-1" rows="3" required maxlength="8000" placeholder="Teaching and learning materials..."></textarea>
                                        <button type="button" onclick="getAISuggestion('resources', 'create')" class="btn btn-ai btn-xs self-start" id="ai-resources-btn"><i class="fas fa-robot"></i> AI</button>
                                    </div>
                                    <div id="resources_ai" class="ai-suggestion-box hidden mt-2"></div>
                                </div>
                                <div>
                                    <label for="keywords" class="block text-sm font-medium text-gray-700 required">Keywords</label>
                                    <div class="flex gap-2">
                                        <textarea id="keywords" name="keywords" class="form-control flex-1" rows="3" required maxlength="8000" placeholder="e.g., vertebrate, invertebrate, mammal..."></textarea>
                                        <button type="button" onclick="getAISuggestion('keywords', 'create')" class="btn btn-ai btn-xs self-start" id="ai-keywords-btn"><i class="fas fa-robot"></i> AI</button>
                                    </div>
                                    <div id="keywords_ai" class="ai-suggestion-box hidden mt-2"></div>
                                </div>
                            </div>
                        </div>

                        <div class="bg-gray-50 p-6 rounded-xl">
                            <h3 class="text-lg font-bold mb-4"><i class="fas fa-chalkboard-teacher text-red-500"></i> Lesson Delivery</h3>
                            <div class="space-y-4">
                                <div>
                                    <label for="starter" class="block text-sm font-medium text-gray-700 required">Starter Activity</label>
                                    <div class="flex gap-2">
                                        <textarea id="starter" name="starter" class="form-control flex-1" rows="3" required maxlength="8000" placeholder="Describe the starter (5-10 mins)..."></textarea>
                                        <button type="button" onclick="getAISuggestion('starter', 'create')" class="btn btn-ai btn-xs self-start" id="ai-starter-btn"><i class="fas fa-robot"></i> AI</button>
                                    </div>
                                    <div id="starter_ai" class="ai-suggestion-box hidden mt-2"></div>
                                </div>
                                <div>
                                    <label for="presentation" class="block text-sm font-medium text-gray-700 required">Main Presentation</label>
                                    <div class="flex gap-2">
                                        <textarea id="presentation" name="presentation" class="form-control flex-1" rows="6" required maxlength="20000" placeholder="Detail the main lesson..."></textarea>
                                        <button type="button" onclick="getAISuggestion('presentation', 'create')" class="btn btn-ai btn-xs self-start" id="ai-presentation-btn"><i class="fas fa-robot"></i> AI</button>
                                    </div>
                                    <div id="presentation_ai" class="ai-suggestion-box hidden mt-2"></div>
                                    <p id="presentation-counter" class="text-sm text-gray-500 text-right">0/20000</p>
                                </div>
                                <div>
                                    <label for="summary" class="block text-sm font-medium text-gray-700 required">Plenary / Summary</label>
                                    <div class="flex gap-2">
                                        <textarea id="summary" name="summary" class="form-control flex-1" rows="3" required maxlength="8000" placeholder="Key points summary..."></textarea>
                                        <button type="button" onclick="getAISuggestion('summary', 'create')" class="btn btn-ai btn-xs self-start" id="ai-summary-btn"><i class="fas fa-robot"></i> AI</button>
                                    </div>
                                    <div id="summary_ai" class="ai-suggestion-box hidden mt-2"></div>
                                </div>
                            </div>
                        </div>

                        <!-- Form Actions -->
                        <div class="flex gap-3 pt-4 border-t border-gray-200">
                            <button type="submit" id="submit-btn" class="btn btn-primary"><i class="fas fa-save"></i> Save Lesson Plan</button>
                            <a href="oxford_lesson_plan.php" class="btn btn-secondary"><i class="fas fa-undo"></i> Reset</a>
                        </div>
                    </form>
                <?php endif; ?>
            </div>
        </section>
    </div>

    <?php include $_SERVER['DOCUMENT_ROOT'] . '/templates/teacher_footer.php'; ?>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script>
    const csrfToken = '<?php echo htmlspecialchars($csrf_token); ?>';
    const effectiveMaxUploadBytes = <?php echo (int)$effective_max_upload_bytes; ?>;
    let aiRequestTimeout = null;
    const AI_TIMEOUT = 45000;
    let currentSchedule = null;        // holds selected schedule details (class/subject/day/time)
    let selectedResourceIds = new Set(); // resource ids chosen as sources for generation
    let libraryResources = [];         // cached list from server
    let planCounter = <?php echo count($plans); ?>;

    function showLoading(msg = 'Processing...') {
        $('#loading-message').text(msg);
        $('#loading-overlay').css('display', 'flex');
    }
    function hideLoading() {
        $('#loading-overlay').css('display', 'none');
    }

    function escapeHtml(text) {
        const d = document.createElement('div');
        d.textContent = text;
        return d.innerHTML;
    }

    // Simple toast so "Save" gives instant feedback without a full page reload.
    function showToast(message, type = 'success') {
        const id = 'toast_' + Date.now();
        const icon = type === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle';
        $('#toastContainer').append(
            `<div id="${id}" class="save-toast ${type}">
                <i class="fas ${icon} text-xl"></i>
                <div style="flex:1;">${message}</div>
            </div>`
        );
        setTimeout(() => $('#' + id).fadeOut(300, function() { $(this).remove(); }), 4000);
    }

    // Load schedules based on term selection
    function loadSchedules(termId) {
        if (!termId) {
            $('#schedule_id').empty().append('<option value="">Select a schedule</option>');
            $('#class_id, #subject_id, #department_id').val('');
            return;
        }
        showLoading('Loading schedules...');
        $.get(window.location.pathname + '?action=get_schedules', { term_id: termId }, function(response) {
            hideLoading();
            const sel = $('#schedule_id');
            sel.empty().append('<option value="">Select a schedule</option>');
            if (response.schedules && response.schedules.length) {
                response.schedules.forEach(s => {
                    sel.append(`<option value="${s.id}">${escapeHtml(s.label)}</option>`);
                });
            } else {
                sel.append('<option value="">No schedules for this term</option>');
            }
            $('#schedule-container').empty();
            $('#class_id, #subject_id, #department_id').val('');
        }, 'json').fail(function() { hideLoading(); alert('Error loading schedules.'); });
    }

    $('#term_id').on('change', function() {
        loadSchedules($(this).val());
    });

    // When schedule selected, fetch details + refresh resource library for this class/subject
    $('#schedule_id').on('change', function() {
        const id = $(this).val();
        if (!id) {
            $('#class_id, #subject_id, #department_id').val('');
            $('#schedule-container').empty();
            currentSchedule = null;
            return;
        }
        showLoading('Loading schedule details...');
        $.get(window.location.pathname + '?action=get_schedule_details', { schedule_id: id }, function(r) {
            hideLoading();
            if (r.error) {
                $('#schedule-container').html('<div class="alert alert-warning"><p>' + escapeHtml(r.error) + '</p></div>');
            } else if (r.schedule) {
                currentSchedule = r.schedule;
                $('#class_id').val(r.schedule.class_id);
                $('#subject_id').val(r.schedule.subject_id || '');
                $('#department_id').val(r.schedule.department_id);
                $('#schedule-container').html(
                    '<div class="bg-green-50 p-4 rounded-xl border border-green-200">' +
                    '<h4 class="font-semibold text-green-700"><i class="fas fa-check-circle"></i> Selected: ' + escapeHtml(r.schedule.display_class_name) + ' - ' + escapeHtml(r.schedule.subject_name) + '</h4>' +
                    '<p class="text-sm mt-2 flex items-center gap-2 flex-wrap">' +
                    '<span class="schedule-day-badge"><i class="fas fa-calendar-day"></i> ' + escapeHtml(r.schedule.day_of_week) + '</span>' +
                    '<span><strong>Time:</strong> ' + escapeHtml(formatTimeRangeJS(r.schedule.start_time, r.schedule.end_time)) + '</span>' +
                    '<span><strong>Dept:</strong> ' + escapeHtml(r.schedule.department_name) + '</span>' +
                    '</p>' +
                    '</div>'
                );
                loadResourceLibrary();
            }
        }, 'json').fail(function() { hideLoading(); });
    });

    $('#plan_language').on('change', function() {
        $('#plan_language_other').toggleClass('hidden', $(this).val() !== 'Other');
    });

    function formatTime12JS(time) {
        if (!time) return '';
        const parts = time.split(':');
        let h = parseInt(parts[0], 10);
        const m = parts[1] || '00';
        const ampm = h >= 12 ? 'PM' : 'AM';
        h = h % 12 || 12;
        return h + ':' + m + ' ' + ampm;
    }
    function formatTimeRangeJS(start, end) {
        return formatTime12JS(start) + ' - ' + formatTime12JS(end);
    }

    // ================= NEW: SOURCES / RESOURCE LIBRARY =================

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
            box.html('<p class="text-xs text-gray-400">No saved sources yet for this class/subject. Upload one above.</p>');
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
        if (selectedResourceIds.has(id)) selectedResourceIds.delete(id);
        else selectedResourceIds.add(id);
        renderResourceLibrary();
    }

    function updateSelectedSourcesSummary() {
        const n = selectedResourceIds.size;
        $('#selectedSourcesSummary').text(
            n === 0
                ? 'No sources selected — AI will use general Oxford curriculum knowledge instead.'
                : n + ' source' + (n > 1 ? 's' : '') + ' selected — AI will ground the plan in these documents.'
        );
        $('#source_resource_ids').val(JSON.stringify(Array.from(selectedResourceIds)));
    }

    function deleteResource(id) {
        if (!confirm('Remove this saved source permanently?')) return;
        $.post(window.location.pathname, { ajax_action: 'delete_resource', resource_id: id, csrf_token: csrfToken }, function(r) {
            if (r.success) {
                selectedResourceIds.delete(id);
                loadResourceLibrary();
            } else {
                alert(r.error || 'Could not delete resource.');
            }
        }, 'json');
    }

    function uploadSourceFile(file) {
        const rowId = 'upl_' + Math.random().toString(36).slice(2);

        // Check against the server's REAL effective limit before sending anything —
        // avoids a doomed round-trip that php.ini/nginx would reject with no usable
        // JSON response (the failure mode that previously showed as "network error").
        if (effectiveMaxUploadBytes > 0 && file.size > effectiveMaxUploadBytes) {
            const limitMb = (effectiveMaxUploadBytes / 1024 / 1024).toFixed(1);
            $('#uploadProgressList').append(
                `<div id="${rowId}" class="flex items-center gap-2 text-sm text-gray-600">
                    <i class="fas fa-exclamation-circle text-red-500"></i>
                    <span>${escapeHtml(file.name)} — too large. Server limit is currently ${limitMb}MB.</span>
                </div>`
            );
            return;
        }

        $('#uploadProgressList').append(
            `<div id="${rowId}" class="flex items-center gap-2 text-sm text-gray-600">
                <i class="fas fa-spinner fa-spin text-indigo-500"></i>
                <span>${escapeHtml(file.name)} — uploading...</span>
            </div>`
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
            url: window.location.pathname,
            type: 'POST',
            data: fd,
            processData: false,
            contentType: false,
            dataType: 'json',
            success: function(r) {
                if (r.success) {
                    $('#' + rowId).html(`<i class="fas fa-check-circle text-green-500"></i> <span>${escapeHtml(file.name)} — saved to your sources.</span>`);
                    selectedResourceIds.add(r.resource.id);
                    loadResourceLibrary();
                } else {
                    $('#' + rowId).html(`<i class="fas fa-exclamation-circle text-red-500"></i> <span>${escapeHtml(file.name)} — ${escapeHtml(r.error || 'upload failed')}</span>`);
                }
            },
            error: function() {
                $('#' + rowId).html(`<i class="fas fa-exclamation-circle text-red-500"></i> <span>${escapeHtml(file.name)} — network error during upload.</span>`);
            }
        });
    }

    const dz = document.getElementById('sourceDropzone');
    const fileInput = document.getElementById('sourceFileInput');
    dz.addEventListener('click', () => fileInput.click());
    fileInput.addEventListener('change', (e) => {
        Array.from(e.target.files).forEach(uploadSourceFile);
        fileInput.value = '';
    });
    ['dragover', 'dragenter'].forEach(evt => dz.addEventListener(evt, (e) => { e.preventDefault(); dz.classList.add('dragover'); }));
    ['dragleave', 'drop'].forEach(evt => dz.addEventListener(evt, (e) => { e.preventDefault(); dz.classList.remove('dragover'); }));
    dz.addEventListener('drop', (e) => {
        Array.from(e.dataTransfer.files).forEach(uploadSourceFile);
    });

    // ================= AI: single-field suggestions (unchanged) =================

    function getAISuggestion(type, formType, retryCount = 0) {
        const prefix = formType === 'edit' ? 'edit_' : '';
        const topic = document.getElementById(prefix + 'topic')?.value?.trim() || '';
        const unit = document.getElementById(prefix + 'unit')?.value?.trim() || '';
        if (!topic) { alert('Please enter a topic first (or generate the complete plan, which fills this in automatically).'); return; }
        if (!unit) { alert('Please enter a unit first (or generate the complete plan, which fills this in automatically).'); return; }
        const aiBox = document.getElementById(prefix + type + '_ai');
        if (!aiBox) return;
        aiBox.className = 'ai-suggestion-box mt-2';
        aiBox.classList.remove('hidden', 'ai-fallback-box');
        aiBox.innerHTML = '<div class="flex items-center gap-2 text-purple-600"><i class="fas fa-spinner fa-spin"></i> AI generating... Please wait up to 45 seconds</div>';
        const btn = document.getElementById(prefix + 'ai-' + type + '-btn');
        if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>'; }
        showLoading('AI generating ' + type + '...');
        const classSelect = document.getElementById('schedule_id');
        const classText = classSelect?.options[classSelect.selectedIndex]?.text || 'Class';
        const langChoice = getSelectedPlanLanguage();
        const context = JSON.stringify({ topic: topic, unit: unit, class_name: classText.split(' - ')[0] || 'Class', language: langChoice });

        if (aiRequestTimeout) clearTimeout(aiRequestTimeout);
        aiRequestTimeout = setTimeout(function() {
            hideLoading();
            if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fas fa-robot"></i> AI'; }
            if (retryCount < 2) {
                aiBox.innerHTML = '<div class="text-orange-600"><i class="fas fa-clock"></i> Request timed out. Retrying... (Attempt ' + (retryCount + 2) + ')</div>';
                getAISuggestion(type, formType, retryCount + 1);
            } else {
                aiBox.className = 'ai-fallback-box mt-2';
                aiBox.innerHTML = '<div class="text-orange-800"><p class="font-semibold"><i class="fas fa-clock"></i> AI timed out after multiple retries. Please try again.</p></div>' +
                    '<button onclick="getAISuggestion(\'' + type + '\', \'' + formType + '\', 0)" class="btn btn-warning btn-xs mt-2"><i class="fas fa-redo"></i> Retry AI</button>';
            }
        }, AI_TIMEOUT);

        $.ajax({
            url: window.location.pathname + '?action=ai_suggestion',
            type: 'GET',
            data: { type: type, context: context },
            dataType: 'json',
            timeout: AI_TIMEOUT,
            success: function(response) {
                if (aiRequestTimeout) clearTimeout(aiRequestTimeout);
                hideLoading();
                if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fas fa-robot"></i> AI'; }
                if (response.error) {
                    if (retryCount < 2) {
                        aiBox.innerHTML = '<div class="text-orange-600"><i class="fas fa-exclamation-triangle"></i> ' + escapeHtml(response.error) + ' Retrying... (Attempt ' + (retryCount + 2) + ')</div>';
                        getAISuggestion(type, formType, retryCount + 1);
                    } else {
                        aiBox.className = 'ai-fallback-box mt-2';
                        aiBox.innerHTML = '<div class="text-orange-600"><i class="fas fa-exclamation-triangle"></i> ' + escapeHtml(response.error) + '</div>' +
                            '<button onclick="getAISuggestion(\'' + type + '\', \'' + formType + '\', 0)" class="btn btn-warning btn-xs mt-2"><i class="fas fa-redo"></i> Retry AI</button>';
                    }
                } else if (response.suggestion) {
                    const isFallback = !!response.notice;
                    aiBox.className = isFallback ? 'ai-fallback-box mt-2' : 'ai-suggestion-box mt-2';
                    aiBox.innerHTML = (response.notice ? '<p class="text-orange-600 text-sm mb-2"><i class="fas fa-info-circle"></i> ' + escapeHtml(response.notice) + '</p>' : '') +
                        '<div class="whitespace-pre-wrap">' + escapeHtml(response.suggestion) + '</div>' +
                        '<button onclick="applySuggestion(\'' + type + '\', \'' + formType + '\')" class="btn btn-primary btn-xs mt-2"><i class="fas fa-check"></i> Apply</button>' +
                        '<button onclick="getAISuggestion(\'' + type + '\', \'' + formType + '\', 0)" class="btn btn-warning btn-xs mt-2 ml-2"><i class="fas fa-redo"></i> Regenerate</button>' +
                        '<button onclick="document.getElementById(\'' + prefix + type + '_ai\').classList.add(\'hidden\')" class="btn btn-secondary btn-xs mt-2 ml-2"><i class="fas fa-times"></i> Dismiss</button>';
                }
            },
            error: function() {
                if (aiRequestTimeout) clearTimeout(aiRequestTimeout);
                hideLoading();
                if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fas fa-robot"></i> AI'; }
                if (retryCount < 2) {
                    aiBox.innerHTML = '<div class="text-orange-600"><i class="fas fa-exclamation-triangle"></i> Network error. Retrying... (Attempt ' + (retryCount + 2) + ')</div>';
                    getAISuggestion(type, formType, retryCount + 1);
                } else {
                    aiBox.className = 'ai-fallback-box mt-2';
                    aiBox.innerHTML = '<div class="text-orange-800"><p class="font-semibold"><i class="fas fa-exclamation-triangle"></i> AI service unavailable after multiple attempts.</p></div>' +
                        '<button onclick="getAISuggestion(\'' + type + '\', \'' + formType + '\', 0)" class="btn btn-warning btn-xs mt-2"><i class="fas fa-redo"></i> Retry AI</button>';
                }
            }
        });
    }

    function applySuggestion(type, formType) {
        const prefix = formType === 'edit' ? 'edit_' : '';
        const aiBox = document.getElementById(prefix + type + '_ai');
        const target = document.getElementById(prefix + type);
        if (aiBox && target) {
            const text = aiBox.querySelector('.whitespace-pre-wrap')?.textContent?.trim() || '';
            target.value = text;
            $(target).trigger('input');
            aiBox.classList.add('hidden');
        }
    }

    function getSelectedPlanLanguage() {
        const sel = $('#plan_language').val();
        if (sel === 'Other') return $('#plan_language_other').val().trim();
        return sel || '';
    }

    // ================= NEW: SMART COMPLETE-PLAN GENERATION (structured JSON, source-grounded) =================

    function generateCompletePlan() {
        if (!currentSchedule) { alert('Please select a term and a schedule (class & subject) first.'); return; }
        const week = $('#week').val();
        if (!week) { alert('Please select a week first.'); return; }

        const aiBox = document.getElementById('complete_plan_ai');
        const btn = document.getElementById('generateFullPlanBtn');
        aiBox.className = 'ai-suggestion-box mt-4';
        aiBox.classList.remove('hidden');
        aiBox.innerHTML = '<div class="flex items-center gap-2 text-purple-600"><i class="fas fa-spinner fa-spin"></i> Reading your sources and drafting the full lesson plan... (up to 60 seconds)</div>';
        if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Generating...'; }
        showLoading('AI generating complete plan...');

        const payload = {
            ajax_action: 'generate_plan',
            csrf_token: csrfToken,
            week: week,
            class_name: currentSchedule.display_class_name,
            subject_name: currentSchedule.subject_name,
            day_of_week: currentSchedule.day_of_week,
            duration: formatTimeRangeJS(currentSchedule.start_time, currentSchedule.end_time),
            topic: $('#topic').val().trim(),
            unit: $('#unit').val().trim(),
            resource_ids: JSON.stringify(Array.from(selectedResourceIds)),
            language: getSelectedPlanLanguage(),
        };

        $.ajax({
            url: window.location.pathname,
            type: 'POST',
            data: payload,
            dataType: 'json',
            timeout: 150000,
            success: function(response) {
                hideLoading();
                if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fas fa-magic"></i> Generate Complete Plan'; }
                if (response.success) {
                    applyGeneratedPlan(response.plan, response.used_resource_ids || []);
                    aiBox.className = 'ai-suggestion-box mt-4';
                    aiBox.innerHTML = '<div class="text-green-700"><i class="fas fa-check-circle"></i> Complete plan generated and filled into the form below. Review it, then click <strong>Save Lesson Plan</strong>.</div>' +
                        '<button type="button" onclick="generateCompletePlan()" class="btn btn-warning btn-xs mt-2"><i class="fas fa-redo"></i> Regenerate</button>' +
                        '<button type="button" onclick="document.getElementById(\'complete_plan_ai\').classList.add(\'hidden\')" class="btn btn-secondary btn-xs mt-2 ml-2"><i class="fas fa-times"></i> Dismiss</button>';
                } else {
                    aiBox.className = 'ai-fallback-box mt-4';
                    aiBox.innerHTML = '<div class="text-orange-800"><i class="fas fa-exclamation-triangle"></i> ' + escapeHtml(response.error || 'Generation failed.') + '</div>' +
                        '<button type="button" onclick="generateCompletePlan()" class="btn btn-warning btn-xs mt-2"><i class="fas fa-redo"></i> Retry</button>';
                }
            },
            error: function() {
                hideLoading();
                if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fas fa-magic"></i> Generate Complete Plan'; }
                aiBox.className = 'ai-fallback-box mt-4';
                aiBox.innerHTML = '<div class="text-orange-800">Network or server error while generating. Please try again.</div>' +
                    '<button type="button" onclick="generateCompletePlan()" class="btn btn-warning btn-xs mt-2"><i class="fas fa-redo"></i> Retry</button>';
            }
        });
    }

    // Fills every relevant field directly from the AI's structured JSON — no fragile text parsing.
    function applyGeneratedPlan(plan, usedResourceIds) {
        if (plan.unit) $('#unit').val(plan.unit);
        if (plan.topic) $('#topic').val(plan.topic);
        if (plan.objectives) $('#objectives').val(plan.objectives);
        if (plan.reference) $('#reference').val(plan.reference);
        if (plan.resources) $('#resources').val(plan.resources);
        if (plan.keywords) $('#keywords').val(plan.keywords);
        if (plan.starter) $('#starter').val(plan.starter);
        if (plan.presentation) { $('#presentation').val(plan.presentation); $('#presentation').trigger('input'); }
        if (plan.summary) $('#summary').val(plan.summary);
        $('#strand').val(plan.strand || '');
        $('#sub_strand').val(plan.sub_strand || '');
        $('#content_standard').val(plan.content_standard || '');
        $('#indicator').val(plan.indicator || '');
        $('#source_resource_ids').val(JSON.stringify(usedResourceIds || []));
        $('#ai_generated').val('1');
    }

    // ================= NEW: ONE-CLICK AJAX SAVE (create + update) =================
    // Clicking Save/Update now posts to ajax_action=save_plan and gets a JSON
    // response back — no full page reload, no extra steps, and the specific
    // field (if any) that was too long is reported clearly instead of a raw
    // database error.

    function buildPlanPayload(formSelector, isUpdate) {
        const $form = $(formSelector);
        const data = {
            ajax_action: 'save_plan',
            csrf_token: csrfToken,
            action: isUpdate ? 'update' : 'submit',
            plan_id: isUpdate ? $('#edit_plan_id').val() : 0,
            term_id: isUpdate ? $('#edit_term_id').val() : $('#term_id').val(),
            week: isUpdate ? $('#edit_week').val() : $('#week').val(),
            class_id: isUpdate ? $('#edit_class_id').val() : $('#class_id').val(),
            subject_id: isUpdate ? $('#edit_subject_id').val() : $('#subject_id').val(),
            unit: isUpdate ? $('#edit_unit').val() : $('#unit').val(),
            topic: isUpdate ? $('#edit_topic').val() : $('#topic').val(),
            objectives: isUpdate ? $('#edit_objectives').val() : $('#objectives').val(),
            reference: isUpdate ? $('#edit_reference').val() : $('#reference').val(),
            resources: isUpdate ? $('#edit_resources').val() : $('#resources').val(),
            keywords: isUpdate ? $('#edit_keywords').val() : $('#keywords').val(),
            starter: isUpdate ? $('#edit_starter').val() : $('#starter').val(),
            presentation: isUpdate ? $('#edit_presentation').val() : $('#presentation').val(),
            summary: isUpdate ? $('#edit_summary').val() : $('#summary').val(),
            strand: isUpdate ? $('#edit_strand').val() : $('#strand').val(),
            sub_strand: isUpdate ? $('#edit_sub_strand').val() : $('#sub_strand').val(),
            content_standard: isUpdate ? $('#edit_content_standard').val() : $('#content_standard').val(),
            indicator: isUpdate ? $('#edit_indicator').val() : $('#indicator').val(),
            source_resource_ids: isUpdate ? $('#edit_source_resource_ids').val() : $('#source_resource_ids').val(),
            ai_generated: isUpdate ? $('#edit_ai_generated').val() : $('#ai_generated').val(),
        };
        return data;
    }

    function reloadPlansTable() {
        $.get(window.location.pathname + '?action=list_plans', {}, function(r) {
            if (!r || !r.rows) return;
            if (!r.rows.length) {
                $('#plansTableWrapper').html('<div class="alert alert-info" id="noPlansAlert"><i class="fas fa-info-circle"></i><p>No plans created yet. Create your first plan below!</p></div>');
                return;
            }
            let rowsHtml = '';
            r.rows.forEach((p, index) => {
                rowsHtml += '<tr>' +
                    '<td>' + (index + 1) + '</td>' +
                    '<td class="font-medium">' + escapeHtml(p.display_class_name) + '</td>' +
                    '<td>' + escapeHtml(p.subject_name || 'N/A') + '</td>' +
                    '<td>' + escapeHtml((p.topic || 'N/A').substring(0, 30)) + (p.ai_generated ? ' <span class="ai-badge ml-1" style="font-size:0.6rem;padding:.1rem .5rem;"><i class="fas fa-robot"></i></span>' : '') + '</td>' +
                    '<td>' + escapeHtml(p.unit || 'N/A') + '</td>' +
                    '<td>Week ' + p.week + '</td>' +
                    '<td>' + escapeHtml(p.term_name) + '</td>' +
                    '<td>' + escapeHtml(p.academic_year) + '</td>' +
                    '<td class="text-sm">' + escapeHtml(p.created_at_display) + '</td>' +
                    '<td><div class="flex gap-1">' +
                        '<button onclick="viewPlan(' + p.id + ')" class="btn btn-info btn-xs"><i class="fas fa-eye"></i></button>' +
                        '<button onclick="editPlan(' + p.id + ')" class="btn btn-warning btn-xs"><i class="fas fa-edit"></i></button>' +
                        '<button onclick="deletePlan(' + p.id + ')" class="btn btn-danger btn-xs"><i class="fas fa-trash"></i></button>' +
                    '</div></td>' +
                '</tr>';
            });
            $('#plansTableWrapper').html(
                '<div class="table-container"><table><thead><tr>' +
                '<th>#</th><th>Class</th><th>Subject</th><th>Topic</th><th>Unit</th><th>Week</th><th>Term</th><th>Year</th><th>Created</th><th>Actions</th>' +
                '</tr></thead><tbody id="plansTableBody">' + rowsHtml + '</tbody></table></div>'
            );
        }, 'json');
    }

    // CREATE: click Save -> instantly saves via AJAX, no page reload.
    $('#oxfordLessonPlanForm').on('submit', function(e) {
        e.preventDefault();
        const $btn = $('#submit-btn');
        $btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Saving...');
        showLoading('Saving lesson plan...');
        $.post(window.location.pathname, buildPlanPayload('#oxfordLessonPlanForm', false), function(r) {
            hideLoading();
            $btn.prop('disabled', false).html('<i class="fas fa-save"></i> Save Lesson Plan');
            if (r.success) {
                showToast(r.message || 'Lesson plan saved successfully!', 'success');
                reloadPlansTable();
                document.getElementById('oxfordLessonPlanForm').reset();
                $('#schedule-container').empty();
                $('#complete_plan_ai, #objectives_ai, #resources_ai, #keywords_ai, #starter_ai, #presentation_ai, #summary_ai').addClass('hidden');
                $('#presentation-counter').text('0/20000');
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
            $btn.prop('disabled', false).html('<i class="fas fa-save"></i> Save Lesson Plan');
            showToast('Network error while saving. Please try again.', 'error');
        });
    });

    // UPDATE: click Update -> instantly saves via AJAX, closes modal, refreshes table.
    $('#editLessonPlanForm').on('submit', function(e) {
        e.preventDefault();
        const $btn = $('#edit-submit-btn');
        $btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Saving...');
        showLoading('Saving lesson plan...');
        $.post(window.location.pathname, buildPlanPayload('#editLessonPlanForm', true), function(r) {
            hideLoading();
            $btn.prop('disabled', false).html('<i class="fas fa-save"></i> Update Lesson Plan');
            if (r.success) {
                showToast(r.message || 'Lesson plan updated successfully!', 'success');
                reloadPlansTable();
                closeEditModal();
            } else if (r.errors && r.errors.length) {
                showToast(r.errors.join('<br>'), 'error');
            } else {
                showToast(r.error || 'Could not update the lesson plan.', 'error');
            }
        }, 'json').fail(function() {
            hideLoading();
            $btn.prop('disabled', false).html('<i class="fas fa-save"></i> Update Lesson Plan');
            showToast('Network error while saving. Please try again.', 'error');
        });
    });

    // DELETE: also AJAX now, so it matches the rest of the flow.
    $('#deleteForm').on('submit', function(e) {
        e.preventDefault();
        showLoading('Deleting...');
        $.post(window.location.pathname, {
            ajax_action: 'delete_plan',
            csrf_token: csrfToken,
            plan_id: $('#delete_plan_id').val()
        }, function(r) {
            hideLoading();
            closeDeleteModal();
            if (r.success) {
                showToast(r.message || 'Lesson plan deleted.', 'success');
                reloadPlansTable();
            } else {
                showToast(r.error || 'Could not delete the lesson plan.', 'error');
            }
        }, 'json').fail(function() {
            hideLoading();
            closeDeleteModal();
            showToast('Network error while deleting. Please try again.', 'error');
        });
    });

    function viewPlan(id) {
        showLoading('Loading...');
        $.get(window.location.pathname + '?action=view_plan', { plan_id: id }, function(r) {
            hideLoading();
            if (r.error) { alert(r.error); return; }
            const p = r.plan, s = r.schedule;
            let c = '<div class="view-detail"><h4><i class="fas fa-info-circle text-blue-500"></i> Overview</h4>' +
                '<div class="grid grid-cols-2 md:grid-cols-3 gap-4 text-sm">' +
                '<div><strong>Class:</strong> ' + escapeHtml(p.display_class_name) + '</div>' +
                '<div><strong>Subject:</strong> ' + escapeHtml(p.subject_name || 'N/A') + '</div>' +
                '<div><strong>Week:</strong> ' + p.week + '</div>' +
                '<div><strong>Term:</strong> ' + escapeHtml(p.term_name) + '</div>' +
                '<div><strong>Unit:</strong> ' + escapeHtml(p.unit || 'N/A') + '</div>' +
                '<div><strong>Topic:</strong> ' + escapeHtml(p.topic || 'N/A') + '</div></div></div>';
            if (s) c += '<div class="view-detail"><h4><i class="fas fa-clock text-green-500"></i> Schedule</h4><p><span class="schedule-day-badge">' + escapeHtml(s.day_of_week) + '</span> &nbsp; ' + escapeHtml(formatTimeRangeJS(s.start_time, s.end_time)) + '</p></div>';
            if (p.strand || p.sub_strand || p.content_standard || p.indicator) {
                c += '<div class="view-detail"><h4>Curriculum Alignment</h4>' +
                    (p.strand ? '<p><strong>Strand:</strong> ' + escapeHtml(p.strand) + '</p>' : '') +
                    (p.sub_strand ? '<p><strong>Sub-strand:</strong> ' + escapeHtml(p.sub_strand) + '</p>' : '') +
                    (p.content_standard ? '<p><strong>Content Standard:</strong> ' + escapeHtml(p.content_standard) + '</p>' : '') +
                    (p.indicator ? '<p><strong>Indicator:</strong> ' + escapeHtml(p.indicator) + '</p>' : '') +
                    '</div>';
            }
            c += '<div class="view-detail"><h4>Objectives</h4><p>' + escapeHtml(p.objectives || 'N/A') + '</p></div>' +
                '<div class="view-detail"><h4>Reference</h4><p>' + escapeHtml(p.reference || 'N/A') + '</p></div>' +
                '<div class="view-detail"><h4>Resources</h4><p>' + escapeHtml(p.resources || 'N/A') + '</p></div>' +
                '<div class="view-detail"><h4>Keywords</h4><p>' + escapeHtml(p.keywords || 'N/A') + '</p></div>' +
                '<div class="view-detail"><h4>Starter</h4><p>' + escapeHtml(p.starter || 'N/A') + '</p></div>' +
                '<div class="view-detail"><h4>Presentation</h4><p>' + escapeHtml(p.presentation || 'N/A') + '</p></div>' +
                '<div class="view-detail"><h4>Summary</h4><p>' + escapeHtml(p.summary || 'N/A') + '</p></div>';
            $('#viewContent').html(c);
            $('#viewModal').show();
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
            $('#edit_unit').val(p.unit || '');
            $('#edit_topic').val(p.topic || '');
            $('#edit_objectives').val(p.objectives || '');
            $('#edit_reference').val(p.reference || '');
            $('#edit_resources').val(p.resources || '');
            $('#edit_keywords').val(p.keywords || '');
            $('#edit_starter').val(p.starter || '');
            $('#edit_presentation').val(p.presentation || '');
            $('#edit_summary').val(p.summary || '');
            $('#edit_strand').val(p.strand || '');
            $('#edit_sub_strand').val(p.sub_strand || '');
            $('#edit_content_standard').val(p.content_standard || '');
            $('#edit_indicator').val(p.indicator || '');
            $('#edit_source_resource_ids').val(p.source_resource_ids || '');
            $('#edit_ai_generated').val(p.ai_generated || 0);
            $('.ai-suggestion-box, .ai-fallback-box').addClass('hidden');
            $('#editModal').show();
        }, 'json').fail(function() { hideLoading(); alert('Error loading plan.'); });
    }

    function deletePlan(id) {
        $('#delete_plan_id').val(id);
        $('#deleteModal').show();
    }

    function closeViewModal() { $('#viewModal').hide(); }
    function closeEditModal() { $('#editModal').hide(); }
    function closeDeleteModal() { $('#deleteModal').hide(); }
    window.onclick = function(e) { if (e.target.classList.contains('modal')) e.target.style.display = 'none'; };

    $(document).ready(function() {
        const defaultTerm = $('#term_id').val();
        if (defaultTerm) loadSchedules(defaultTerm);

        $('#presentation').on('input', function() {
            const len = $(this).val().length;
            $('#presentation-counter').text(len + '/20000');
        });

        hideLoading();
    });

    window.addEventListener('beforeunload', function(e) {
        const hasContent = $('#objectives').val() || $('#presentation').val();
        if (hasContent && !$('#editModal').is(':visible')) {
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