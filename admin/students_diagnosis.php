<?php
// ============================================
// ADMIN: STUDENT ACADEMIC DIAGNOSIS - VIEW ALL
// students_diagnosis.php  (admin portal)
// Read-only view across every teacher/class/term, grouped by class,
// with an auto-generated diagnostic interpretation + advice per record.
// ============================================

// ============================================
// ERROR REPORTING & DEBUGGING
// ============================================
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/error_log.txt');

// ============================================
// START SESSION
// ============================================
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ============================================
// RESTRICT ACCESS - ADMIN ONLY
// ============================================
if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    error_log("Unauthorized access attempt to admin diagnosis view: user_id=" . ($_SESSION['user_id'] ?? 'unset') . ", role=" . ($_SESSION['user_role'] ?? 'unset'));
    $_SESSION['error_msg'] = "Please log in as an administrator.";
    header("Location: /login.php");
    exit();
}

$admin_id = intval($_SESSION['user_id']);

// ============================================
// DATABASE CONNECTION
// ============================================
$db_paths = [
    $_SERVER['DOCUMENT_ROOT'] . '/config/db.php',
    __DIR__ . '/../config/db.php',
    __DIR__ . '/../../config/db.php',
    __DIR__ . '/config/db.php',
    __DIR__ . '/includes/db.php'
];

$conn = null;
$db_found = false;

foreach ($db_paths as $path) {
    if (file_exists($path)) {
        try {
            require_once $path;
            $db_found = true;
            break;
        } catch (Exception $e) {
            error_log("Failed to include db.php from $path: " . $e->getMessage());
        }
    }
}

if (!$db_found || !isset($conn) || $conn === null) {
    require_once $_SERVER['DOCUMENT_ROOT'] . '/config/db.php';
}

if (!$conn || $conn->connect_error) {
    die("Database Connection Failed: " . ($conn ? $conn->connect_error : 'No connection object'));
}

$conn->set_charset('utf8mb4');

// ============================================
// ADMIN NAME (for header)
// ============================================
$admin_name = 'Administrator';
try {
    $admin_stmt = $conn->prepare("SELECT name FROM users WHERE id = ? LIMIT 1");
    if ($admin_stmt) {
        $admin_stmt->bind_param("i", $admin_id);
        $admin_stmt->execute();
        $admin_result = $admin_stmt->get_result();
        if ($admin_result && $admin_result->num_rows > 0) {
            $admin_name = $admin_result->fetch_assoc()['name'] ?? 'Administrator';
        }
        $admin_stmt->close();
    }
} catch (Exception $e) {
    error_log("Admin name lookup error: " . $e->getMessage());
}

// ============================================
// CLASS NAME MAPPING FOR DISPLAY + NATURAL GRADE ORDER
// ============================================
$class_mapping = [
    'Esther Foundation' => 'Creche',
    'Sheba One' => 'Nursery 1',
    'Sheba Two' => 'Nursery 2',
    'Yaasant One' => 'KG 1',
    'Yaasant Two' => 'KG 2',
    'Cleopatra One' => 'Basic 1',
    'Cleopatra Two' => 'Basic 2',
    'Nefertiti One' => 'Basic 3',
    'Nefertiti Two' => 'Basic 4',
    'Malika One' => 'Basic 5',
    'Malika Two' => 'Basic 6',
    'Makeda One' => 'Basic 7',
    'Makeda Two' => 'Basic 8',
    'An Majesty' => 'Basic 9'
];
$class_grade_order = array_flip(array_keys($class_mapping)); // e.g. 'Esther Foundation' => 0

function getDisplayClassName($class_name) {
    global $class_mapping;
    return $class_mapping[$class_name] ?? $class_name;
}

$status_labels = [
    'excellent'          => 'Excellent',
    'good'               => 'Good',
    'average'            => 'Average',
    'needs_improvement'  => 'Needs Improvement',
    'critical'           => 'Critical',
];
$status_badge_class = [
    'excellent'          => 'success',
    'good'               => 'good',
    'average'            => 'average',
    'needs_improvement'  => 'warn',
    'critical'           => 'critical',
];
$status_icon = [
    'excellent'          => '🌟',
    'good'               => '👍',
    'average'            => '📝',
    'needs_improvement'  => '⚠️',
    'critical'           => '🔴',
];

