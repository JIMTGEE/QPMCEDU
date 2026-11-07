<?php
/**
 * view_progress.php — Academic Progress Viewer (QueensPa School)
 *
 * FIX HISTORY (latest):
 *  16.   get_class_periods — removed term_id filter entirely.
 *        academic_year alone is the correct scope; term_id from academic_calendar
 *        may not match the term_id stored in entries (different resolution paths).
 *  17.   get_class_progress — subject list now UNIONs class_schedules subjects
 *        WITH subjects that actually have entries for this class/year, so data
 *        entered for a subject that isn't in class_schedules is still visible.
 *  18.   get_class_progress — removed term_id filter for the same reason as #16.
 */

ob_start();
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ── Auth ─────────────────────────────────────────────────────────────────────
if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'teacher') {
    $_SESSION['error_msg'] = "Please log in as a teacher.";
    header("Location: /login.php");
    exit();
}

ini_set('log_errors', 1);
ini_set('error_log', dirname(__FILE__) . '/error.log');
error_reporting(E_ALL);

$db_path = $_SERVER['DOCUMENT_ROOT'] . '/config/db.php';
if (!file_exists($db_path)) {
    $_SESSION['error_msg'] = "System error: Server configuration error.";
    header("Location: /login.php");
    exit();
}
require_once($db_path);

if (!isset($conn) || !$conn || mysqli_connect_error()) {
    $_SESSION['error_msg'] = "System error: Database connection failed.";
    header("Location: /login.php");
    exit();
}

$teacher_id = (int)$_SESSION['user_id'];

// ── CSRF ──────────────────────────────────────────────────────────────────────
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// ── Class name map ────────────────────────────────────────────────────────────
$class_name_map = [
    'Esther Foundation' => 'Crèche',    'Sheba 1'    => 'Nursery 1',
    'Sheba 2'           => 'Nursery 2', 'Yaasant 1'  => 'KG 1',
    'Yaasant 2'         => 'KG 2',      'Cleopatra 1'=> 'Basic 1',
    'Cleopatra 2'       => 'Basic 2',   'Nefertiti 1'=> 'Basic 3',
    'Nefertiti 2'       => 'Basic 4',   'Malika 1'   => 'Basic 5',
    'Malika 2'          => 'Basic 6',   'Makeda 1'   => 'Basic 7 (JHS 1)',
    'Makeda 2'          => 'Basic 8 (JHS 2)',         'Majesty'    => 'Basic 9 (JHS 3)',
];

function getFriendlyClassName(string $dbName): string {
    global $class_name_map;
    foreach ($class_name_map as $key => $label) {
        if (stripos($dbName, $key) !== false) return $label;
    }
    return $dbName;
}

// ── generateBiWeeklyPeriods ───────────────────────────────────────────────────
function generateBiWeeklyPeriods($start_date, $end_date) {
    $periods = [];
    if (!$start_date || !$end_date) return $periods;
    try {
        $current    = new DateTime($start_date);
        $end        = new DateTime($end_date);
        $period_num = 1;
        while ($current <= $end && $period_num <= 20) {
            $p_start = clone $current;
            $p_end   = clone $current;
            $p_end->modify('+13 days');
            if ($p_end > $end) $p_end = clone $end;
            $periods[] = [
                'id'         => $period_num,
                'start_date' => $p_start->format('Y-m-d'),
                'end_date'   => $p_end->format('Y-m-d'),
                'label'      => sprintf('Period %d: %s - %s',
                    $period_num,
                    $p_start->format('M d'),
                    $p_end->format('M d, Y')
                ),
            ];
            $current->modify('+14 days');
            $period_num++;
        }
    } catch (Exception $e) {
        error_log("generateBiWeeklyPeriods error: " . $e->getMessage());
    }
    return $periods;
}

// ── Current academic year ─────────────────────────────────────────────────────
$today         = date('Y-m-d');
$current_month = (int)date('m');
$current_year  = (int)date('Y');
$current_academic_year = $current_month >= 9
    ? "$current_year/" . ($current_year + 1)
    : ($current_year - 1) . "/$current_year";

// ── Term resolution ───────────────────────────────────────────────────────────
$current_term_id   = null;
$current_term_name = 'N/A';
$term_start        = null;
$term_end          = null;

$table_check = $conn->query("SHOW TABLES LIKE 'academic_calendar'");
if ($table_check && $table_check->num_rows > 0) {

    $stmt = $conn->prepare(
        "SELECT id, term_name, year, start_date, end_date
         FROM academic_calendar
         WHERE year = ? AND start_date <= ? AND end_date >= ?
         ORDER BY start_date ASC LIMIT 1"
    );
    if ($stmt) {
        $stmt->bind_param("sss", $current_academic_year, $today, $today);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row) {
            $current_term_id   = (int)$row['id'];
            $current_term_name = $row['term_name'];
            $term_start        = $row['start_date'];
            $term_end          = $row['end_date'];
        }
    }

    if (!$current_term_id) {
        $stmt = $conn->prepare(
            "SELECT id, term_name, year, start_date, end_date
             FROM academic_calendar
             WHERE year = ? AND start_date > ?
             ORDER BY start_date ASC LIMIT 1"
        );
        if ($stmt) {
            $stmt->bind_param("ss", $current_academic_year, $today);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($row) {
                $current_term_id   = (int)$row['id'];
                $current_term_name = $row['term_name'] . ' (Upcoming)';
                $term_start        = $row['start_date'];
                $term_end          = $row['end_date'];
            }
        }
    }

    if (!$current_term_id) {
        $stmt = $conn->prepare(
            "SELECT id, term_name, year, start_date, end_date
             FROM academic_calendar
             WHERE year = ?
             ORDER BY end_date DESC LIMIT 1"
        );
        if ($stmt) {
            $stmt->bind_param("s", $current_academic_year);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($row) {
                $current_term_id   = (int)$row['id'];
                $current_term_name = $row['term_name'] . ' (Previous)';
                $term_start        = $row['start_date'];
                $term_end          = $row['end_date'];
            }
        }
    }
}

// ── Check student_classes columns ─────────────────────────────────────────────
$sc_cols = [];
$sc_res  = $conn->query("SHOW COLUMNS FROM student_classes");
if ($sc_res) { while ($c = $sc_res->fetch_assoc()) $sc_cols[] = $c['Field']; }
$sc_has_academic_year = in_array('academic_year', $sc_cols);