// ============================================
// DIAGNOSTIC ANALYSIS ENGINE
// ------------------------------------------------
// Generates a human-readable interpretation and a set of concrete,
// actionable recommendations from the raw diagnosis data: overall
// status/grade/score, per-criterion scores (grouped by category), and
// the teacher-tagged subject mastery/struggling lists. No external
// service is called - this runs entirely on data already in the
// database, so it's instant and has no extra moving parts to maintain.
// ============================================
function analyzeDiagnosis($overall_status, $overall_score, $academic_grade, $scores, $mastery_subjects, $struggling_subjects) {
    $status_meaning = [
        'excellent'         => 'the student is performing exceptionally well and is meeting or exceeding expectations across most of what was assessed',
        'good'              => 'the student is performing above the expected baseline, with a solid and fairly consistent grasp of the material',
        'average'           => 'the student is performing at an expected baseline level, but consistency and depth still have room to grow',
        'needs_improvement' => 'the student is falling behind expected benchmarks in a number of areas and would benefit from closer, structured support',
        'critical'          => 'the student is significantly behind expected benchmarks and needs immediate, structured intervention',
    ];

    // --- Category breakdown from raw criterion scores ---
    $categories = [];
    foreach ($scores as $row) {
        $cat = $row['category_name'] ?: 'General';
        if (!isset($categories[$cat])) {
            $categories[$cat] = ['sum' => 0, 'max' => 0, 'count' => 0];
        }
        $categories[$cat]['sum'] += (float)$row['score'];
        $categories[$cat]['max'] += (float)$row['max_score'];
        $categories[$cat]['count']++;
    }
    $category_breakdown = [];
    foreach ($categories as $name => $data) {
        $pct = $data['max'] > 0 ? round(($data['sum'] / $data['max']) * 100, 1) : 0;
        $level = $pct >= 75 ? 'strong' : ($pct >= 50 ? 'moderate' : 'weak');
        $category_breakdown[] = ['name' => $name, 'pct' => $pct, 'level' => $level];
    }
    usort($category_breakdown, function ($a, $b) { return $a['pct'] <=> $b['pct']; });

    // --- Build the interpretation paragraph ---
    $sentences = [];
    $pct_overall = $overall_score !== null ? round(($overall_score / 20) * 100, 1) : null;
    $sentences[] = "This diagnosis places the student's overall performance in the \"" . str_replace('_', ' ', $overall_status) . "\" range"
        . ($overall_score !== null ? " (" . round($overall_score, 1) . "/20" . ($academic_grade ? ", grade " . $academic_grade : "") . ")" : "")
        . ", meaning " . ($status_meaning[$overall_status] ?? 'the student\'s performance falls within this band') . ".";

    if (count($category_breakdown) >= 2) {
        $weakest = $category_breakdown[0];
        $strongest = end($category_breakdown);
        $spread = $strongest['pct'] - $weakest['pct'];
        if ($spread >= 15) {
            $sentences[] = "Within the assessed criteria, \"" . $strongest['name'] . "\" is clearly the strongest area (" . $strongest['pct'] . "%), while \"" . $weakest['name'] . "\" (" . $weakest['pct'] . "%) stands out as the area needing the most attention.";
        } else {
            $sentences[] = "Performance is fairly even across the assessed categories, sitting mostly between " . $weakest['pct'] . "% and " . $strongest['pct'] . "%, without one area dramatically outweighing another.";
        }
    } elseif (count($category_breakdown) === 1) {
        $only = $category_breakdown[0];
        $sentences[] = "The single criteria category assessed, \"" . $only['name'] . "\", came in at " . $only['pct'] . "%.";
    }

    if (!empty($mastery_subjects) || !empty($struggling_subjects)) {
        $subj_sentence = "Looking at subject-level grades on record, ";
        $parts = [];
        if (!empty($mastery_subjects)) {
            $parts[] = "the student shows clear mastery in " . implode(', ', array_slice($mastery_subjects, 0, 4)) . (count($mastery_subjects) > 4 ? ', among others' : '');
        }
        if (!empty($struggling_subjects)) {
            $parts[] = (empty($mastery_subjects) ? "the student is struggling in " : "while struggling in ") . implode(', ', array_slice($struggling_subjects, 0, 4)) . (count($struggling_subjects) > 4 ? ', among others' : '');
        }
        $sentences[] = $subj_sentence . implode(', ', $parts) . '.';
    }

    $interpretation = implode(' ', $sentences);

    // --- Build recommendations ---
    $recommendations = [];

    $severity_advice = [
        'critical' => [
            'Arrange a parent-teacher conference as soon as possible to agree on a joint support plan.',
            'Put the student on a short, closely monitored intervention plan with weekly check-ins for the next 4-6 weeks.',
            'Consider one-on-one or small-group remedial sessions rather than relying on regular classroom pace alone.',
        ],
        'needs_improvement' => [
            'Schedule brief, regular check-ins (e.g. weekly) to track whether targeted support is closing the gap.',
            'Pair the student with a peer mentor or buddy for the weakest subjects/categories identified below.',
            'Break down remaining topics into smaller, more frequent practice sessions rather than one long review.',
        ],
        'average' => [
            'Reinforce current strengths with slightly more challenging practice to build confidence and depth.',
            'Encourage consistent daily/weekly review habits so performance becomes more predictable across terms.',
        ],
        'good' => [
            'Keep up the current routine, and introduce a few stretch tasks in weaker categories to round out performance.',
            'Recognize the student\'s progress explicitly - positive reinforcement at this stage helps sustain momentum.',
        ],
        'excellent' => [
            'Offer enrichment or advanced material so the student stays challenged and engaged.',
            'Consider involving the student in peer-tutoring or leadership roles in class to reinforce mastery.',
        ],
    ];
    $recommendations = array_merge($recommendations, $severity_advice[$overall_status] ?? []);

    // Category-specific tips for the weakest categories (top 2, only if genuinely weak/moderate)
    $category_tip_map = [
        'particip'     => 'Use more turn-and-talk or hands-raising prompts to draw the student into class discussion.',
        'behav'        => 'Set a simple, specific behavior goal for the week and review it together with the student.',
        'attend'       => 'Follow up with the family on attendance patterns, since consistent presence underlies most other gains.',
        'homework'     => 'Introduce a short daily homework check-in so gaps are caught before they compound.',
        'assignment'   => 'Break assignments into smaller checkpoints with earlier feedback rather than one final deadline.',
        'social'       => 'Create structured small-group activities to build the student\'s social/collaborative skills.',
        'communicat'   => 'Give the student more low-pressure speaking opportunities, e.g. reading aloud in pairs first.',
        'motor'        => 'Add short, playful fine/gross motor activities (cutting, drawing, ball games) into the routine.',
        'creativ'      => 'Offer more open-ended tasks (art, storytelling, building) to nurture creative expression.',
        'cognit'       => 'Use concrete, hands-on examples before moving to abstract explanations for this area.',
        'academic'     => 'Add short, focused review sessions on core concepts before introducing new material.',
        'emotion'      => 'Check in on the student\'s emotional wellbeing - consider involving the school counselor if needed.',
        'independ'     => 'Gradually increase tasks the student completes without direct supervision to build independence.',
        'punctual'     => 'Discuss punctuality with the family, since arriving late repeatedly disrupts the day\'s learning.',
    ];
    $weak_categories = array_filter($category_breakdown, function ($c) { return $c['level'] !== 'strong'; });
    $weak_categories = array_slice($weak_categories, 0, 2);
    foreach ($weak_categories as $cat) {
        $matched = null;
        foreach ($category_tip_map as $needle => $tip) {
            if (stripos($cat['name'], $needle) !== false) {
                $matched = $tip;
                break;
            }
        }
        if (!$matched) {
            $matched = 'Give the student more structured, guided practice specifically in "' . $cat['name'] . '" (currently ' . $cat['pct'] . '%).';
        }
        $recommendations[] = $matched;
    }

    // Subject-specific tips for struggling subjects (up to 3)
    $subject_tip_map = [
        'math'      => 'Use short daily drills and real-world examples (money, measuring, counting objects) to rebuild number confidence.',
        'numeracy'  => 'Use short daily drills and real-world examples (money, measuring, counting objects) to rebuild number confidence.',
        'english'   => 'Encourage 10-15 minutes of daily reading aloud, followed by simple comprehension questions.',
        'literacy'  => 'Encourage 10-15 minutes of daily reading aloud, followed by simple comprehension questions.',
        'reading'   => 'Pair the student with reading material slightly below current level to rebuild fluency and confidence first.',
        'writing'   => 'Start with short, structured writing prompts (3-5 sentences) before longer compositions.',
        'phonics'   => 'Revisit foundational sound-letter pairs with short, playful phonics games.',
        'science'   => 'Use simple hands-on experiments or demonstrations to make abstract concepts concrete.',
        'ict'       => 'Give extra guided practice time on the computer/tablet with step-by-step tasks.',
        'comput'    => 'Give extra guided practice time on the computer/tablet with step-by-step tasks.',
        'french'    => 'Introduce short daily vocabulary flashcards and simple spoken practice.',
        'social studies' => 'Use maps, timelines, or storytelling to make social studies content more concrete and memorable.',
        'religious' => 'Use storytelling and discussion-based approaches to make the content more engaging.',
        'physical'  => 'Incorporate more structured movement breaks and skill-building drills during PE.',
        'creativ'   => 'Give more open-ended creative tasks with less rigid structure to rebuild enjoyment first.',
    ];
    $subject_advice_count = 0;
    foreach ($struggling_subjects as $subject) {
        if ($subject_advice_count >= 3) break;
        $matched = null;
        foreach ($subject_tip_map as $needle => $tip) {
            if (stripos($subject, $needle) !== false) {
                $matched = $tip;
                break;
            }
        }
        if (!$matched) {
            $matched = 'Schedule short, focused extra-help sessions specifically for ' . $subject . '.';
        } else {
            $matched = ucfirst($subject) . ': ' . $matched;
        }
        $recommendations[] = $matched;
        $subject_advice_count++;
    }

    return [
        'interpretation' => $interpretation,
        'recommendations' => array_values(array_unique($recommendations)),
        'category_breakdown' => array_reverse($category_breakdown), // strongest first for display
    ];
}

// ============================================
// AJAX: DETAIL VIEW FOR A SINGLE DIAGNOSIS
// Returns per-criterion scores, subject tags, and the generated
// analysis/advice as JSON. Called when a student row is expanded.
// ============================================
if (isset($_GET['action']) && $_GET['action'] === 'details' && isset($_GET['diagnosis_id'])) {
    header('Content-Type: application/json');
    $diagnosis_id = intval($_GET['diagnosis_id']);
    $out = [
        'success' => false,
        'scores' => [],
        'mastery_subjects' => [],
        'struggling_subjects' => [],
        'comments' => '',
        'recommendations_text' => '',
        'overall_status' => null,
        'overall_score' => null,
        'academic_grade' => null,
        'analysis' => null,
    ];

    try {
        $d_stmt = $conn->prepare("SELECT comments, recommendations, overall_status, overall_score, academic_grade FROM student_diagnosis WHERE id = ? LIMIT 1");
        if ($d_stmt) {
            $d_stmt->bind_param("i", $diagnosis_id);
            $d_stmt->execute();
            $d_result = $d_stmt->get_result();
            if ($d_result && $d_result->num_rows > 0) {
                $d_row = $d_result->fetch_assoc();
                $out['comments'] = $d_row['comments'] ?? '';
                $out['recommendations_text'] = $d_row['recommendations'] ?? '';
                $out['overall_status'] = $d_row['overall_status'];
                $out['overall_score'] = (float)$d_row['overall_score'];
                $out['academic_grade'] = $d_row['academic_grade'];
                $out['success'] = true;
            }
            $d_stmt->close();
        }

        $s_stmt = $conn->prepare("
            SELECT s.score, s.remarks, c.name AS criterion_name, c.max_score, cat.name AS category_name
            FROM student_diagnosis_scores s
            JOIN diagnosis_criteria c ON c.id = s.criterion_id
            LEFT JOIN diagnosis_criteria_categories cat ON cat.id = c.category_id
            WHERE s.diagnosis_id = ?
            ORDER BY cat.sort_order, c.sort_order
        ");
        if ($s_stmt) {
            $s_stmt->bind_param("i", $diagnosis_id);
            $s_stmt->execute();
            $s_result = $s_stmt->get_result();
            if ($s_result) {
                while ($row = $s_result->fetch_assoc()) {
                    $out['scores'][] = $row;
                }
            }
            $s_stmt->close();
        }

        $t_stmt = $conn->prepare("
            SELECT t.tag_type, sub.subject_name
            FROM student_diagnosis_subject_tags t
            JOIN subjects sub ON sub.id = t.subject_id
            WHERE t.diagnosis_id = ?
        ");
        if ($t_stmt) {
            $t_stmt->bind_param("i", $diagnosis_id);
            $t_stmt->execute();
            $t_result = $t_stmt->get_result();
            if ($t_result) {
                while ($row = $t_result->fetch_assoc()) {
                    if ($row['tag_type'] === 'mastery') {
                        $out['mastery_subjects'][] = $row['subject_name'];
                    } else {
                        $out['struggling_subjects'][] = $row['subject_name'];
                    }
                }
            }
            $t_stmt->close();
        }

        if ($out['success']) {
            $out['analysis'] = analyzeDiagnosis(
                $out['overall_status'],
                $out['overall_score'],
                $out['academic_grade'],
                $out['scores'],
                $out['mastery_subjects'],
                $out['struggling_subjects']
            );
        }
    } catch (Exception $e) {
        error_log("Admin diagnosis detail error: " . $e->getMessage());
        $out['success'] = false;
        $out['message'] = 'Could not load details.';
    }

    echo json_encode($out);
    exit;
}

// ============================================
// FILTERS (shared by the grouped view and the CSV export)
// ============================================
$f_academic_year_id = isset($_GET['academic_year_id']) && $_GET['academic_year_id'] !== '' ? intval($_GET['academic_year_id']) : null;
$f_term_id           = isset($_GET['term_id']) && $_GET['term_id'] !== '' ? intval($_GET['term_id']) : null;
$f_class_id          = isset($_GET['class_id']) && $_GET['class_id'] !== '' ? intval($_GET['class_id']) : null;
$f_status            = isset($_GET['status']) && $_GET['status'] !== '' ? $_GET['status'] : null;
$f_teacher_id        = isset($_GET['teacher_id']) && $_GET['teacher_id'] !== '' ? intval($_GET['teacher_id']) : null;
$f_search            = isset($_GET['search']) ? trim($_GET['search']) : '';

$valid_statuses = array_keys($status_labels);
if ($f_status && !in_array($f_status, $valid_statuses)) {
    $f_status = null;
}

function buildDiagnosisFilters($f_academic_year_id, $f_term_id, $f_class_id, $f_status, $f_teacher_id, $f_search) {
    $where = ['1=1'];
    $types = '';
    $params = [];

    if ($f_academic_year_id) { $where[] = 'd.academic_year_id = ?'; $types .= 'i'; $params[] = $f_academic_year_id; }
    if ($f_term_id)          { $where[] = 'd.term_id = ?';          $types .= 'i'; $params[] = $f_term_id; }
    if ($f_class_id)         { $where[] = 'd.class_id = ?';         $types .= 'i'; $params[] = $f_class_id; }
    if ($f_status)           { $where[] = 'd.overall_status = ?';   $types .= 's'; $params[] = $f_status; }
    if ($f_teacher_id)       { $where[] = 'd.teacher_id = ?';       $types .= 'i'; $params[] = $f_teacher_id; }
    if ($f_search !== '')    { $where[] = 'u.name LIKE ?';          $types .= 's'; $params[] = '%' . $f_search . '%'; }

    return ['where' => implode(' AND ', $where), 'types' => $types, 'params' => $params];
}

function bindParamsByRef($stmt, $types, $params) {
    if ($types === '') return;
    $refs = [];
    $refs[] = &$types;
    foreach ($params as $key => $value) {
        $refs[] = &$params[$key];
    }
    call_user_func_array([$stmt, 'bind_param'], $refs);
}

$filters = buildDiagnosisFilters($f_academic_year_id, $f_term_id, $f_class_id, $f_status, $f_teacher_id, $f_search);

// ============================================
// CSV EXPORT (all matching rows)
// ============================================
if (isset($_GET['action']) && $_GET['action'] === 'export') {
    try {
        $sql = "
            SELECT u.name AS student_name, c.class_name, c.department,
                   t.name AS teacher_name,
                   at.term_name, ay.year_label,
                   d.overall_score, d.overall_status, d.academic_grade,
                   d.comments, d.recommendations, d.diagnosis_date, d.created_at
            FROM student_diagnosis d
            JOIN users u ON u.id = d.student_id
            JOIN classes c ON c.id = d.class_id
            JOIN users t ON t.id = d.teacher_id
            JOIN academic_terms at ON at.id = d.term_id
            JOIN academic_years ay ON ay.id = d.academic_year_id
            WHERE {$filters['where']}
            ORDER BY c.class_name ASC, u.name ASC
        ";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            throw new Exception('Failed to prepare export query: ' . $conn->error);
        }
        bindParamsByRef($stmt, $filters['types'], $filters['params']);
        $stmt->execute();
        $result = $stmt->get_result();

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=student_diagnosis_export_' . date('Y-m-d_His') . '.csv');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['Student', 'Class', 'Department', 'Teacher', 'Term', 'Academic Year', 'Score (/20)', 'Status', 'Grade', 'Comments', 'Recommendations', 'Diagnosis Date', 'Recorded At']);
        while ($row = $result->fetch_assoc()) {
            fputcsv($out, [
                $row['student_name'],
                getDisplayClassName($row['class_name']),
                $row['department'],
                $row['teacher_name'],
                $row['term_name'],
                $row['year_label'],
                round($row['overall_score'], 1),
                $status_labels[$row['overall_status']] ?? $row['overall_status'],
                $row['academic_grade'],
                $row['comments'],
                $row['recommendations'],
                $row['diagnosis_date'],
                $row['created_at'],
            ]);
        }
        fclose($out);
        $stmt->close();
        exit;
    } catch (Exception $e) {
        error_log("Admin diagnosis export error: " . $e->getMessage());
        header('Content-Type: text/plain');
        echo "Export failed: " . $e->getMessage();
        exit;
    }
}

// ============================================
// FILTER DROPDOWN DATA
// ============================================
$academic_years = [];
try {
    $ay_query = $conn->query("SELECT id, year_label, is_active FROM academic_years ORDER BY start_date DESC");
    if ($ay_query) {
        $academic_years = $ay_query->fetch_all(MYSQLI_ASSOC);
    }
} catch (Exception $e) {
    error_log("Academic years query error: " . $e->getMessage());
}

$academic_terms = [];
try {
    $at_query = $conn->query("
        SELECT t.id, t.term_name, t.term_number, t.academic_year_id, ay.year_label
        FROM academic_terms t
        JOIN academic_years ay ON ay.id = t.academic_year_id
        ORDER BY ay.start_date DESC, t.term_number ASC
    ");
    if ($at_query) {
        $academic_terms = $at_query->fetch_all(MYSQLI_ASSOC);
    }
} catch (Exception $e) {
    error_log("Academic terms query error: " . $e->getMessage());
}

$classes = [];
try {
    $c_query = $conn->query("SELECT id, class_name, department FROM classes ORDER BY class_name");
    if ($c_query) {
        $classes = $c_query->fetch_all(MYSQLI_ASSOC);
    }
} catch (Exception $e) {
    error_log("Classes query error: " . $e->getMessage());
}

$teachers = [];
try {
    $t_query = $conn->query("SELECT DISTINCT u.id, u.name FROM users u JOIN student_diagnosis d ON d.teacher_id = u.id ORDER BY u.name");
    if ($t_query) {
        $teachers = $t_query->fetch_all(MYSQLI_ASSOC);
    }
} catch (Exception $e) {
    error_log("Teachers query error: " . $e->getMessage());
}

// ============================================
// STATS FOR CURRENT FILTER
// ============================================
$stats = [
    'total' => 0, 'excellent' => 0, 'good' => 0, 'average' => 0,
    'needs_improvement' => 0, 'critical' => 0, 'avg_score' => 0
];
try {
    $sql = "
        SELECT
            COUNT(*) AS total,
            SUM(CASE WHEN d.overall_status = 'excellent' THEN 1 ELSE 0 END) AS excellent,
            SUM(CASE WHEN d.overall_status = 'good' THEN 1 ELSE 0 END) AS good,
            SUM(CASE WHEN d.overall_status = 'average' THEN 1 ELSE 0 END) AS average,
            SUM(CASE WHEN d.overall_status = 'needs_improvement' THEN 1 ELSE 0 END) AS needs_improvement,
            SUM(CASE WHEN d.overall_status = 'critical' THEN 1 ELSE 0 END) AS critical,
            ROUND(AVG(d.overall_score), 1) AS avg_score
        FROM student_diagnosis d
        JOIN users u ON u.id = d.student_id
        WHERE {$filters['where']}
    ";
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        bindParamsByRef($stmt, $filters['types'], $filters['params']);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result) {
            $row = $result->fetch_assoc();
            if ($row && $row['total'] !== null) {
                $stats = $row;
            }
        }
        $stmt->close();
    }
} catch (Exception $e) {
    error_log("Admin diagnosis stats error: " . $e->getMessage());
}