// ═════════════════════════════════════════════════════════════════════════════
// AJAX
// ═════════════════════════════════════════════════════════════════════════════
if (isset($_GET['ajax']) || isset($_POST['ajax'])) {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-cache, no-store, must-revalidate');

    $action = trim($_REQUEST['action'] ?? '');

    // ── get_dashboard ─────────────────────────────────────────────────────────
    if ($action === 'get_dashboard') {
        $all_classes_res = $conn->query(
            "SELECT id, class_name, department_id FROM classes ORDER BY department_id, class_name"
        );
        $all_classes = $all_classes_res ? $all_classes_res->fetch_all(MYSQLI_ASSOC) : [];

        $stmt = $conn->prepare(
            "SELECT DISTINCT c.id as class_id, c.class_name, cs.subject_id, s.subject_name
             FROM class_schedules cs
             JOIN classes c  ON cs.class_id  = c.id
             JOIN subjects s ON cs.subject_id = s.id
             WHERE cs.teacher_id = ? AND cs.academic_year = ?
             ORDER BY c.class_name, s.subject_name"
        );
        $teacher_subjects = [];
        if ($stmt) {
            $stmt->bind_param("is", $teacher_id, $current_academic_year);
            $stmt->execute();
            $teacher_subjects = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
        }

        $class_subjects = []; $teacher_class_ids = [];
        foreach ($teacher_subjects as $ts) {
            $cid = (int)$ts['class_id'];
            $class_subjects[$cid]['class_name'] = $ts['class_name'];
            $class_subjects[$cid]['subjects'][] = ['id' => $ts['subject_id'], 'name' => $ts['subject_name']];
            if (!in_array($cid, $teacher_class_ids)) $teacher_class_ids[] = $cid;
        }

        $fallback_periods = $term_start ? generateBiWeeklyPeriods($term_start, $term_end) : [];

        $current_period = null;
        foreach ($fallback_periods as $p) {
            if ($today >= $p['start_date'] && $today <= $p['end_date']) {
                $current_period = $p; break;
            }
        }
        if (!$current_period && !empty($fallback_periods)) $current_period = $fallback_periods[0];

        echo json_encode([
            'success'           => true,
            'all_classes'       => $all_classes,
            'class_subjects'    => $class_subjects,
            'teacher_class_ids' => $teacher_class_ids,
            'academic_year'     => $current_academic_year,
            'term_name'         => $current_term_name,
            'term_id'           => $current_term_id,
            'fallback_periods'  => $fallback_periods,
            'current_period'    => $current_period,
            'csrf_token'        => $csrf_token,
        ]);
        exit();
    }

    // ── get_class_periods ─────────────────────────────────────────────────────
    // FIX 16: NO term_id filter — academic_year is the only scope needed.
    // Entries may have been saved under a different term_id than what
    // academic_calendar resolves to today.
    if ($action === 'get_class_periods') {
        $class_id = (int)($_REQUEST['class_id'] ?? 0);
        if (!$class_id) {
            echo json_encode(['success' => false, 'error' => 'Missing class_id']);
            exit();
        }

        // Single query — no term_id branch
        $stmt = $conn->prepare(
            "SELECT DISTINCT
                DATE_FORMAT(period_start_date, '%Y-%m-%d') AS start_date,
                DATE_FORMAT(period_end_date,   '%Y-%m-%d') AS end_date
             FROM academic_progress_entries
             WHERE class_id      = ?
               AND academic_year = ?
               AND entry_period  = 'bi-weekly'
             ORDER BY period_start_date ASC"
        );

        $db_periods = [];
        if ($stmt) {
            $stmt->bind_param("is", $class_id, $current_academic_year);
            $stmt->execute();
            $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();

            $generated = $term_start ? generateBiWeeklyPeriods($term_start, $term_end) : [];
            $gen_index = [];
            foreach ($generated as $gp) {
                $gen_index[$gp['start_date']] = $gp;
            }

            $n = 1;
            foreach ($rows as $row) {
                if (isset($gen_index[$row['start_date']])) {
                    $gp = $gen_index[$row['start_date']];
                    $db_periods[] = [
                        'id'         => $gp['id'],
                        'start_date' => $row['start_date'],
                        'end_date'   => $row['end_date'],
                        'label'      => $gp['label'],
                        'source'     => 'db',
                    ];
                } else {
                    $sd = new DateTime($row['start_date']);
                    $ed = new DateTime($row['end_date']);
                    $db_periods[] = [
                        'id'         => $n,
                        'start_date' => $row['start_date'],
                        'end_date'   => $row['end_date'],
                        'label'      => sprintf('Period %d: %s - %s',
                            $n, $sd->format('M d'), $ed->format('M d, Y')
                        ),
                        'source'     => 'db',
                    ];
                }
                $n++;
            }
        } else {
            error_log("get_class_periods prepare failed: " . $conn->error);
        }

        $current_period = null;
        foreach ($db_periods as $p) {
            if ($today >= $p['start_date'] && $today <= $p['end_date']) {
                $current_period = $p; break;
            }
        }
        if (!$current_period && !empty($db_periods)) {
            foreach (array_reverse($db_periods) as $p) {
                if ($p['end_date'] <= $today) { $current_period = $p; break; }
            }
        }
        if (!$current_period && !empty($db_periods)) {
            $current_period = end($db_periods);
        }

        echo json_encode([
            'success'        => true,
            'periods'        => $db_periods,
            'current_period' => $current_period,
            'has_data'       => !empty($db_periods),
        ]);
        exit();
    }

    // ── get_class_progress ────────────────────────────────────────────────────
    if ($action === 'get_class_progress') {
        $class_id     = (int)($_REQUEST['class_id']    ?? 0);
        $period_start = trim($_REQUEST['period_start'] ?? '');
        $period_end   = trim($_REQUEST['period_end']   ?? '');

        if (!$class_id || !$period_start || !$period_end) {
            echo json_encode(['success' => false, 'error' => 'Missing parameters']);
            exit();
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $period_start) ||
            !preg_match('/^\d{4}-\d{2}-\d{2}$/', $period_end)) {
            echo json_encode(['success' => false, 'error' => 'Invalid date format']);
            exit();
        }

        // FIX 17: UNION subjects from class_schedules WITH subjects that
        // actually have entries for this class/year, so data entered for a
        // subject not in class_schedules is still surfaced.
        $stmt = $conn->prepare(
            "SELECT DISTINCT s.id, s.subject_name
             FROM subjects s
             WHERE s.id IN (
                 -- subjects assigned to this class in the schedule
                 SELECT cs.subject_id
                 FROM class_schedules cs
                 WHERE cs.class_id = ? AND cs.academic_year = ?

                 UNION

                 -- subjects that actually have entries for this class/year
                 SELECT ape.subject_id
                 FROM academic_progress_entries ape
                 WHERE ape.class_id = ? AND ape.academic_year = ?
             )
             ORDER BY s.subject_name"
        );
        $subjects = [];
        if ($stmt) {
            $stmt->bind_param(
                "isis",
                $class_id, $current_academic_year,
                $class_id, $current_academic_year
            );
            $stmt->execute();
            $subjects = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
        }

        // Students in this class
        if ($sc_has_academic_year) {
            $stu_stmt = $conn->prepare(
                "SELECT u.id, u.name FROM student_classes sc
                 JOIN users u ON sc.user_id = u.id
                 WHERE sc.class_id = ? AND sc.academic_year = ? AND u.role = 'student'
                 ORDER BY u.name"
            );
            if ($stu_stmt) {
                $stu_stmt->bind_param("is", $class_id, $current_academic_year);
                $stu_stmt->execute();
                $students = $stu_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                $stu_stmt->close();
            } else { $students = []; }
        } else {
            $stu_stmt = $conn->prepare(
                "SELECT u.id, u.name FROM student_classes sc
                 JOIN users u ON sc.user_id = u.id
                 WHERE sc.class_id = ? AND u.role = 'student'
                 ORDER BY u.name"
            );
            if ($stu_stmt) {
                $stu_stmt->bind_param("i", $class_id);
                $stu_stmt->execute();
                $students = $stu_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                $stu_stmt->close();
            } else { $students = []; }
        }

        $subject_ids         = array_column($subjects, 'id');
        $progress_by_subject = [];

        if (!empty($subject_ids)) {
            $ph = implode(',', array_fill(0, count($subject_ids), '?'));

            // FIX 18: NO term_id filter — match by class/subjects/year/dates only.
            // term_id stored in entries may differ from what academic_calendar
            // resolves to; year + exact dates is a unique, reliable key.
            $types  = "i" . str_repeat("i", count($subject_ids)) . "sss";
            $params = array_merge(
                [$class_id],
                array_map('intval', $subject_ids),
                [$current_academic_year, $period_start, $period_end]
            );

            $stmt = $conn->prepare("
                SELECT
                    ape.student_id, ape.subject_id,
                    ape.class_practices_raw,  ape.class_practices_max,
                    ape.assignments_raw,      ape.assignments_max,
                    ape.cat_raw,              ape.cat_max,
                    ape.group_project_raw,    ape.group_project_max,
                    ape.total_scaled,         ape.scaled_scores,
                    ape.comments,             ape.status,
                    u.name   AS student_name,
                    s.subject_name
                FROM academic_progress_entries ape
                JOIN users    u ON ape.student_id = u.id
                JOIN subjects s ON ape.subject_id = s.id
                WHERE ape.class_id      = ?
                  AND ape.subject_id    IN ($ph)
                  AND ape.academic_year  = ?
                  AND DATE(ape.period_start_date) = ?
                  AND DATE(ape.period_end_date)   = ?
                  AND ape.entry_period   = 'bi-weekly'
                ORDER BY s.subject_name, u.name");

            if ($stmt) {
                $stmt->bind_param($types, ...$params);
                $stmt->execute();
                $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                $stmt->close();
                foreach ($rows as $r) {
                    $progress_by_subject[(int)$r['subject_id']][] = $r;
                }
            } else {
                error_log("get_class_progress prepare failed: " . $conn->error);
            }
        }

        function computeScaled(array $e): float {
            $ts = isset($e['total_scaled']) ? (float)$e['total_scaled'] : 0.0;
            if ($ts > 0) return $ts;
            $cp = ($e['class_practices_max'] > 0) ? ((float)$e['class_practices_raw'] / (float)$e['class_practices_max']) * 25 : 0.0;
            $as = ($e['assignments_max']     > 0) ? ((float)$e['assignments_raw']     / (float)$e['assignments_max'])     * 25 : 0.0;
            $ct = ($e['cat_max']             > 0) ? ((float)$e['cat_raw']             / (float)$e['cat_max'])             * 25 : 0.0;
            $gp = ($e['group_project_max']   > 0) ? ((float)$e['group_project_raw']   / (float)$e['group_project_max'])   * 25 : 0.0;
            return round($cp + $as + $ct + $gp, 2);
        }

        $subject_summaries = [];
        foreach ($subjects as $subj) {
            $sid     = (int)$subj['id'];
            $entries = $progress_by_subject[$sid] ?? [];

            $valid_scores = array_map('computeScaled', $entries);
            $count        = count($valid_scores);
            $avg          = $count ? round(array_sum($valid_scores) / $count, 1) : null;
            $highest      = $count ? round((float)max($valid_scores), 1)         : null;
            $lowest       = $count ? round((float)min($valid_scores), 1)         : null;

            $student_scores = [];
            foreach ($students as $stu) {
                $entry = null;
                foreach ($entries as $e) {
                    if ((int)$e['student_id'] === (int)$stu['id']) { $entry = $e; break; }
                }
                $ts = ($entry !== null) ? computeScaled($entry) : null;
                $student_scores[] = [
                    'student_id'          => (int)$stu['id'],
                    'student_name'        => $stu['name'],
                    'total_scaled'        => $ts,
                    'class_practices_raw' => $entry !== null ? (float)$entry['class_practices_raw'] : null,
                    'class_practices_max' => $entry !== null ? (float)$entry['class_practices_max'] : null,
                    'assignments_raw'     => $entry !== null ? (float)$entry['assignments_raw']      : null,
                    'assignments_max'     => $entry !== null ? (float)$entry['assignments_max']      : null,
                    'cat_raw'             => $entry !== null ? (float)$entry['cat_raw']              : null,
                    'cat_max'             => $entry !== null ? (float)$entry['cat_max']              : null,
                    'group_project_raw'   => $entry !== null ? (float)$entry['group_project_raw']    : null,
                    'group_project_max'   => $entry !== null ? (float)$entry['group_project_max']    : null,
                    'comments'            => $entry !== null ? ($entry['comments'] ?? '')            : '',
                    'status'              => $entry !== null ? ($entry['status']   ?? 'draft')       : null,
                    'has_data'            => $entry !== null,
                ];
            }

            $subject_summaries[] = [
                'subject_id'     => $sid,
                'subject_name'   => $subj['subject_name'],
                'entry_count'    => count($entries),
                'average'        => $avg,
                'highest'        => $highest,
                'lowest'         => $lowest,
                'student_scores' => $student_scores,
            ];
        }

        echo json_encode([
            'success'           => true,
            'subjects'          => $subjects,
            'students'          => $students,
            'subject_summaries' => $subject_summaries,
            'student_count'     => count($students),
            'period_start'      => $period_start,
            'period_end'        => $period_end,
        ]);
        exit();
    }

    // ── get_ai_insight ────────────────────────────────────────────────────────
    if ($action === 'get_ai_insight') {
        $class_id   = (int)($_REQUEST['class_id']  ?? 0);
        $class_name = trim($_REQUEST['class_name'] ?? '');
        if (!$class_id) {
            echo json_encode(['success' => false, 'error' => 'Missing class ID']);
            exit();
        }

        // AI insight also drops term_id filter for consistency
        $ai_stmt = $conn->prepare(
            "SELECT ape.total_scaled,
                    ape.class_practices_raw, ape.class_practices_max,
                    ape.assignments_raw,     ape.assignments_max,
                    ape.cat_raw,             ape.cat_max,
                    ape.group_project_raw,   ape.group_project_max,
                    u.name AS student_name, s.subject_name
             FROM academic_progress_entries ape
             JOIN users    u ON ape.student_id = u.id
             JOIN subjects s ON ape.subject_id = s.id
             WHERE ape.class_id     = ?
               AND ape.academic_year = ?
               AND ape.entry_period  = 'bi-weekly'
               AND ape.status        = 'submitted'
             ORDER BY s.subject_name, u.name"
        );
        $all_progress = [];
        if ($ai_stmt) {
            $ai_stmt->bind_param("is", $class_id, $current_academic_year);
            $ai_stmt->execute();
            $all_progress = $ai_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $ai_stmt->close();
        }

        if (empty($all_progress)) {
            echo json_encode(['success' => false, 'error' => 'No submitted bi-weekly data available for AI analysis']);
            exit();
        }

        $subj_summary = [];
        foreach ($all_progress as $p) {
            $subj = $p['subject_name'];
            if (!isset($subj_summary[$subj])) $subj_summary[$subj] = ['scores' => [], 'students' => []];
            $ts = computeScaled($p);
            $subj_summary[$subj]['scores'][]   = $ts;
            $subj_summary[$subj]['students'][] = $p['student_name'] . ': ' . $ts;
        }

        $friendly = getFriendlyClassName($class_name);
        $data_str = "Class: $friendly\n";
        foreach ($subj_summary as $subj => $d) {
            if (!empty($d['scores'])) {
                $cnt  = count($d['scores']);
                $avg  = round(array_sum($d['scores']) / $cnt, 1);
                $top5 = implode(', ', array_slice($d['students'], 0, 5));
                $data_str .= "$subj — Avg: {$avg}%, Entries: $cnt, Sample: $top5\n";
            }
        }

        $api_key = $apiKey = getenv('GEMINI_API_KEY');
        $prompt  = "You are an educational assistant for QueensPa School, Ghana. "
            . "Analyze this student performance data and provide:\n"
            . "1. A 2-3 sentence summary of overall class performance\n"
            . "2. Identify strongest and weakest subjects\n"
            . "3. One specific, actionable teaching recommendation\n\n"
            . "Data:\n$data_str\n\n"
            . "Keep your response under 200 words. Use plain text, no markdown.";

        $ch = curl_init('https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash-latest:generateContent');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'X-goog-api-key: ' . $api_key],
            CURLOPT_POSTFIELDS     => json_encode([
                'contents'         => [['parts' => [['text' => $prompt]]]],
                'generationConfig' => ['temperature' => 0.7, 'maxOutputTokens' => 320],
            ]),
            CURLOPT_TIMEOUT        => 20,
        ]);
        $resp      = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_err  = curl_error($ch);
        curl_close($ch);

        if ($http_code === 200 && $resp) {
            $decoded = json_decode($resp, true);
            $text    = $decoded['candidates'][0]['content']['parts'][0]['text'] ?? 'Unable to generate insights.';
            echo json_encode(['success' => true, 'insight' => trim($text)]);
        } else {
            $err = $curl_err ?: ('HTTP ' . $http_code);
            error_log("AI insight error: $err | Response: " . substr($resp, 0, 300));
            echo json_encode(['success' => false, 'error' => 'AI service unavailable (' . $err . ')']);
        }
        exit();
    }

    echo json_encode(['success' => false, 'error' => 'Unknown action: ' . htmlspecialchars($action)]);
    exit();
}