// ============================================
// FETCH RECORDS (grouped by class in PHP)
// A safety cap keeps a single page load fast; if a filter combination
// matches more than this, we show the cap and ask the admin to narrow
// down (e.g. by term or class) rather than silently truncating forever.
// ============================================
define('DIAGNOSIS_FETCH_CAP', 600);

$diagnoses = [];
try {
    $sql = "
        SELECT d.id, d.overall_score, d.overall_status, d.academic_grade,
               d.comments, d.recommendations, d.diagnosis_date, d.created_at,
               u.id AS student_id, u.name AS student_name, u.profile_picture,
               c.id AS class_id, c.class_name, c.department,
               tu.name AS teacher_name,
               at.term_name, ay.year_label
        FROM student_diagnosis d
        JOIN users u ON u.id = d.student_id
        JOIN classes c ON c.id = d.class_id
        JOIN users tu ON tu.id = d.teacher_id
        JOIN academic_terms at ON at.id = d.term_id
        JOIN academic_years ay ON ay.id = d.academic_year_id
        WHERE {$filters['where']}
        ORDER BY c.class_name ASC, d.overall_score ASC
        LIMIT ?
    ";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new Exception('Failed to prepare list query: ' . $conn->error);
    }
    $types = $filters['types'] . 'i';
    $params = $filters['params'];
    $params[] = DIAGNOSIS_FETCH_CAP;
    bindParamsByRef($stmt, $types, $params);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result) {
        $diagnoses = $result->fetch_all(MYSQLI_ASSOC);
    }
    $stmt->close();
} catch (Exception $e) {
    error_log("Admin diagnosis list error: " . $e->getMessage());
}

// ============================================
// GROUP BY CLASS + sort groups into natural grade order
// ============================================
$groups = [];
foreach ($diagnoses as $d) {
    $cid = $d['class_id'];
    if (!isset($groups[$cid])) {
        $groups[$cid] = [
            'class_id' => $cid,
            'class_name' => $d['class_name'],
            'department' => $d['department'],
            'rows' => [],
            'sum_score' => 0,
        ];
    }
    $groups[$cid]['rows'][] = $d;
    $groups[$cid]['sum_score'] += (float)$d['overall_score'];
}
uasort($groups, function ($a, $b) use ($class_grade_order) {
    $oa = $class_grade_order[$a['class_name']] ?? 999;
    $ob = $class_grade_order[$b['class_name']] ?? 999;
    if ($oa === $ob) return strcmp($a['class_name'], $b['class_name']);
    return $oa <=> $ob;
});

// ============================================
// BUILD QUERY STRING HELPER
// ============================================
function buildQueryString($overrides = []) {
    $params = array_merge($_GET, $overrides);
    foreach ($params as $key => $value) {
        if ($value === null || $value === '') {
            unset($params[$key]);
        }
    }
    return http_build_query($params);
}

// ============================================
// INCLUDE ADMIN HEADER TEMPLATE
// ============================================
$header_paths = [
    $_SERVER['DOCUMENT_ROOT'] . '/templates/admin_header.php',
    __DIR__ . '/../templates/admin_header.php',
    __DIR__ . '/../../templates/admin_header.php',
    __DIR__ . '/templates/admin_header.php',
    __DIR__ . '/includes/templates/admin_header.php'
];

$header_included = false;
foreach ($header_paths as $path) {
    if (file_exists($path)) {
        require_once $path;
        $header_included = true;
        break;
    }
}