include $_SERVER['DOCUMENT_ROOT'] . '/templates/teacher_header.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="csrf-token" content="<?= htmlspecialchars($csrf_token) ?>">
<title>Academic Progress · QueensPa School</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz,wght@0,9..40,300;0,9..40,400;0,9..40,500;0,9..40,600;0,9..40,700;1,9..40,400&family=DM+Serif+Display:ital@0;1&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.2/dist/chart.umd.min.js"></script>
<style>
:root {
  --ink:#0d1117;--ink-2:#374151;--ink-3:#6b7280;--ink-4:#9ca3af;
  --line:#e5e7eb;--line-2:#f3f4f6;
  --surface:#ffffff;--surface-2:#f9fafb;--surface-3:#f3f4f6;
  --teal:#0d9488;--teal-l:#ccfbf1;--teal-d:#0f766e;
  --amber:#d97706;--amber-l:#fef3c7;
  --red:#dc2626;--red-l:#fee2e2;
  --blue:#2563eb;--blue-l:#dbeafe;
  --emerald:#059669;--emerald-l:#d1fae5;
  --radius:12px;--radius-sm:8px;
  --shadow:0 1px 3px rgba(0,0,0,.06),0 1px 2px rgba(0,0,0,.04);
  --shadow-md:0 4px 12px rgba(0,0,0,.08),0 2px 4px rgba(0,0,0,.04);
  --shadow-lg:0 12px 32px rgba(0,0,0,.1),0 4px 8px rgba(0,0,0,.06);
  --font-body:'DM Sans',system-ui,sans-serif;
  --font-head:'DM Serif Display',Georgia,serif;
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
body{font-family:var(--font-body);background:var(--surface-2);color:var(--ink);-webkit-font-smoothing:antialiased;line-height:1.6;}
.hidden{display:none!important;}
.topbar{background:var(--surface);border-bottom:1px solid var(--line);position:sticky;top:0;z-index:50;}
.topbar-inner{max-width:1280px;margin:0 auto;padding:0 1.25rem;display:flex;align-items:center;justify-content:space-between;height:60px;}
.topbar-brand{display:flex;align-items:center;gap:.75rem;}
.topbar-icon{width:38px;height:38px;border-radius:var(--radius-sm);background:linear-gradient(135deg,var(--teal),var(--teal-d));display:flex;align-items:center;justify-content:center;color:white;font-size:.9rem;flex-shrink:0;}
.topbar-title{font-family:var(--font-head);font-size:1.1rem;color:var(--ink);line-height:1.2;}
.topbar-sub{font-size:.72rem;color:var(--ink-3);}
.page{max-width:1280px;margin:0 auto;padding:1.5rem 1.25rem 3rem;}
.card{background:var(--surface);border:1px solid var(--line);border-radius:var(--radius);box-shadow:var(--shadow);}
.quick-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:.75rem;margin-bottom:1.25rem;}
@media(min-width:640px){.quick-grid{grid-template-columns:repeat(4,1fr);}}
.quick-card{display:flex;align-items:center;gap:.75rem;padding:.875rem 1rem;background:var(--surface);border:1px solid var(--line);border-radius:var(--radius);text-decoration:none;color:inherit;transition:box-shadow .2s,transform .2s,border-color .2s;}
.quick-card:hover{box-shadow:var(--shadow-md);transform:translateY(-2px);border-color:var(--teal);}
.quick-icon{width:36px;height:36px;border-radius:var(--radius-sm);display:flex;align-items:center;justify-content:center;font-size:.85rem;flex-shrink:0;}
.quick-label{font-size:.8rem;font-weight:600;color:var(--ink);}
.quick-sub{font-size:.7rem;color:var(--ink-3);}
.ai-card{background:linear-gradient(135deg,#0f3460 0%,#1a5276 50%,var(--teal-d) 100%);border-radius:var(--radius);padding:1.25rem 1.5rem;margin-bottom:1.25rem;color:white;position:relative;overflow:hidden;}
.ai-card::before{content:'';position:absolute;inset:0;background:radial-gradient(circle at 80% 20%,rgba(255,255,255,.07) 0%,transparent 60%);pointer-events:none;}
.ai-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:.75rem;}
.ai-title{font-family:var(--font-head);font-size:1rem;display:flex;align-items:center;gap:.5rem;}
.ai-robot{width:40px;height:40px;border-radius:50%;background:rgba(255,255,255,.15);display:flex;align-items:center;justify-content:center;font-size:1.1rem;flex-shrink:0;}
.ai-body{font-size:.85rem;line-height:1.7;color:rgba(255,255,255,.88);min-height:2rem;}
.ai-btn{margin-top:.875rem;display:inline-flex;align-items:center;gap:.5rem;background:rgba(255,255,255,.18);border:1px solid rgba(255,255,255,.25);color:white;border-radius:var(--radius-sm);padding:.45rem 1rem;font-size:.8rem;font-weight:600;cursor:pointer;transition:background .2s;}
.ai-btn:hover{background:rgba(255,255,255,.28);}
.ai-btn:disabled{opacity:.5;cursor:not-allowed;}
.section-header{display:flex;align-items:center;justify-content:space-between;padding:1rem 1.25rem;border-bottom:1px solid var(--line);}
.section-title{font-family:var(--font-head);font-size:1rem;color:var(--ink);display:flex;align-items:center;gap:.5rem;}
.section-title i{color:var(--teal);font-size:.85rem;}
.class-panel{margin-bottom:1.25rem;}
.class-chips{display:flex;flex-wrap:wrap;gap:.5rem;padding:1rem 1.25rem;}
.chip{display:inline-flex;align-items:center;gap:.35rem;padding:.35rem .85rem;border:1.5px solid var(--line);border-radius:999px;font-size:.78rem;font-weight:500;cursor:pointer;transition:all .18s;background:var(--surface);color:var(--ink-2);white-space:nowrap;}
.chip:hover{border-color:var(--teal);color:var(--teal-d);background:var(--teal-l);}
.chip.active{background:var(--teal);border-color:var(--teal);color:white;}
.chip.my-class{border-color:var(--teal);}
.chip-dot{width:7px;height:7px;border-radius:50%;background:currentColor;opacity:.6;flex-shrink:0;}
.period-row{padding:.875rem 1.25rem;border-top:1px solid var(--line);display:flex;align-items:center;gap:.75rem;flex-wrap:wrap;}
.period-label{font-size:.78rem;font-weight:600;color:var(--ink-3);text-transform:uppercase;letter-spacing:.04em;}
select.field{padding:.45rem .9rem;border:1.5px solid var(--line);border-radius:var(--radius-sm);background:var(--surface);color:var(--ink);font-family:var(--font-body);font-size:.82rem;cursor:pointer;min-width:260px;appearance:none;background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='10' fill='%236b7280' viewBox='0 0 16 16'%3E%3Cpath d='M8 11L3 6h10l-5 5z'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right .7rem center;padding-right:2rem;transition:border-color .2s,box-shadow .2s;}
select.field:focus{outline:none;border-color:var(--teal);box-shadow:0 0 0 3px rgba(13,148,136,.12);}
select.field:disabled{opacity:.5;cursor:not-allowed;}
.period-note{font-size:.7rem;color:var(--ink-3);padding:.25rem 1.25rem .75rem;font-style:italic;}
.stats-row{display:grid;grid-template-columns:repeat(2,1fr);gap:.75rem;margin-bottom:1.25rem;}
@media(min-width:640px){.stats-row{grid-template-columns:repeat(3,1fr);}}
@media(min-width:1024px){.stats-row{grid-template-columns:repeat(5,1fr);}}
.stat-tile{background:var(--surface);border:1px solid var(--line);border-radius:var(--radius);padding:1rem;text-align:center;transition:box-shadow .2s,transform .2s;}
.stat-tile:hover{box-shadow:var(--shadow-md);transform:translateY(-2px);}
.stat-num{font-family:var(--font-head);font-size:1.6rem;line-height:1;margin-bottom:.25rem;}
.stat-lbl{font-size:.7rem;font-weight:600;text-transform:uppercase;letter-spacing:.05em;color:var(--ink-3);}
.c-teal{color:var(--teal-d);}.c-emerald{color:var(--emerald);}.c-amber{color:var(--amber);}.c-red{color:var(--red);}.c-blue{color:var(--blue);}
.subjects-grid{display:grid;grid-template-columns:repeat(1,1fr);gap:.875rem;margin-bottom:1.25rem;}
@media(min-width:640px){.subjects-grid{grid-template-columns:repeat(2,1fr);}}
@media(min-width:1024px){.subjects-grid{grid-template-columns:repeat(3,1fr);}}
.subject-card{background:var(--surface);border:1px solid var(--line);border-left:4px solid var(--line);border-radius:var(--radius);padding:1rem 1.1rem;cursor:pointer;transition:all .2s;}
.subject-card:hover{box-shadow:var(--shadow-md);transform:translateY(-2px);}
.subject-card.has-data{border-left-color:var(--teal);}
.subject-card.no-data{border-left-color:var(--line);opacity:.72;}
.subj-top{display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:.75rem;}
.subj-name{font-weight:600;font-size:.88rem;color:var(--ink);}
.subj-stats{display:grid;grid-template-columns:repeat(3,1fr);gap:.5rem;margin-bottom:.75rem;}
.subj-stat-val{font-family:var(--font-head);font-size:1.2rem;}
.subj-stat-lbl{font-size:.65rem;text-transform:uppercase;letter-spacing:.04em;color:var(--ink-3);}
.bar{height:5px;background:var(--line);border-radius:3px;overflow:hidden;margin-bottom:.5rem;}
.bar-fill{height:100%;border-radius:3px;transition:width .5s ease;}
.subj-footer{font-size:.7rem;color:var(--ink-3);}
.badge{display:inline-flex;align-items:center;padding:.18rem .6rem;border-radius:999px;font-size:.68rem;font-weight:600;letter-spacing:.02em;white-space:nowrap;}
.badge-ok{background:var(--emerald-l);color:var(--emerald);}
.badge-warn{background:var(--amber-l);color:var(--amber);}
.badge-err{background:var(--red-l);color:var(--red);}
.badge-info{background:var(--blue-l);color:var(--blue);}
.badge-none{background:var(--surface-3);color:var(--ink-3);}
.badge-sub{background:var(--teal-l);color:var(--teal-d);}
.detail-section{margin-bottom:1.25rem;}
.detail-toolbar{display:flex;align-items:center;justify-content:space-between;padding:.875rem 1.25rem;border-bottom:1px solid var(--line);flex-wrap:wrap;gap:.5rem;}
.detail-toolbar-title{font-family:var(--font-head);font-size:.95rem;display:flex;align-items:center;gap:.5rem;}
.btn{display:inline-flex;align-items:center;gap:.4rem;padding:.45rem 1rem;border-radius:var(--radius-sm);font-family:var(--font-body);font-size:.8rem;font-weight:600;border:1.5px solid transparent;cursor:pointer;transition:all .18s;text-decoration:none;white-space:nowrap;}
.btn-sm{padding:.35rem .75rem;font-size:.75rem;}
.btn-teal{background:var(--teal);color:white;border-color:var(--teal);}
.btn-teal:hover{background:var(--teal-d);}
.btn-outline{background:var(--surface);color:var(--teal);border-color:var(--teal);}
.btn-outline:hover{background:var(--teal-l);}
.btn-ghost{background:transparent;color:var(--ink-2);border-color:var(--line);}
.btn-ghost:hover{background:var(--surface-3);}
.btn:disabled{opacity:.45;cursor:not-allowed;pointer-events:none;}
.tbl-wrap{overflow-x:auto;max-height:420px;overflow-y:auto;}
.ptbl{width:100%;border-collapse:collapse;font-size:.82rem;}
.ptbl thead{position:sticky;top:0;z-index:5;}
.ptbl thead th{background:var(--surface-3);padding:.65rem .875rem;text-align:left;font-weight:600;font-size:.72rem;text-transform:uppercase;letter-spacing:.05em;color:var(--ink-3);border-bottom:1px solid var(--line);white-space:nowrap;}
.ptbl thead th:not(:first-child){text-align:center;}
.ptbl tbody tr{border-bottom:1px solid var(--line-2);transition:background .15s;}
.ptbl tbody tr:hover{background:var(--surface-2);}
.ptbl tbody td{padding:.65rem .875rem;}
.ptbl tbody td:not(:first-child){text-align:center;}
.stu-name{font-weight:500;color:var(--ink);}
.score-cell{font-family:var(--font-head);font-size:.9rem;}
.raw-cell{font-size:.78rem;color:var(--ink-3);}
.comment-cell{font-size:.75rem;color:var(--ink-3);max-width:160px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.chart-wrap{padding:1rem 1.25rem;position:relative;height:260px;}
.empty{text-align:center;padding:3.5rem 1.5rem;color:var(--ink-3);}
.empty i{font-size:2.5rem;display:block;margin-bottom:.875rem;opacity:.35;}
.empty p{font-size:.875rem;}
.empty small{font-size:.75rem;display:block;margin-top:.3rem;}
.spin{display:inline-block;width:16px;height:16px;border:2px solid rgba(255,255,255,.3);border-top-color:white;border-radius:50%;animation:spin .6s linear infinite;vertical-align:middle;}
.spin-dark{border-color:var(--line);border-top-color:var(--teal);}
@keyframes spin{to{transform:rotate(360deg);}}
#page-loading{position:fixed;inset:0;z-index:100;background:rgba(255,255,255,.9);display:flex;flex-direction:column;align-items:center;justify-content:center;gap:.75rem;}
#page-loading .big-spin{width:48px;height:48px;border:4px solid var(--teal-l);border-top-color:var(--teal);border-radius:50%;animation:spin .8s linear infinite;}
#page-loading p{font-size:.875rem;color:var(--ink-3);}
#toast-stack{position:fixed;bottom:1.25rem;right:1.25rem;z-index:200;display:flex;flex-direction:column;gap:.5rem;}
.toast{display:flex;align-items:center;gap:.625rem;padding:.75rem 1rem;border-radius:var(--radius-sm);background:var(--ink);color:white;font-size:.82rem;font-weight:500;box-shadow:var(--shadow-lg);animation:slideIn .3s ease;}
.toast.ok{background:var(--emerald);}
.toast.warn{background:var(--amber);}
.toast.err{background:var(--red);}
@keyframes slideIn{from{transform:translateX(110%);opacity:0;}to{transform:translateX(0);opacity:1;}}
@keyframes slideOut{to{transform:translateX(110%);opacity:0;}}
.tabs{display:flex;gap:0;border-bottom:1px solid var(--line);padding:0 1.25rem;}
.tab{padding:.625rem 1rem;font-size:.8rem;font-weight:600;cursor:pointer;border-bottom:2.5px solid transparent;color:var(--ink-3);transition:color .18s,border-color .18s;margin-bottom:-1px;}
.tab.active{color:var(--teal-d);border-bottom-color:var(--teal);}
.tab:hover:not(.active){color:var(--ink-2);}
.score-bar-wrap{display:flex;align-items:center;gap:.5rem;}
.score-bar-bg{flex:1;height:5px;background:var(--line);border-radius:3px;overflow:hidden;max-width:80px;}
.score-bar-fg{height:100%;border-radius:3px;transition:width .4s ease;}
.status-pill{font-size:.65rem;padding:.1rem .45rem;border-radius:999px;font-weight:600;}
.status-submitted{background:var(--emerald-l);color:var(--emerald);}
.status-draft{background:var(--amber-l);color:var(--amber);}
.status-reviewed{background:var(--blue-l);color:var(--blue);}
@media(max-width:480px){.topbar-title{font-size:.95rem;}.page{padding:1rem .875rem 2rem;}.chip{font-size:.72rem;padding:.28rem .7rem;}}
</style>
</head>
<body>

<div id="page-loading"><div class="big-spin"></div><p>Loading Academic Progress…</p></div>

<div class="topbar">
  <div class="topbar-inner">
    <div class="topbar-brand">
      <div class="topbar-icon"><i class="fas fa-chart-line"></i></div>
      <div>
        <div class="topbar-title">Academic Progress</div>
        <div class="topbar-sub" id="term-display">Loading…</div>
      </div>
    </div>
    <a href="dashboard.php" class="btn btn-ghost btn-sm"><i class="fas fa-arrow-left"></i> Dashboard</a>
  </div>
</div>

<div class="page">

  <div class="quick-grid">
    <a href="input_academic_scores.php" class="quick-card">
      <div class="quick-icon" style="background:#dbeafe;color:#1d4ed8"><i class="fas fa-calculator"></i></div>
      <div><div class="quick-label">Enter Scores</div><div class="quick-sub">Bi-weekly entries</div></div>
    </a>
    <a href="input_academic_comments.php" class="quick-card">
      <div class="quick-icon" style="background:#d1fae5;color:#065f46"><i class="fas fa-comment-dots"></i></div>
      <div><div class="quick-label">Add Comments</div><div class="quick-sub">Student feedback</div></div>
    </a>
    <a href="input_mock_scores.php" class="quick-card">
      <div class="quick-icon" style="background:#ede9fe;color:#4c1d95"><i class="fas fa-graduation-cap"></i></div>
      <div><div class="quick-label">Mock Scores</div><div class="quick-sub">BECE prep</div></div>
    </a>
    <a href="view_reports.php" class="quick-card">
      <div class="quick-icon" style="background:#fef3c7;color:#92400e"><i class="fas fa-file-alt"></i></div>
      <div><div class="quick-label">Reports</div><div class="quick-sub">Generate reports</div></div>
    </a>
  </div>

  <div id="ai-card" class="ai-card hidden">
    <div class="ai-header">
      <div style="display:flex;align-items:center;gap:.75rem">
        <div class="ai-robot"><i class="fas fa-robot"></i></div>
        <div class="ai-title"><i class="fas fa-sparkles" style="font-size:.75rem;opacity:.7"></i> AI Performance Insights</div>
      </div>
      <button onclick="loadAIInsight()" style="background:rgba(255,255,255,.15);border:none;color:white;width:30px;height:30px;border-radius:50%;cursor:pointer;font-size:.8rem" title="Refresh">
        <i class="fas fa-sync-alt"></i>
      </button>
    </div>
    <div class="ai-body" id="ai-content">Select a class and click Analyze to get AI-powered insights.</div>
    <button class="ai-btn" id="ai-btn" onclick="loadAIInsight()"><i class="fas fa-robot"></i> Analyze with AI</button>
  </div>

  <div class="card class-panel">
    <div class="section-header">
      <div class="section-title"><i class="fas fa-chalkboard"></i> Select Class &amp; Period</div>
      <span id="period-badge" style="font-size:.72rem;color:var(--ink-3)"></span>
    </div>
    <div class="class-chips" id="class-chips">
      <div style="font-size:.82rem;color:var(--ink-3);padding:.25rem 0">Loading classes…</div>
    </div>
    <div class="period-row">
      <span class="period-label">Period</span>
      <select class="field" id="period-select" disabled>
        <option value="">— select class first —</option>
      </select>
      <button class="btn btn-teal btn-sm" id="load-btn" disabled onclick="triggerLoad()">
        <i class="fas fa-sync-alt"></i> Load Data
      </button>
    </div>
    <div class="period-note hidden" id="period-note"></div>
  </div>

  <div class="stats-row hidden" id="stats-row">
    <div class="stat-tile"><div class="stat-num c-teal" id="stat-students">—</div><div class="stat-lbl">Students</div></div>
    <div class="stat-tile"><div class="stat-num c-blue" id="stat-subjects">—</div><div class="stat-lbl">Subjects</div></div>
    <div class="stat-tile"><div class="stat-num c-emerald" id="stat-avg">—</div><div class="stat-lbl">Overall Avg</div></div>
    <div class="stat-tile"><div class="stat-num c-amber" id="stat-entries">—</div><div class="stat-lbl">Entries</div></div>
    <div class="stat-tile"><div class="stat-num c-teal" id="stat-best" style="font-size:1rem;line-height:1.4">—</div><div class="stat-lbl">Best Subject</div></div>
  </div>

  <div class="subjects-grid" id="subjects-grid">
    <div style="grid-column:1/-1">
      <div class="empty">
        <i class="fas fa-arrow-up"></i>
        <p>Select a class above to view entered progress periods</p>
        <small>Periods reflect exactly what was entered in the scores page</small>
      </div>
    </div>
  </div>

  <div class="card detail-section hidden" id="detail-section">
    <div class="detail-toolbar">
      <div class="detail-toolbar-title" id="detail-title">
        <i class="fas fa-table" style="color:var(--teal)"></i> Student Details
      </div>
      <div style="display:flex;gap:.5rem;flex-wrap:wrap">
        <button class="btn btn-ghost btn-sm" onclick="closeDetail()"><i class="fas fa-times"></i> Close</button>
        <button class="btn btn-outline btn-sm" onclick="exportCSV()"><i class="fas fa-download"></i> Export CSV</button>
      </div>
    </div>
    <div class="tabs">
      <div class="tab active" data-tab="table" onclick="switchTab(this,'table')">Score Table</div>
      <div class="tab" data-tab="chart" onclick="switchTab(this,'chart')">Chart</div>
    </div>
    <div id="tab-table">
      <div class="tbl-wrap">
        <table class="ptbl">
          <thead><tr>
            <th>#</th><th style="text-align:left">Student</th>
            <th>Class Pract.</th><th>Assignments</th><th>CAT</th><th>Group Proj.</th>
            <th>Total /100</th><th>Grade</th><th>Status</th><th style="text-align:left">Comments</th>
          </tr></thead>
          <tbody id="detail-tbody"></tbody>
        </table>
      </div>
    </div>
    <div id="tab-chart" class="hidden">
      <div class="chart-wrap"><canvas id="detail-chart"></canvas></div>
    </div>
  </div>

</div>

<div id="toast-stack"></div>

<?php include $_SERVER['DOCUMENT_ROOT'] . '/templates/teacher_footer.php'; ?>
<?php if (isset($conn) && $conn instanceof mysqli) $conn->close(); ?>
<?php ob_end_flush(); ?>

<script>
(function(){
'use strict';

/* ── State ──────────────────────────────────────────────────────────────────── */
let dashData        = null;
let currentClassId  = null;
let currentClassNm  = '';
let currentSummary  = null;
let chartInst       = null;
let fallbackPeriods = [];

/* ── DOM ─────────────────────────────────────────────────────────────────────── */
const $ = s => document.querySelector(s);
const $$ = s => [...document.querySelectorAll(s)];
const loading       = $('#page-loading');
const termDisplay   = $('#term-display');
const classChips    = $('#class-chips');
const periodSel     = $('#period-select');
const loadBtn       = $('#load-btn');
const statsRow      = $('#stats-row');
const subjectsGrid  = $('#subjects-grid');
const detailSection = $('#detail-section');
const detailTitle   = $('#detail-title');
const detailTbody   = $('#detail-tbody');
const aiCard        = $('#ai-card');
const aiContent     = $('#ai-content');
const aiBtn         = $('#ai-btn');
const periodNote    = $('#period-note');

/* ── Toast ───────────────────────────────────────────────────────────────────── */
function toast(msg, type='ok', ms=4000){
  const icons={ok:'fa-check-circle',warn:'fa-exclamation-triangle',err:'fa-exclamation-circle'};
  const el=document.createElement('div');
  el.className='toast '+type;
  el.innerHTML=`<i class="fas ${icons[type]||'fa-info-circle'}"></i><span>${msg}</span>`;
  $('#toast-stack').appendChild(el);
  setTimeout(()=>{ el.style.animation='slideOut .35s ease forwards'; setTimeout(()=>el.remove(),380); },ms);
}

/* ── API helper ──────────────────────────────────────────────────────────────── */
const SELF = window.location.pathname;
async function api(action, body=null){
  let url, opts;
  if(body){ body.set('ajax','1'); body.set('action',action); url=SELF+'?ajax=1'; opts={method:'POST',body}; }
  else { url=SELF+'?ajax=1&action='+encodeURIComponent(action); opts={}; }
  const res = await fetch(url,opts);
  if(!res.ok) throw new Error('HTTP '+res.status);
  const text = await res.text();
  try{ return JSON.parse(text); }
  catch(e){ console.error('Non-JSON:',text.substr(0,400)); throw new Error('Invalid JSON from server'); }
}

/* ── Score helpers ───────────────────────────────────────────────────────────── */
function gradeLabel(v){
  if(v===null||v===undefined) return {txt:'—',cls:'badge-none'};
  if(v>=80) return {txt:'Excellent',cls:'badge-ok'};
  if(v>=65) return {txt:'Good',cls:'badge-sub'};
  if(v>=50) return {txt:'Average',cls:'badge-warn'};
  return {txt:'Below Avg',cls:'badge-err'};
}
function barColor(v){
  if(v===null) return '#e5e7eb';
  if(v>=70) return '#059669';
  if(v>=50) return '#d97706';
  return '#dc2626';
}

/* ── Init ────────────────────────────────────────────────────────────────────── */
async function init(){
  try{
    const r = await api('get_dashboard');
    if(!r.success) throw new Error(r.error||'Dashboard load failed');
    dashData = r;
    if(r.csrf_token){ const m=$('meta[name="csrf-token"]'); if(m) m.content=r.csrf_token; }

    termDisplay.textContent = r.term_name+' · '+r.academic_year;
    $('#period-badge').textContent = r.academic_year;
    fallbackPeriods = r.fallback_periods || [];

    classChips.innerHTML='';
    if(!r.all_classes||!r.all_classes.length){
      classChips.innerHTML='<div style="font-size:.82rem;color:var(--ink-3)">No classes found.</div>';
    } else {
      r.all_classes.forEach(cls=>{
        const isMine = r.teacher_class_ids.includes(parseInt(cls.id));
        const chip   = document.createElement('button');
        chip.className  = 'chip'+(isMine?' my-class':'');
        chip.title      = cls.class_name;
        chip.innerHTML  = (isMine?'<span class="chip-dot"></span>':'')+esc(getFriendlyName(cls.class_name));
        chip.dataset.id = cls.id;
        chip.dataset.nm = cls.class_name;
        chip.onclick    = ()=>selectClass(parseInt(cls.id),cls.class_name,chip);
        classChips.appendChild(chip);
      });
    }

    const firstMine = classChips.querySelector('.chip.my-class');
    const firstChip = firstMine||classChips.querySelector('.chip');
    if(firstChip) firstChip.click();

  } catch(err){
    toast('Error loading dashboard: '+err.message,'err',8000);
    console.error(err);
  } finally {
    loading.classList.add('hidden');
  }
}

/* ── selectClass ─────────────────────────────────────────────────────────────── */
async function selectClass(id, name, chipEl){
  $$('.chip').forEach(c=>c.classList.remove('active'));
  if(chipEl) chipEl.classList.add('active');
  currentClassId = id;
  currentClassNm = name;
  loadBtn.disabled = true;
  aiCard.classList.remove('hidden');

  periodSel.disabled = true;
  periodSel.innerHTML = '<option value="">Loading periods…</option>';
  periodNote.classList.add('hidden');

  try{
    const fd = new FormData();
    fd.append('class_id', id);
    const r = await api('get_class_periods', fd);

    if(r.success && r.has_data && r.periods.length>0){
      populatePeriodDropdown(r.periods, r.current_period);
      periodNote.textContent =
        r.periods.length+' period'+(r.periods.length>1?'s':'')+
        ' with entered data — matching scores page exactly.';
      periodNote.classList.remove('hidden');
      if(periodSel.value && periodSel.value!==''){
        triggerLoad();
      }
    } else {
      if(fallbackPeriods.length>0){
        const today = new Date().toISOString().slice(0,10);
        const curFb = fallbackPeriods.find(p=>today>=p.start_date&&today<=p.end_date)
                      ||fallbackPeriods[0];
        populatePeriodDropdown(fallbackPeriods, curFb);
        periodNote.textContent = 'No scores entered for this class yet. Showing term calendar periods.';
        periodNote.classList.remove('hidden');
      } else {
        periodSel.innerHTML='<option value="">— no periods available —</option>';
      }
      loadBtn.disabled = false;
    }
  } catch(err){
    console.error('get_class_periods error:',err);
    if(fallbackPeriods.length>0) populatePeriodDropdown(fallbackPeriods,null);
  }
}

function populatePeriodDropdown(periods, currentPeriod){
  periodSel.innerHTML = '<option value="">— select period —</option>';
  periods.forEach(p=>{
    const opt   = document.createElement('option');
    opt.value   = JSON.stringify({start: p.start_date, end: p.end_date});
    const isCur = currentPeriod && p.start_date===currentPeriod.start_date;
    opt.textContent = p.label + (isCur?' ★':'');
    if(isCur) opt.selected = true;
    periodSel.appendChild(opt);
  });
  periodSel.disabled = false;
  loadBtn.disabled   = false;
  periodSel.onchange = ()=>{ if(currentClassId) triggerLoad(); };
}

/* ── Trigger load ────────────────────────────────────────────────────────────── */
function triggerLoad(){
  if(!currentClassId) return;
  let pd;
  try{ pd=JSON.parse(periodSel.value); } catch(e){ pd=null; }
  if(!pd||!pd.start||!pd.end){
    toast('Please select a period','warn'); return;
  }
  loadClassProgress(currentClassId, currentClassNm, pd);
}
window.triggerLoad = triggerLoad;

/* ── Load & render ───────────────────────────────────────────────────────────── */
async function loadClassProgress(classId, className, period){
  subjectsGrid.innerHTML=`<div style="grid-column:1/-1"><div class="empty">
    <span class="spin spin-dark" style="width:32px;height:32px;border-width:3px;display:inline-block"></span>
    <p style="margin-top:.75rem">Loading class data…</p></div></div>`;
  statsRow.classList.add('hidden');
  detailSection.classList.add('hidden');

  const fd=new FormData();
  fd.append('class_id',classId);
  fd.append('period_start',period.start);
  fd.append('period_end',period.end);

  try{
    const r = await api('get_class_progress',fd);
    if(!r.success) throw new Error(r.error||'Unknown server error');
    renderClassProgress(r, period);
  } catch(err){
    subjectsGrid.innerHTML=`<div style="grid-column:1/-1"><div class="empty">
      <i class="fas fa-exclamation-triangle" style="color:var(--red)"></i>
      <p>Error: ${esc(err.message)}</p>
      <small>Check the PHP error log for details.</small>
    </div></div>`;
    toast('Failed to load class data','err',6000);
    console.error(err);
  }
}

function renderClassProgress(data, period){
  statsRow.classList.remove('hidden');
  $('#stat-students').textContent = data.student_count;
  $('#stat-subjects').textContent = (data.subjects||[]).length;

  let allScores=[],totalEntries=0;
  (data.subject_summaries||[]).forEach(s=>{
    (s.student_scores||[]).forEach(ss=>{
      if(ss.total_scaled!==null&&ss.total_scaled!==undefined){
        allScores.push(ss.total_scaled); totalEntries++;
      }
    });
  });
  $('#stat-avg').textContent = allScores.length
    ? (allScores.reduce((a,b)=>a+b,0)/allScores.length).toFixed(1)+'%' : '—';
  $('#stat-entries').textContent = totalEntries;

  let best='—',bestV=-1;
  (data.subject_summaries||[]).forEach(s=>{
    if(s.average!==null&&s.average!==undefined&&s.average>bestV){bestV=s.average;best=s.subject_name;}
  });
  const bestEl=$('#stat-best');
  bestEl.textContent = best.length>14?best.substr(0,13)+'…':best;
  bestEl.title = best;

  subjectsGrid.innerHTML='';
  if(!data.subject_summaries||!data.subject_summaries.length){
    subjectsGrid.innerHTML=`<div style="grid-column:1/-1"><div class="empty">
      <i class="fas fa-folder-open"></i>
      <p>No subjects found for this class</p>
      <small>Check class schedules in the admin panel</small>
    </div></div>`;
    return;
  }

  data.subject_summaries.forEach(summary=>{
    const hasData = summary.entry_count>0;
    const avgColor = summary.average===null?'var(--ink-3)':
                     summary.average>=70?'var(--emerald)':
                     summary.average>=50?'var(--amber)':'var(--red)';
    const covered = (summary.student_scores||[]).filter(s=>s.has_data).length;
    const total   = (summary.student_scores||[]).length;

    const card = document.createElement('div');
    card.className='subject-card '+(hasData?'has-data':'no-data');
    card.innerHTML=`
      <div class="subj-top">
        <div class="subj-name">${esc(summary.subject_name)}</div>
        ${hasData?`<span class="badge badge-sub">${covered}/${total} students</span>`
                 :`<span class="badge badge-none">No Data</span>`}
      </div>
      <div class="subj-stats">
        <div>
          <div class="subj-stat-val" style="color:${avgColor}">
            ${summary.average!==null?summary.average+'%':'—'}
          </div>
          <div class="subj-stat-lbl">Average</div>
        </div>
        <div>
          <div class="subj-stat-val" style="color:var(--emerald)">
            ${summary.highest!==null?summary.highest+'%':'—'}
          </div>
          <div class="subj-stat-lbl">Highest</div>
        </div>
        <div>
          <div class="subj-stat-val" style="color:var(--red)">
            ${summary.lowest!==null?summary.lowest+'%':'—'}
          </div>
          <div class="subj-stat-lbl">Lowest</div>
        </div>
      </div>
      <div class="bar"><div class="bar-fill" style="width:${summary.average||0}%;background:${barColor(summary.average)}"></div></div>
      <div class="subj-footer">
        ${summary.entry_count} entr${summary.entry_count===1?'y':'ies'}
        · ${period.start} – ${period.end}
        · <em>Click for details</em>
      </div>`;
    card.onclick = ()=>showDetail(summary, period);
    subjectsGrid.appendChild(card);
  });

  toast(`Loaded ${data.student_count} students · ${(data.subjects||[]).length} subjects · ${totalEntries} entries`,'ok');
}

/* ── Detail panel ────────────────────────────────────────────────────────────── */
function showDetail(summary, period){
  currentSummary={summary,period};
  detailSection.classList.remove('hidden');
  detailTitle.innerHTML=`<i class="fas fa-table" style="color:var(--teal)"></i>
    ${esc(summary.subject_name)}
    <small style="font-weight:400;font-size:.75rem;color:var(--ink-3);margin-left:.5rem">
      ${period.start} – ${period.end}
    </small>`;
  const tt=document.querySelector('.tab[data-tab="table"]');
  if(tt) switchTab(tt,'table');
  renderDetailTable(summary);
  renderDetailChart(summary);
  detailSection.scrollIntoView({behavior:'smooth',block:'start'});
}

function renderDetailTable(summary){
  detailTbody.innerHTML='';
  if(!summary.student_scores||!summary.student_scores.length){
    detailTbody.innerHTML='<tr><td colspan="10" style="text-align:center;color:var(--ink-3);padding:2rem">No students found</td></tr>';
    return;
  }
  summary.student_scores.forEach((ss,i)=>{
    const ts=ss.total_scaled;
    const g=gradeLabel(ts);
    const cpS=(ss.class_practices_max>0)?((ss.class_practices_raw/ss.class_practices_max)*25).toFixed(1):null;
    const asS=(ss.assignments_max>0)?((ss.assignments_raw/ss.assignments_max)*25).toFixed(1):null;
    const ctS=(ss.cat_max>0)?((ss.cat_raw/ss.cat_max)*25).toFixed(1):null;
    const gpS=(ss.group_project_max>0)?((ss.group_project_raw/ss.group_project_max)*25).toFixed(1):null;
    const mk=(sc,raw,max)=>sc!==null
      ?`<span class="score-cell">${sc}</span><br><span class="raw-cell">${raw}/${max}</span>`
      :`<span style="color:var(--ink-4)">—</span>`;
    const stCls=ss.status==='submitted'?'status-submitted':ss.status==='reviewed'?'status-reviewed':ss.status==='draft'?'status-draft':'';
    const stTxt=ss.status?`<span class="status-pill ${stCls}">${ss.status}</span>`:`<span style="color:var(--ink-4);font-size:.7rem">—</span>`;
    const tr=document.createElement('tr');
    tr.innerHTML=`
      <td style="color:var(--ink-3);font-size:.75rem">${i+1}</td>
      <td class="stu-name">${esc(ss.student_name)}</td>
      <td>${mk(cpS,ss.class_practices_raw,ss.class_practices_max)}</td>
      <td>${mk(asS,ss.assignments_raw,ss.assignments_max)}</td>
      <td>${mk(ctS,ss.cat_raw,ss.cat_max)}</td>
      <td>${mk(gpS,ss.group_project_raw,ss.group_project_max)}</td>
      <td>${ts!==null&&ts!==undefined
        ?`<div class="score-bar-wrap"><span class="score-cell" style="color:${barColor(ts)};min-width:3.5ch">${parseFloat(ts).toFixed(1)}</span>
           <div class="score-bar-bg"><div class="score-bar-fg" style="width:${Math.min(ts,100)}%;background:${barColor(ts)}"></div></div></div>`
        :`<span style="color:var(--ink-4)">—</span>`}</td>
      <td><span class="badge ${g.cls}">${g.txt}</span></td>
      <td>${stTxt}</td>
      <td class="comment-cell" title="${esc(ss.comments||'')}">${esc(ss.comments||'—')}</td>`;
    detailTbody.appendChild(tr);
  });
}

function renderDetailChart(summary){
  const ctx=$('#detail-chart').getContext('2d');
  const scores=summary.student_scores||[];
  const labels=scores.map(s=>shortName(s.student_name));
  const values=scores.map(s=>s.total_scaled!==null?parseFloat(s.total_scaled):0);
  const colors=values.map(v=>barColor(v));
  if(chartInst){chartInst.destroy();chartInst=null;}
  chartInst=new Chart(ctx,{
    type:'bar',
    data:{labels,datasets:[{label:summary.subject_name,data:values,backgroundColor:colors,borderRadius:5,borderSkipped:false}]},
    options:{
      responsive:true,maintainAspectRatio:false,
      plugins:{legend:{display:false},tooltip:{callbacks:{label:c=>' '+c.parsed.y.toFixed(1)+' / 100'}}},
      scales:{
        y:{min:0,max:100,grid:{color:'#f3f4f6'},ticks:{font:{size:11}}},
        x:{grid:{display:false},ticks:{font:{size:10},maxRotation:45}}
      }
    }
  });
}

function switchTab(tabEl,name){
  if(!tabEl) return;
  $$('.tab').forEach(t=>t.classList.remove('active'));
  tabEl.classList.add('active');
  $('#tab-table').classList.toggle('hidden',name!=='table');
  $('#tab-chart').classList.toggle('hidden',name!=='chart');
}
function closeDetail(){
  detailSection.classList.add('hidden');
  if(chartInst){chartInst.destroy();chartInst=null;}
}
window.switchTab=switchTab;
window.closeDetail=closeDetail;

/* ── AI insight ──────────────────────────────────────────────────────────────── */
async function loadAIInsight(){
  if(!currentClassId){toast('Select a class first','warn');return;}
  aiContent.innerHTML='<span class="spin"></span> Analysing performance data…';
  aiBtn.disabled=true;
  const fd=new FormData();
  fd.append('class_id',currentClassId);
  fd.append('class_name',currentClassNm);
  try{
    const r=await api('get_ai_insight',fd);
    aiContent.innerHTML=r.success
      ?esc(r.insight).replace(/\n/g,'<br>')
      :`<em style="opacity:.7">${esc(r.error||'No insight available')}</em>`;
  } catch(err){
    aiContent.innerHTML='<em style="opacity:.7">AI service temporarily unavailable.</em>';
    console.error(err);
  } finally { aiBtn.disabled=false; }
}
window.loadAIInsight=loadAIInsight;

/* ── CSV export ──────────────────────────────────────────────────────────────── */
function exportCSV(){
  if(!currentSummary) return;
  const{summary,period}=currentSummary;
  const rows=[['Student','Class Practices (/25)','Assignments (/25)','CAT (/25)','Group Proj (/25)','Total (/100)','Grade','Status','Comments']];
  (summary.student_scores||[]).forEach(ss=>{
    const ts=ss.total_scaled;
    const cpS=ss.class_practices_max>0?((ss.class_practices_raw/ss.class_practices_max)*25).toFixed(1):'';
    const asS=ss.assignments_max>0?((ss.assignments_raw/ss.assignments_max)*25).toFixed(1):'';
    const ctS=ss.cat_max>0?((ss.cat_raw/ss.cat_max)*25).toFixed(1):'';
    const gpS=ss.group_project_max>0?((ss.group_project_raw/ss.group_project_max)*25).toFixed(1):'';
    rows.push([ss.student_name,cpS,asS,ctS,gpS,ts!==null?parseFloat(ts).toFixed(1):'',gradeLabel(ts).txt,ss.status||'',ss.comments||'']);
  });
  const csv=rows.map(r=>r.map(c=>'"'+String(c).replace(/"/g,'""')+'"').join(',')).join('\n');
  const blob=new Blob(['\ufeff'+csv],{type:'text/csv;charset=utf-8;'});
  const url=URL.createObjectURL(blob);
  const a=document.createElement('a');
  a.href=url;
  a.download=summary.subject_name.replace(/\W+/g,'_')+'_'+period.start+'.csv';
  document.body.appendChild(a);a.click();document.body.removeChild(a);
  URL.revokeObjectURL(url);
}
window.exportCSV=exportCSV;

/* ── Helpers ─────────────────────────────────────────────────────────────────── */
function esc(s){return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');}
function shortName(n){const p=(n||'').trim().split(/\s+/);return p.length>1?p[0]+' '+p[p.length-1][0]+'.':p[0];}
function getFriendlyName(db){
  const m={'Esther Foundation':'Crèche','Sheba 1':'Nursery 1','Sheba 2':'Nursery 2',
    'Yaasant 1':'KG 1','Yaasant 2':'KG 2','Cleopatra 1':'Basic 1','Cleopatra 2':'Basic 2',
    'Nefertiti 1':'Basic 3','Nefertiti 2':'Basic 4','Malika 1':'Basic 5','Malika 2':'Basic 6',
    'Makeda 1':'JHS 1','Makeda 2':'JHS 2','Majesty':'JHS 3'};
  for(const[k,v] of Object.entries(m)){if(db.toLowerCase().includes(k.toLowerCase())) return v;}
  return db;
}

/* ── Boot ────────────────────────────────────────────────────────────────────── */
init();
})();
</script>
</body>
</html>