if (!$header_included) {
    error_log("Warning: admin_header.php not found in any expected location");
    if (!function_exists('render_admin_header')) {
        function render_admin_header($title, $admin_name) {
            ?>
            <!DOCTYPE html>
            <html lang="en">
            <head>
                <meta charset="UTF-8">
                <meta name="viewport" content="width=device-width, initial-scale=1.0">
                <title><?php echo htmlspecialchars($title); ?></title>
                <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
                <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
            </head>
            <body>
                <nav class="navbar navbar-expand-lg navbar-dark bg-dark">
                    <div class="container-fluid">
                        <a class="navbar-brand" href="dashboard.php">Admin Portal</a>
                        <div class="navbar-nav ms-auto">
                            <span class="navbar-text text-white me-3">
                                <i class="fas fa-user-shield me-1"></i><?php echo htmlspecialchars($admin_name); ?>
                            </span>
                            <a class="nav-link" href="logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a>
                        </div>
                    </div>
                </nav>
                <div class="container-fluid mt-3">
            <?php
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Diagnoses - Admin Portal</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #4F46E5;
            --primary-dark: #4338CA;
            --primary-light: #EEF2FF;
            --secondary: #7C3AED;
            --success: #16A34A;
            --success-bg: #F0FDF4;
            --warning: #D97706;
            --warning-bg: #FFFBEB;
            --danger: #DC2626;
            --danger-bg: #FEF2F2;
            --info: #0891B2;
            --ink: #1E293B;
            --muted: #64748B;
            --line: #E2E8F0;
            --canvas: #F4F5F9;
            --card: #ffffff;
            --radius-lg: 20px;
            --radius-md: 14px;
            --radius-sm: 10px;
            --shadow-sm: 0 1px 3px rgba(15,23,42,0.06);
            --shadow-md: 0 8px 24px rgba(15,23,42,0.08);
        }
        * { box-sizing: border-box; }
        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: var(--canvas);
            color: var(--ink);
            padding: 24px;
            -webkit-font-smoothing: antialiased;
        }
        .container-fluid { max-width: 1560px; margin: 0 auto; padding: 0; }

        /* ---------- Hero header ---------- */
        .hero {
            background: radial-gradient(120% 180% at 0% 0%, #6D5DF6 0%, var(--primary) 45%, var(--secondary) 100%);
            color: white;
            padding: 32px 36px;
            border-radius: var(--radius-lg);
            margin-bottom: 22px;
            box-shadow: var(--shadow-md);
            position: relative;
            overflow: hidden;
        }
        .hero::after {
            content: '';
            position: absolute; top: -60px; right: -60px;
            width: 240px; height: 240px; border-radius: 50%;
            background: rgba(255,255,255,0.08);
        }
        .hero h1 { margin: 0; font-weight: 800; font-size: 1.7rem; letter-spacing: -0.02em; }
        .hero p { margin: 6px 0 0; opacity: 0.9; font-size: 0.95rem; }
        .hero .btn-export {
            background: rgba(255,255,255,0.15);
            border: 1px solid rgba(255,255,255,0.35);
            color: white;
            border-radius: 12px;
            padding: 10px 20px;
            font-weight: 600;
            font-size: 0.88rem;
            backdrop-filter: blur(4px);
            transition: all 0.2s;
            text-decoration: none;
            white-space: nowrap;
        }
        .hero .btn-export:hover { background: rgba(255,255,255,0.28); color: white; transform: translateY(-1px); }

        /* ---------- Sticky filter bar ---------- */
        .filter-bar {
            background: var(--card);
            border-radius: var(--radius-md);
            padding: 16px 20px;
            margin-bottom: 20px;
            box-shadow: var(--shadow-sm);
            border: 1px solid var(--line);
            position: sticky;
            top: 12px;
            z-index: 50;
        }
        .filter-bar .form-label { font-size: 0.72rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; color: var(--muted); margin-bottom: 4px; }
        .filter-bar select, .filter-bar input {
            border-radius: 10px;
            border: 1.5px solid var(--line);
            padding: 8px 12px;
            font-size: 0.87rem;
            width: 100%;
            background: #fbfbfe;
            transition: all 0.15s;
        }
        .filter-bar select:focus, .filter-bar input:focus {
            border-color: var(--primary);
            outline: none;
            box-shadow: 0 0 0 3px var(--primary-light);
            background: white;
        }
        .clear-link { font-size: 0.82rem; color: var(--muted); text-decoration: none; display: inline-flex; align-items: center; gap: 5px; }
        .clear-link:hover { color: var(--danger); }

        /* ---------- Stat strip ---------- */
        .stat-strip { display: grid; grid-template-columns: repeat(6, 1fr); gap: 12px; margin-bottom: 22px; }
        .stat-card {
            background: var(--card);
            border-radius: var(--radius-md);
            padding: 16px 14px;
            text-align: center;
            box-shadow: var(--shadow-sm);
            border: 1px solid var(--line);
            border-bottom: 3px solid transparent;
            transition: transform 0.15s;
        }
        .stat-card:hover { transform: translateY(-2px); }
        .stat-card .number { font-size: 1.7rem; font-weight: 800; line-height: 1; }
        .stat-card .label { font-size: 0.72rem; color: var(--muted); margin-top: 6px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.3px; }
        .stat-card.total { border-bottom-color: var(--primary); }
        .stat-card.excellent { border-bottom-color: var(--success); }
        .stat-card.good { border-bottom-color: #6366F1; }
        .stat-card.average { border-bottom-color: var(--warning); }
        .stat-card.warn { border-bottom-color: #F59E0B; }
        .stat-card.critical { border-bottom-color: var(--danger); }
        .stat-card.excellent .number { color: var(--success); }
        .stat-card.good .number { color: #6366F1; }
        .stat-card.average .number, .stat-card.warn .number { color: var(--warning); }
        .stat-card.critical .number { color: var(--danger); }

        /* ---------- Class jump nav ---------- */
        .class-jump {
            display: flex; flex-wrap: wrap; gap: 8px;
            margin-bottom: 18px;
        }
        .class-jump a {
            background: var(--card); border: 1px solid var(--line);
            border-radius: 999px; padding: 6px 16px; font-size: 0.82rem; font-weight: 600;
            color: var(--ink); text-decoration: none; box-shadow: var(--shadow-sm);
            transition: all 0.15s; display: inline-flex; align-items: center; gap: 6px;
        }
        .class-jump a:hover { background: var(--primary); color: white; transform: translateY(-1px); }
        .class-jump a .count { background: rgba(0,0,0,0.08); border-radius: 999px; padding: 1px 8px; font-size: 0.72rem; }
        .class-jump a:hover .count { background: rgba(255,255,255,0.25); }
        .toolbar-row { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px; margin-bottom: 14px; }
        .toggle-all-btn {
            font-size: 0.82rem; font-weight: 600; color: var(--primary);
            background: var(--primary-light); border: none; border-radius: 999px;
            padding: 7px 16px; cursor: pointer; transition: all 0.15s;
        }
        .toggle-all-btn:hover { background: var(--primary); color: white; }

        /* ---------- Class group ---------- */
        .class-group {
            background: var(--card);
            border-radius: var(--radius-lg);
            margin-bottom: 18px;
            box-shadow: var(--shadow-sm);
            border: 1px solid var(--line);
            overflow: hidden;
            scroll-margin-top: 90px;
        }
        .class-group > summary {
            list-style: none;
            cursor: pointer;
            padding: 18px 24px;
            display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px;
            background: linear-gradient(180deg, #FAFAFF 0%, #F5F5FC 100%);
            border-bottom: 1px solid var(--line);
        }
        .class-group > summary::-webkit-details-marker { display: none; }
        .class-group-title { display: flex; align-items: center; gap: 12px; }
        .class-group-title .class-icon {
            width: 44px; height: 44px; border-radius: 12px;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: white; display: flex; align-items: center; justify-content: center;
            font-size: 1.1rem; font-weight: 800; flex-shrink: 0;
        }
        .class-group-title h3 { margin: 0; font-size: 1.1rem; font-weight: 700; }
        .class-group-title .dept-tag { font-size: 0.76rem; color: var(--muted); font-weight: 500; }
        .class-group-meta { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }
        .meta-chip {
            font-size: 0.78rem; font-weight: 600; padding: 5px 12px; border-radius: 999px;
            background: white; border: 1px solid var(--line); color: var(--ink);
        }
        .meta-chip.avg-good { color: var(--success); border-color: #BBF7D0; background: var(--success-bg); }
        .meta-chip.avg-mid { color: var(--warning); border-color: #FDE68A; background: var(--warning-bg); }
        .meta-chip.avg-low { color: var(--danger); border-color: #FECACA; background: var(--danger-bg); }
        .chevron-toggle { color: var(--muted); transition: transform 0.2s; font-size: 1rem; }
        .class-group[open] .chevron-toggle { transform: rotate(90deg); }

        .class-group-body { padding: 6px 12px 14px; }

        /* ---------- Student rows ---------- */
        table.diagnosis-table { width: 100%; border-collapse: separate; border-spacing: 0; }
        table.diagnosis-table thead th {
            text-align: left; font-size: 0.7rem; text-transform: uppercase; letter-spacing: 0.5px;
            color: var(--muted); padding: 10px 14px; font-weight: 700; border-bottom: 1px solid var(--line);
        }
        table.diagnosis-table tbody tr.diag-row { transition: background 0.12s; }
        table.diagnosis-table tbody tr.diag-row:hover { background: #FAFAFE; cursor: pointer; }
        table.diagnosis-table td { padding: 12px 14px; border-bottom: 1px solid #F1F2F6; vertical-align: middle; font-size: 0.88rem; }
        table.diagnosis-table tbody tr:last-child td { border-bottom: none; }

        .student-mini { display: flex; align-items: center; gap: 10px; }
        .avatar-sm {
            width: 36px; height: 36px; border-radius: 10px; flex-shrink: 0; overflow: hidden;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: white; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 0.85rem;
        }
        .avatar-sm img { width: 100%; height: 100%; object-fit: cover; }
        .student-name { font-weight: 600; }

        .score-badge {
            font-weight: 800; font-size: 0.92rem; display: inline-flex; align-items: center; gap: 4px;
        }
        .status-chip {
            display: inline-flex; align-items: center; gap: 5px;
            font-size: 0.78rem; font-weight: 700; padding: 5px 12px; border-radius: 999px;
        }
        .status-chip.excellent { background: var(--success-bg); color: var(--success); }
        .status-chip.good { background: #EEF2FF; color: #4338CA; }
        .status-chip.average { background: var(--warning-bg); color: var(--warning); }
        .status-chip.warn { background: #FFF7ED; color: #C2410C; }
        .status-chip.critical { background: var(--danger-bg); color: var(--danger); }

        .row-chevron { color: #C4C9D4; font-size: 0.85rem; transition: transform 0.2s; }
        tr.diag-row.expanded .row-chevron { transform: rotate(90deg); color: var(--primary); }

        /* ---------- Detail panel ---------- */
        .detail-row td { background: #FBFBFE; padding: 0; }
        .detail-panel { padding: 4px 18px 22px 60px; }
        .detail-loading { color: var(--muted); font-size: 0.85rem; padding: 16px 0; }

        .analysis-card {
            background: linear-gradient(135deg, #F5F3FF 0%, #EEF2FF 100%);
            border: 1px solid #E0E7FF;
            border-radius: var(--radius-md);
            padding: 18px 20px;
            margin-top: 14px;
            margin-bottom: 16px;
        }
        .analysis-card .analysis-head {
            display: flex; align-items: center; gap: 10px; margin-bottom: 10px;
        }
        .analysis-card .analysis-head .icon-wrap {
            width: 32px; height: 32px; border-radius: 9px; background: var(--primary);
            color: white; display: flex; align-items: center; justify-content: center; font-size: 0.85rem;
        }
        .analysis-card .analysis-head h5 { margin: 0; font-size: 0.95rem; font-weight: 700; color: #3730A3; }
        .analysis-card .analysis-head small { display: block; color: #6D6D9E; font-size: 0.72rem; }
        .analysis-text { font-size: 0.87rem; line-height: 1.6; color: #312E5A; }

        .advice-list { margin: 12px 0 0; padding: 0; list-style: none; }
        .advice-list li {
            display: flex; align-items: flex-start; gap: 10px;
            font-size: 0.85rem; padding: 8px 0; border-top: 1px dashed #DEDBF7;
            color: var(--ink); line-height: 1.5;
        }
        .advice-list li:first-child { border-top: none; }
        .advice-list li i { color: var(--primary); margin-top: 3px; flex-shrink: 0; }

        .category-bars { margin-top: 6px; }
        .category-bar-row { display: flex; align-items: center; gap: 10px; padding: 5px 0; font-size: 0.82rem; }
        .category-bar-row .cat-name { width: 160px; flex-shrink: 0; color: var(--muted); font-weight: 600; }
        .category-bar-track { flex: 1; height: 8px; border-radius: 6px; background: #E9E9F5; overflow: hidden; }
        .category-bar-fill { height: 100%; border-radius: 6px; }
        .category-bar-fill.strong { background: var(--success); }
        .category-bar-fill.moderate { background: var(--warning); }
        .category-bar-fill.weak { background: var(--danger); }
        .category-bar-pct { width: 44px; text-align: right; font-weight: 700; flex-shrink: 0; }

        .tag-pill { display: inline-flex; align-items: center; gap: 4px; padding: 4px 12px; border-radius: 999px; font-size: 0.75rem; font-weight: 700; margin: 2px 5px 2px 0; }
        .tag-pill.mastery { background: var(--success-bg); color: var(--success); }
        .tag-pill.struggling { background: var(--danger-bg); color: var(--danger); }

        .section-label { font-size: 0.72rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; color: var(--muted); margin: 16px 0 8px; }
        .free-text-block { font-size: 0.85rem; background: white; border: 1px solid var(--line); border-radius: 10px; padding: 10px 14px; color: var(--ink); }

        .empty-state { text-align: center; padding: 60px 20px; color: var(--muted); }
        .empty-state i { opacity: 0.2; }
        .cap-warning { background: #FFFBEB; border: 1px solid #FDE68A; color: #92400E; border-radius: 12px; padding: 10px 16px; font-size: 0.85rem; margin-bottom: 18px; }

        @media (max-width: 992px) {
            .stat-strip { grid-template-columns: repeat(3, 1fr); }
        }
        @media (max-width: 768px) {
            body { padding: 12px; }
            .stat-strip { grid-template-columns: repeat(2, 1fr); }
            .detail-panel { padding-left: 20px; }
            .category-bar-row .cat-name { width: 100px; font-size: 0.75rem; }
            table.diagnosis-table { font-size: 0.8rem; }
        }
    </style>
</head>
<body>
    <div class="template-header-wrapper">
        <?php
        if (function_exists('render_admin_header')) {
            render_admin_header('Student Diagnoses', $admin_name);
        }
        ?>
    </div>

    <div class="container-fluid">
        <!-- ============================================ -->
        <!-- HERO -->
        <!-- ============================================ -->
        <div class="hero d-flex justify-content-between align-items-center flex-wrap">
            <div>
                <h1><i class="fas fa-stethoscope me-2"></i>Student Diagnoses</h1>
                <p>School-wide view, grouped by class, with automatic interpretation and improvement advice for every record</p>
            </div>
            <a class="btn-export" href="?<?php echo htmlspecialchars(buildQueryString(['action' => 'export'])); ?>">
                <i class="fas fa-file-csv me-2"></i>Export CSV
            </a>
        </div>

        <!-- ============================================ -->
        <!-- FILTERS -->
        <!-- ============================================ -->
        <div class="filter-bar">
            <form method="GET" action="" class="row g-3 align-items-end">
                <div class="col-6 col-md-2">
                    <label class="form-label">Academic Year</label>
                    <select name="academic_year_id" onchange="this.form.submit()">
                        <option value="">All Years</option>
                        <?php foreach ($academic_years as $ay): ?>
                            <option value="<?php echo (int)$ay['id']; ?>" <?php echo $f_academic_year_id == $ay['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($ay['year_label']); ?><?php echo $ay['is_active'] ? ' (Active)' : ''; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-6 col-md-2">
                    <label class="form-label">Term</label>
                    <select name="term_id" onchange="this.form.submit()">
                        <option value="">All Terms</option>
                        <?php foreach ($academic_terms as $term): ?>
                            <?php if ($f_academic_year_id && $term['academic_year_id'] != $f_academic_year_id) continue; ?>
                            <option value="<?php echo (int)$term['id']; ?>" <?php echo $f_term_id == $term['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($term['term_name']); ?> (<?php echo htmlspecialchars($term['year_label']); ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-6 col-md-2">
                    <label class="form-label">Class</label>
                    <select name="class_id" onchange="this.form.submit()">
                        <option value="">All Classes</option>
                        <?php foreach ($classes as $class): ?>
                            <option value="<?php echo (int)$class['id']; ?>" <?php echo $f_class_id == $class['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars(getDisplayClassName($class['class_name'])); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-6 col-md-2">
                    <label class="form-label">Status</label>
                    <select name="status" onchange="this.form.submit()">
                        <option value="">All Statuses</option>
                        <?php foreach ($status_labels as $key => $label): ?>
                            <option value="<?php echo htmlspecialchars($key); ?>" <?php echo $f_status === $key ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($label); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-6 col-md-2">
                    <label class="form-label">Teacher</label>
                    <select name="teacher_id" onchange="this.form.submit()">
                        <option value="">All Teachers</option>
                        <?php foreach ($teachers as $t): ?>
                            <option value="<?php echo (int)$t['id']; ?>" <?php echo $f_teacher_id == $t['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($t['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-6 col-md-2">
                    <label class="form-label">Student Name</label>
                    <div class="input-group">
                        <input type="text" name="search" placeholder="Search..." value="<?php echo htmlspecialchars($f_search); ?>">
                    </div>
                </div>
                <div class="col-12 d-flex justify-content-between align-items-center">
                    <button type="submit" class="btn btn-sm" style="background:var(--primary); color:white; border-radius:10px; font-weight:600; padding:6px 18px;">
                        <i class="fas fa-search me-1"></i> Apply
                    </button>
                    <?php if ($f_academic_year_id || $f_term_id || $f_class_id || $f_status || $f_teacher_id || $f_search !== ''): ?>
                    <a href="?" class="clear-link"><i class="fas fa-times-circle"></i> Clear all filters</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <!-- ============================================ -->
        <!-- STATS STRIP -->
        <!-- ============================================ -->
        <div class="stat-strip">
            <div class="stat-card total"><div class="number"><?php echo $stats['total'] ?? 0; ?></div><div class="label">Total</div></div>
            <div class="stat-card excellent"><div class="number"><?php echo $stats['excellent'] ?? 0; ?></div><div class="label">Excellent</div></div>
            <div class="stat-card good"><div class="number"><?php echo $stats['good'] ?? 0; ?></div><div class="label">Good</div></div>
            <div class="stat-card average"><div class="number"><?php echo $stats['average'] ?? 0; ?></div><div class="label">Average</div></div>
            <div class="stat-card warn"><div class="number"><?php echo $stats['needs_improvement'] ?? 0; ?></div><div class="label">Needs Attn.</div></div>
            <div class="stat-card critical"><div class="number"><?php echo $stats['critical'] ?? 0; ?></div><div class="label">Critical</div></div>
        </div>

        <?php if (($stats['total'] ?? 0) > DIAGNOSIS_FETCH_CAP): ?>
        <div class="cap-warning">
            <i class="fas fa-circle-info me-2"></i>
            Showing the first <?php echo DIAGNOSIS_FETCH_CAP; ?> of <?php echo $stats['total']; ?> matching records, grouped by class. Narrow the filters above (e.g. by term or class) to see everything.
        </div>
        <?php endif; ?>

        <?php if (empty($groups)): ?>
            <div class="class-group">
                <div class="empty-state">
                    <i class="fas fa-folder-open fa-4x mb-3"></i>
                    <h5>No diagnosis records match these filters</h5>
                    <p>Try widening the filters above, or check back once teachers have submitted diagnoses.</p>
                </div>
            </div>
        <?php else: ?>

        <!-- ============================================ -->
        <!-- CLASS JUMP NAV + TOOLBAR -->
        <!-- ============================================ -->
        <div class="toolbar-row">
            <div class="class-jump">
                <?php foreach ($groups as $g): ?>
                    <a href="#class-<?php echo (int)$g['class_id']; ?>">
                        <?php echo htmlspecialchars(getDisplayClassName($g['class_name'])); ?>
                        <span class="count"><?php echo count($g['rows']); ?></span>
                    </a>
                <?php endforeach; ?>
            </div>
            <button type="button" class="toggle-all-btn" onclick="toggleAllGroups()"><i class="fas fa-up-right-and-down-left-from-center me-1"></i><span id="toggleAllLabel">Expand all classes</span></button>
        </div>

        <!-- ============================================ -->
        <!-- GROUPED BY CLASS -->
        <!-- ============================================ -->
        <?php foreach ($groups as $g):
            $avg = count($g['rows']) > 0 ? $g['sum_score'] / count($g['rows']) : 0;
            $avg_class = $avg >= 14 ? 'avg-good' : ($avg >= 10 ? 'avg-mid' : 'avg-low');
        ?>
        <details class="class-group" id="class-<?php echo (int)$g['class_id']; ?>" open>
            <summary>
                <div class="class-group-title">
                    <div class="class-icon"><?php echo htmlspecialchars(strtoupper(substr(getDisplayClassName($g['class_name']), 0, 2))); ?></div>
                    <div>
                        <h3><?php echo htmlspecialchars(getDisplayClassName($g['class_name'])); ?></h3>
                        <span class="dept-tag"><?php echo htmlspecialchars($g['department'] ?? ''); ?></span>
                    </div>
                </div>
                <div class="class-group-meta">
                    <span class="meta-chip"><i class="fas fa-users me-1"></i><?php echo count($g['rows']); ?> diagnosed</span>
                    <span class="meta-chip <?php echo $avg_class; ?>"><i class="fas fa-chart-line me-1"></i>Avg <?php echo round($avg, 1); ?>/20</span>
                    <i class="fas fa-chevron-right chevron-toggle"></i>
                </div>
            </summary>
            <div class="class-group-body">
                <table class="diagnosis-table">
                    <thead>
                        <tr>
                            <th style="width:20px;"></th>
                            <th>Student</th>
                            <th>Teacher</th>
                            <th>Term / Year</th>
                            <th>Score</th>
                            <th>Grade</th>
                            <th>Status</th>
                            <th>Recorded</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($g['rows'] as $d): ?>
                        <tr class="diag-row" data-diagnosis-id="<?php echo (int)$d['id']; ?>" onclick="toggleDetails(this)">
                            <td><i class="fas fa-chevron-right row-chevron"></i></td>
                            <td>
                                <div class="student-mini">
                                    <div class="avatar-sm">
                                        <?php if (!empty($d['profile_picture'])): ?>
                                            <img src="<?php echo htmlspecialchars($d['profile_picture']); ?>" alt="">
                                        <?php else: ?>
                                            <?php echo htmlspecialchars(strtoupper(substr($d['student_name'], 0, 1))); ?>
                                        <?php endif; ?>
                                    </div>
                                    <span class="student-name"><?php echo htmlspecialchars($d['student_name']); ?></span>
                                </div>
                            </td>
                            <td><?php echo htmlspecialchars($d['teacher_name']); ?></td>
                            <td><?php echo htmlspecialchars($d['term_name']); ?> <small class="text-muted">(<?php echo htmlspecialchars($d['year_label']); ?>)</small></td>
                            <td><span class="score-badge"><?php echo round($d['overall_score'], 1); ?>/20</span></td>
                            <td><?php echo htmlspecialchars($d['academic_grade'] ?? '-'); ?></td>
                            <td>
                                <span class="status-chip <?php echo $status_badge_class[$d['overall_status']] ?? ''; ?>">
                                    <?php echo $status_icon[$d['overall_status']] ?? ''; ?> <?php echo htmlspecialchars($status_labels[$d['overall_status']] ?? ucfirst($d['overall_status'])); ?>
                                </span>
                            </td>
                            <td><small class="text-muted"><?php echo date('M d, Y', strtotime($d['created_at'])); ?></small></td>
                        </tr>
                        <tr class="detail-row" id="detail-row-<?php echo (int)$d['id']; ?>" style="display:none;">
                            <td colspan="8">
                                <div class="detail-panel" id="detail-panel-<?php echo (int)$d['id']; ?>">
                                    <div class="detail-loading"><i class="fas fa-spinner fa-spin me-2"></i>Generating analysis...</div>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </details>
        <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <script>
    const detailCache = {};
    const statusIcons = {excellent:'🌟', good:'👍', average:'📝', needs_improvement:'⚠️', critical:'🔴'};

    function toggleDetails(rowEl) {
        const id = rowEl.getAttribute('data-diagnosis-id');
        const detailRow = document.getElementById('detail-row-' + id);
        const panel = document.getElementById('detail-panel-' + id);
        if (!detailRow) return;

        const isOpen = detailRow.style.display !== 'none';
        if (isOpen) {
            detailRow.style.display = 'none';
            rowEl.classList.remove('expanded');
            return;
        }

        detailRow.style.display = 'table-row';
        rowEl.classList.add('expanded');

        if (detailCache[id]) {
            panel.innerHTML = detailCache[id];
            return;
        }

        fetch('?action=details&diagnosis_id=' + encodeURIComponent(id))
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (!data.success) {
                    panel.innerHTML = '<div class="text-danger small py-3">Could not load details for this diagnosis.</div>';
                    return;
                }
                panel.innerHTML = renderDetail(data);
                detailCache[id] = panel.innerHTML;
            })
            .catch(function(err) {
                panel.innerHTML = '<div class="text-danger small py-3">Error loading details.</div>';
                console.error(err);
            });
    }

    function renderDetail(data) {
        let html = '';

        // Subject tags
        if (data.mastery_subjects.length || data.struggling_subjects.length) {
            html += '<div class="mt-3">';
            data.mastery_subjects.forEach(function(name) {
                html += '<span class="tag-pill mastery"><i class="fas fa-star"></i>' + escapeHtml(name) + '</span>';
            });
            data.struggling_subjects.forEach(function(name) {
                html += '<span class="tag-pill struggling"><i class="fas fa-triangle-exclamation"></i>' + escapeHtml(name) + '</span>';
            });
            html += '</div>';
        }

        // Category breakdown bars
        if (data.analysis && data.analysis.category_breakdown && data.analysis.category_breakdown.length) {
            html += '<div class="section-label">Criteria Breakdown</div><div class="category-bars">';
            data.analysis.category_breakdown.forEach(function(cat) {
                html += '<div class="category-bar-row">' +
                            '<div class="cat-name">' + escapeHtml(cat.name) + '</div>' +
                            '<div class="category-bar-track"><div class="category-bar-fill ' + cat.level + '" style="width:' + Math.min(cat.pct, 100) + '%;"></div></div>' +
                            '<div class="category-bar-pct">' + cat.pct + '%</div>' +
                        '</div>';
            });
            html += '</div>';
        }

        // Auto-generated analysis + advice
        if (data.analysis) {
            html += '<div class="analysis-card">' +
                        '<div class="analysis-head">' +
                            '<div class="icon-wrap"><i class="fas fa-brain"></i></div>' +
                            '<div><h5>Diagnostic Analysis</h5><small>Auto-generated from this record\'s scores and subject tags</small></div>' +
                        '</div>' +
                        '<div class="analysis-text">' + escapeHtml(data.analysis.interpretation) + '</div>';
            if (data.analysis.recommendations && data.analysis.recommendations.length) {
                html += '<ul class="advice-list">';
                data.analysis.recommendations.forEach(function(tip) {
                    html += '<li><i class="fas fa-circle-check"></i><span>' + escapeHtml(tip) + '</span></li>';
                });
                html += '</ul>';
            }
            html += '</div>';
        }

        // Teacher's own comments / recommendations, if any
        if (data.comments) {
            html += '<div class="section-label">Teacher Comments</div><div class="free-text-block">' + escapeHtml(data.comments) + '</div>';
        }
        if (data.recommendations_text) {
            html += '<div class="section-label">Teacher Recommendations</div><div class="free-text-block">' + escapeHtml(data.recommendations_text) + '</div>';
        }

        return html;
    }

    function toggleAllGroups() {
        const groups = document.querySelectorAll('.class-group');
        const label = document.getElementById('toggleAllLabel');
        const anyClosed = Array.from(groups).some(function(g) { return !g.open; });
        groups.forEach(function(g) { g.open = anyClosed; });
        label.textContent = anyClosed ? 'Collapse all classes' : 'Expand all classes';
    }

    function escapeHtml(str) {
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }
    </script>
</body>
</html>