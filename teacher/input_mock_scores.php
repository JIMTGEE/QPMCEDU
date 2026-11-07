<?php
ob_start();
session_start();
/* -------------------------------------------------
   SECURITY & SESSION
   ------------------------------------------------- */
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'teacher') {
    $_SESSION['error_msg'] = 'Please log in as a teacher.';
    header('Location: /login.php');
    exit;
}
$teacher_id = (int)$_SESSION['user_id'];
/* -------------------------------------------------
   DB CONNECTION
   ------------------------------------------------- */
require $_SERVER['DOCUMENT_ROOT'] . '/config/db.php';
if (!$conn) {
    error_log('DB connection failed');
    $_SESSION['error_msg'] = 'Database error.';
    header('Location: /login.php');
    exit;
}
/* -------------------------------------------------
   CSRF TOKEN
   ------------------------------------------------- */
$csrf_token = $_SESSION['csrf_mock'] ?? bin2hex(random_bytes(32));
$_SESSION['csrf_mock'] = $csrf_token;
/* -------------------------------------------------
   CURRENT TERM & ACADEMIC YEAR
   ------------------------------------------------- */
$today = new DateTime();
$year_start = $today->format('Y');
$year_end = $today->format('Y') + 1;
$academic_year_for_classes = "$year_start/$year_end";
$academic_year_for_mock = $year_start;

// Get all available academic years for mocks
$years_sql = "SELECT DISTINCT academic_year FROM mock_entries WHERE class_id IN (SELECT id FROM classes WHERE class_name = 'Majesty') ORDER BY academic_year DESC";
$years_result = $conn->query($years_sql);
$available_years = [];
while ($row = $years_result->fetch_assoc()) {
    $available_years[] = $row['academic_year'];
}
// Add current year if not present
if (!in_array($academic_year_for_mock, $available_years)) {
    $available_years[] = $academic_year_for_mock;
}

$selected_year = $academic_year_for_mock; // Default to current year
if (isset($_GET['year']) && in_array($_GET['year'], $available_years)) {
    $selected_year = $_GET['year'];
}

$term_key = "current_term_$teacher_id";
if (!isset($_SESSION[$term_key])) {
    $sql = "SELECT id, term_name, start_date, end_date
            FROM academic_calendar
            WHERE start_date <= CURDATE() AND end_date >= CURDATE()
            LIMIT 1";
    $res = $conn->query($sql);
    $_SESSION[$term_key] = $res && $res->num_rows ? $res->fetch_assoc() : null;
}
$current_term = $_SESSION[$term_key];
$current_term_id = $current_term['id'] ?? 1;

/* -------------------------------------------------
   MAJESTY CLASS → DEPARTMENT → SUBJECTS
   ------------------------------------------------- */
$majesty_res = $conn->query("SELECT id, department_id FROM classes WHERE class_name = 'Majesty' LIMIT 1");
if (!$majesty_res || $majesty_res->num_rows === 0) {
    $_SESSION['error_msg'] = 'Majesty class not found.';
    header('Location: /teacher/dashboard.php');
    exit;
}
$majesty = $majesty_res->fetch_assoc();
$majesty_id = $majesty['id'];
$department_id = $majesty['department_id'];

// Fetch subjects
$subj_sql = "SELECT id, subject_name FROM subjects WHERE department_id = ? ORDER BY subject_name";
$subj_stmt = $conn->prepare($subj_sql);
$subj_stmt->bind_param('i', $department_id);
$subj_stmt->execute();
$subjects = $subj_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$subj_stmt->close();

// BLOCKED SUBJECTS
$blocked_subjects = ['Music', 'Chinese'];

/* -------------------------------------------------
   STUDENTS IN MAJESTY (for selected year)
   ------------------------------------------------- */
// First, get students from student_classes for the selected year
$stu_sql = "SELECT DISTINCT u.id, u.name
            FROM users u
            JOIN student_classes sc ON u.id = sc.user_id
            WHERE sc.class_id = ? 
            AND (
                sc.academic_year = ? 
                OR ? IN (SELECT academic_year FROM student_classes WHERE user_id = u.id AND class_id = ?)
            )
            AND u.role = 'student'
            ORDER BY u.name";
$stu_stmt = $conn->prepare($stu_sql);
$stu_stmt->bind_param('isis', $majesty_id, $selected_year, $selected_year, $majesty_id);
$stu_stmt->execute();
$students = $stu_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stu_stmt->close();

// If no students found for selected year, try previous year
if (empty($students)) {
    $prev_year = (int)$selected_year - 1;
    $stu_sql = "SELECT DISTINCT u.id, u.name
                FROM users u
                JOIN student_classes sc ON u.id = sc.user_id
                WHERE sc.class_id = ? 
                AND sc.academic_year = ?
                AND u.role = 'student'
                ORDER BY u.name";
    $stu_stmt = $conn->prepare($stu_sql);
    $stu_stmt->bind_param('is', $majesty_id, $prev_year);
    $stu_stmt->execute();
    $students = $stu_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stu_stmt->close();
}

/* -------------------------------------------------
   GET AVAILABLE MOCK NUMBERS FOR SELECTED YEAR
   ------------------------------------------------- */
$mock_numbers_sql = "SELECT DISTINCT mock_number FROM mock_entries 
                     WHERE class_id = ? AND academic_year = ? 
                     ORDER BY mock_number DESC";
$mock_stmt = $conn->prepare($mock_numbers_sql);
$mock_stmt->bind_param('is', $majesty_id, $selected_year);
$mock_stmt->execute();
$mock_numbers_result = $mock_stmt->get_result();
$existing_mocks = [];
while ($row = $mock_numbers_result->fetch_assoc()) {
    $existing_mocks[] = $row['mock_number'];
}
$mock_stmt->close();

/* -------------------------------------------------
   GRADE → REMARK MAPPING
   ------------------------------------------------- */
$grade_remarks = [
    1 => 'Highest',
    2 => 'Higher',
    3 => 'High',
    4 => 'High Average',
    5 => 'Average',
    6 => 'Low Average',
    7 => 'Low',
    8 => 'Lower',
    9 => 'Lowest'
];

function getNumericGrade($score): ?int {
    if ($score === null) return null;
    if ($score >= 95) return 1;
    if ($score >= 85) return 2;
    if ($score >= 75) return 3;
    if ($score >= 65) return 4;
    if ($score >= 55) return 5;
    if ($score >= 45) return 6;
    if ($score >= 36) return 7;
    if ($score >= 31) return 8;
    return 9;
}

function getRemarkFromGrade($grade): string {
    global $grade_remarks;
    return $grade_remarks[$grade] ?? '-';
}

/* -------------------------------------------------
   AJAX HANDLERS
   ------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    ob_clean();
    header('Content-Type: application/json');
    if (($_POST['csrf_token'] ?? '') !== $csrf_token) {
        echo json_encode(['success' => false, 'msg' => 'Invalid CSRF token']);
        exit;
    }

    /* ---------- LOAD MOCK ---------- */
    if ($_POST['action'] === 'load_mock') {
        $mock = (int)($_POST['mock_number'] ?? 1);
        $year = $_POST['academic_year'] ?? $selected_year;
        
        $ex_sql = "SELECT id, student_id, subject_id, mock_raw, mock_grade
                   FROM mock_entries
                   WHERE class_id = ? AND term_id = ? AND academic_year = ? AND mock_number = ?";
        $ex_stmt = $conn->prepare($ex_sql);
        $ex_stmt->bind_param('iisi', $majesty_id, $current_term_id, $year, $mock);
        $ex_stmt->execute();
        $res = $ex_stmt->get_result();
        $existing = [];
        while ($r = $res->fetch_assoc()) {
            $existing[$r['student_id']][$r['subject_id']] = $r;
        }
        $ex_stmt->close();

        $html = '<div class="overflow-x-auto"><table class="w-full text-white"><thead class="bg-gradient-to-r from-orange-500 to-yellow-400"><tr><th class="px-4 py-3 text-left">Student</th>';
        foreach ($subjects as $s) {
            $is_blocked = in_array($s['subject_name'], $blocked_subjects);
            $html .= '<th class="px-4 py-3 text-left">' . htmlspecialchars($s['subject_name']) . ($is_blocked ? ' <span class="text-xs text-yellow-300">(No Score)</span>' : '') . '</th>';
        }
        $html .= '</tr></thead><tbody class="divide-y divide-gray-300">';

        if (empty($students)) {
            $html .= '<tr><td colspan="' . (count($subjects) + 1) . '" class="px-4 py-8 text-center text-yellow-300 bg-orange-50">No students found for ' . htmlspecialchars($year) . '. Try selecting a different year.</td></tr>';
        } else {
            foreach ($students as $stu) {
                $html .= '<tr class="hover:bg-orange-50 transition"><td class="px-4 py-3 font-medium text-gray-900">' . htmlspecialchars($stu['name']) . '</td>';
                foreach ($subjects as $sub) {
                    $is_blocked = in_array($sub['subject_name'], $blocked_subjects);
                    $e = $existing[$stu['id']][$sub['id']] ?? [];
                    $score = $e['mock_raw'] ?? '';
                    $grade = $e['mock_grade'] ?? '';
                    $entry_id = $e['id'] ?? '';

                    if ($is_blocked) {
                        $display_grade = '-';
                        $display_comment = '-';
                        $input_disabled = 'readonly';
                        $input_class = 'bg-gray-100 text-gray-400 cursor-not-allowed';
                        $grade_color = 'text-gray-500';
                        $comment_color = 'text-gray-400';
                    } else {
                        $display_grade = $grade ?: '-';
                        $display_comment = $grade ? getRemarkFromGrade((int)$grade) : '-';
                        $input_disabled = '';
                        $input_class = 'score-input w-full bg-transparent border-b-2 border-orange-300 focus:border-orange-500 outline-none text-gray-900 text-lg font-medium';
                        $grade_color = 'text-orange-600 font-semibold';
                        $comment_color = 'text-gray-600 italic';
                    }

                    $html .= <<<CELL
                    <td class="px-4 py-3 bg-white">
                        <form class="score-form space-y-1" data-subject="{$sub['id']}">
                            <input type="hidden" name="entry_id" value="$entry_id">
                            <input type="hidden" name="student_id" value="{$stu['id']}">
                            <input type="hidden" name="subject_id" value="{$sub['id']}">
                            <input type="hidden" name="mock_number" value="$mock">
                            <input type="hidden" name="academic_year" value="$year">
                            <input type="number" name="raw_score" value="$score" min="0" max="100" step="0.01"
                                   class="$input_class" $input_disabled placeholder="0-100">
                            <p class="text-sm $grade_color grade-display font-semibold">$display_grade</p>
                            <p class="text-xs $comment_color comment-display italic">$display_comment</p>
                        </form>
                    </td>
                    CELL;
                }
                $html .= '</tr>';
            }
        }
        $html .= '</tbody></table></div>';
        echo json_encode(['success' => true, 'html' => $html]);
        exit;
    }

    /* ---------- SAVE SCORE ---------- */
    if ($_POST['action'] === 'save_score') {
        $subject_id = (int)$_POST['subject_id'];
        $subject_name = '';
        foreach ($subjects as $s) {
            if ($s['id'] == $subject_id) {
                $subject_name = $s['subject_name'];
                break;
            }
        }

        // BLOCK SAVE FOR BLOCKED SUBJECTS
        if (in_array($subject_name, $blocked_subjects)) {
            echo json_encode([
                'success' => true,
                'grade' => '-',
                'comment' => '-',
                'entry_id' => !empty($_POST['entry_id']) ? (int)$_POST['entry_id'] : null
            ]);
            exit;
        }

        $entry_id = !empty($_POST['entry_id']) ? (int)$_POST['entry_id'] : null;
        $student_id = (int)$_POST['student_id'];
        $mock = (int)$_POST['mock_number'];
        $year = $_POST['academic_year'] ?? $selected_year;
        $raw_input = $_POST['raw_score'] ?? '';
        $raw = $raw_input === '' ? null : round((float)$raw_input, 2);
        $numeric_grade = getNumericGrade($raw);
        $remark = $numeric_grade ? getRemarkFromGrade($numeric_grade) : '-';

        $exam_date = date('Y-m-d');

        if ($entry_id) {
            $sql = "UPDATE mock_entries SET
                        mock_raw = ?, mock_max = 100.00, mock_grade = ?, mock_comments = ?,
                        teacher_id = ?, exam_date = ?, updated_at = NOW()
                    WHERE id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param('disisi', $raw, $numeric_grade, $remark, $teacher_id, $exam_date, $entry_id);
        } else {
            $sql = "INSERT INTO mock_entries
                        (student_id, teacher_id, class_id, subject_id, term_id, mock_number,
                         academic_year, exam_date, mock_raw, mock_max, mock_grade, mock_comments)
                    VALUES (?,?,?,?,?,?,?, ?, ?, 100.00, ?, ?)";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param(
                'iiiisissdis',
                $student_id, $teacher_id, $majesty_id, $subject_id, $current_term_id,
                $mock, $year, $exam_date, $raw, $numeric_grade, $remark
            );
        }

        $result = $stmt->execute();
        $new_id = $entry_id ?: $conn->insert_id;

        if (!$result) {
            error_log("SQL Save Error: " . $stmt->error);
            echo json_encode(['success' => false, 'msg' => $stmt->error]);
        } else {
            echo json_encode([
                'success' => true,
                'grade' => $numeric_grade ?? '-',
                'comment' => $remark,
                'entry_id' => $new_id
            ]);
        }
        $stmt->close();
        exit;
    }
    
    /* ---------- DELETE MOCK ---------- */
    if ($_POST['action'] === 'delete_mock') {
        $mock = (int)($_POST['mock_number'] ?? 0);
        $year = $_POST['academic_year'] ?? $selected_year;
        
        $sql = "DELETE FROM mock_entries WHERE class_id = ? AND academic_year = ? AND mock_number = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('isi', $majesty_id, $year, $mock);
        
        if ($stmt->execute()) {
            echo json_encode(['success' => true, 'msg' => 'Mock deleted successfully']);
        } else {
            echo json_encode(['success' => false, 'msg' => $stmt->error]);
        }
        $stmt->close();
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en" class="scroll-smooth">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mock Scores Manager – Majesty Class</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"/>
    <style>
        :root {
            --primary-orange: #f97316;
            --light-orange: #fb923c;
            --bright-orange: #ea580c;
            --cream: #fffbeb;
        }
        body {
            font-family: 'Inter', sans-serif;
        }
        .glass {
            background: rgba(255, 255, 255, 0.9);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 165, 0, 0.2);
            box-shadow: 0 8px 32px rgba(249, 115, 22, 0.1);
        }
        .gradient-bg {
            background: linear-gradient(135deg, #fffbeb 0%, #fed7aa 100%);
        }
        .btn-primary {
            background: linear-gradient(135deg, #f97316 0%, #fb923c 100%);
            color: white;
            transition: all 0.3s ease;
        }
        .btn-primary:hover {
            background: linear-gradient(135deg, #ea580c 0%, #f97316 100%);
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(249, 115, 22, 0.3);
        }
        .score-input:focus {
            box-shadow: 0 0 0 3px rgba(249, 115, 22, 0.2);
        }
        .stat-card {
            background: white;
            border-left: 4px solid #f97316;
            transition: transform 0.3s ease;
        }
        .stat-card:hover {
            transform: translateY(-3px);
        }
        .year-badge {
            background: linear-gradient(135deg, #f97316 0%, #fb923c 100%);
            color: white;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 0.75rem;
            font-weight: 600;
        }
    </style>
</head>
<body class="min-h-screen gradient-bg text-gray-800">
<div id="loader" class="fixed inset-0 bg-black/70 flex items-center justify-center z-50 hidden">
    <div class="bg-white p-6 rounded-2xl shadow-2xl flex flex-col items-center">
        <i class="fas fa-spinner fa-spin text-4xl text-orange-500 mb-4"></i>
        <p class="text-gray-700 font-medium">Processing...</p>
    </div>
</div>
<?php
$header = $_SERVER['DOCUMENT_ROOT'].'/templates/teacher_header.php';
if (file_exists($header)) include $header;
?>
<main class="container mx-auto max-w-7xl p-4 md:p-6">
    <!-- Header -->
    <div class="flex flex-col md:flex-row items-start md:items-center justify-between mb-8 gap-4">
        <div>
            <h1 class="text-3xl md:text-4xl font-bold text-gray-900 flex items-center gap-3">
                <i class="fas fa-chart-line text-orange-500"></i>
                Mock Scores Manager
            </h1>
            <p class="text-gray-600 mt-2 flex items-center gap-2">
                <i class="fas fa-graduation-cap text-orange-400"></i>
                Majesty Class • JHS 3
            </p>
        </div>
        <div class="flex items-center gap-3">
            <span class="year-badge"><?= htmlspecialchars($selected_year) ?></span>
            <button id="themeBtn" class="glass px-4 py-2 rounded-lg font-medium text-gray-700 hover:bg-orange-50 transition">
                <i class="fas fa-palette mr-2"></i>Theme
            </button>
        </div>
    </div>

    <!-- Stats Overview -->
    <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-8">
        <div class="stat-card glass rounded-xl p-4">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm text-gray-500">Total Students</p>
                    <p class="text-2xl font-bold text-gray-900"><?= count($students) ?></p>
                </div>
                <i class="fas fa-users text-3xl text-orange-400"></i>
            </div>
        </div>
        <div class="stat-card glass rounded-xl p-4">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm text-gray-500">Subjects</p>
                    <p class="text-2xl font-bold text-gray-900"><?= count($subjects) - count($blocked_subjects) ?></p>
                </div>
                <i class="fas fa-book-open text-3xl text-orange-400"></i>
            </div>
        </div>
        <div class="stat-card glass rounded-xl p-4">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm text-gray-500">Existing Mocks</p>
                    <p class="text-2xl font-bold text-gray-900"><?= count($existing_mocks) ?></p>
                </div>
                <i class="fas fa-file-alt text-3xl text-orange-400"></i>
            </div>
        </div>
        <div class="stat-card glass rounded-xl p-4">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm text-gray-500">Current Term</p>
                    <p class="text-lg font-bold text-gray-900"><?= htmlspecialchars($current_term['term_name'] ?? 'N/A') ?></p>
                </div>
                <i class="fas fa-calendar-alt text-3xl text-orange-400"></i>
            </div>
        </div>
    </div>

    <!-- Control Panel -->
    <section class="glass rounded-2xl p-6 mb-8">
        <div class="flex flex-col lg:flex-row gap-6">
            <!-- Year Selection -->
            <div class="flex-1">
                <h3 class="text-xl font-semibold mb-4 text-gray-900 flex items-center gap-2">
                    <i class="fas fa-calendar text-orange-500"></i>
                    Select Academic Year
                </h3>
                <div class="flex flex-wrap gap-2">
                    <?php foreach ($available_years as $year): ?>
                        <a href="?year=<?= $year ?>" 
                           class="px-4 py-2 rounded-lg font-medium transition <?= $year == $selected_year ? 'btn-primary' : 'bg-white text-gray-700 hover:bg-orange-50 border border-orange-200' ?>">
                           <?= htmlspecialchars($year) ?>
                           <?php if ($year == $academic_year_for_mock): ?>
                                <span class="text-xs ml-1 opacity-75">(Current)</span>
                           <?php endif; ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
            
            <!-- Mock Selection -->
            <div class="flex-1">
                <h3 class="text-xl font-semibold mb-4 text-gray-900 flex items-center gap-2">
                    <i class="fas fa-edit text-orange-500"></i>
                    Mock Management
                </h3>
                <div class="flex flex-wrap items-end gap-4">
                    <div class="flex-1">
                        <label class="block mb-2 text-gray-700 font-medium">Mock #</label>
                        <div class="flex gap-2">
                            <input type="number" id="mockNum" min="1" value="1" 
                                   class="w-24 bg-white border-2 border-orange-200 text-gray-900 px-3 py-2 rounded-lg focus:border-orange-500 outline-none">
                            <div class="relative">
                                <button id="mockDropdown" class="bg-white border-2 border-orange-200 text-gray-700 px-3 py-2 rounded-lg hover:bg-orange-50 transition">
                                    <i class="fas fa-history"></i>
                                </button>
                                <div id="mockList" class="absolute hidden mt-1 bg-white border border-orange-200 rounded-lg shadow-lg z-10 min-w-[150px]">
                                    <?php foreach ($existing_mocks as $mock_num): ?>
                                        <button type="button" class="mock-option block w-full text-left px-4 py-2 hover:bg-orange-50 text-gray-700" data-mock="<?= $mock_num ?>">
                                            Mock <?= $mock_num ?>
                                        </button>
                                    <?php endforeach; ?>
                                    <?php if (empty($existing_mocks)): ?>
                                        <div class="px-4 py-2 text-gray-500 text-sm">No previous mocks</div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    <button id="loadBtn" class="btn-primary px-6 py-2.5 rounded-lg font-medium transition">
                        <i class="fas fa-search mr-2"></i>Load Mock
                    </button>
                    <button id="deleteMockBtn" class="bg-red-500 hover:bg-red-600 text-white px-4 py-2.5 rounded-lg font-medium transition opacity-50 cursor-not-allowed" disabled>
                        <i class="fas fa-trash mr-2"></i>Delete
                    </button>
                </div>
            </div>
        </div>
        
        <!-- Info Panel -->
        <div class="mt-6 p-4 bg-orange-50 border border-orange-200 rounded-lg">
            <div class="flex items-start gap-3">
                <i class="fas fa-info-circle text-orange-500 mt-1"></i>
                <div>
                    <p class="text-gray-700">
                        <strong>Note:</strong> This page allows you to manage mock exam scores for Majesty Class. 
                        Select an academic year to view previous mocks or create new ones. 
                        Scores for <span class="font-semibold text-orange-600">Music</span> and 
                        <span class="font-semibold text-orange-600">Chinese</span> are blocked as per school policy.
                    </p>
                </div>
            </div>
        </div>
    </section>

    <!-- Scores Section -->
    <section id="scoresSection" class="glass rounded-2xl p-6 mb-8 hidden">
        <div class="flex flex-col md:flex-row justify-between items-start md:items-center mb-6 gap-4">
            <div>
                <h3 class="text-xl font-semibold text-gray-900 flex items-center gap-2">
                    <i class="fas fa-table text-orange-500"></i>
                    <span id="mockTitle">Mock Scores</span>
                </h3>
                <p class="text-gray-600 text-sm" id="mockSubtitle"></p>
            </div>
            <div class="flex gap-3">
                <button id="saveAllBtn" class="btn-primary px-6 py-2.5 rounded-lg font-medium transition">
                    <i class="fas fa-save mr-2"></i>Save All Changes
                </button>
                <button id="exportBtn" class="bg-white border-2 border-orange-300 text-orange-600 px-4 py-2.5 rounded-lg font-medium hover:bg-orange-50 transition">
                    <i class="fas fa-download mr-2"></i>Export
                </button>
            </div>
        </div>
        <div id="scoresTable"></div>
        
        <!-- Summary Stats -->
        <div id="summaryStats" class="mt-6 p-4 bg-gradient-to-r from-orange-50 to-yellow-50 border border-orange-200 rounded-lg hidden">
            <h4 class="font-semibold text-gray-900 mb-3 flex items-center gap-2">
                <i class="fas fa-chart-bar text-orange-500"></i>
                Class Summary
            </h4>
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                <div class="text-center p-3 bg-white rounded-lg border border-orange-100">
                    <p class="text-sm text-gray-500">Average Score</p>
                    <p class="text-xl font-bold text-gray-900">--</p>
                </div>
                <div class="text-center p-3 bg-white rounded-lg border border-orange-100">
                    <p class="text-sm text-gray-500">Highest Score</p>
                    <p class="text-xl font-bold text-gray-900">--</p>
                </div>
                <div class="text-center p-3 bg-white rounded-lg border border-orange-100">
                    <p class="text-sm text-gray-500">Lowest Score</p>
                    <p class="text-xl font-bold text-gray-900">--</p>
                </div>
                <div class="text-center p-3 bg-white rounded-lg border border-orange-100">
                    <p class="text-sm text-gray-500">Completion</p>
                    <p class="text-xl font-bold text-gray-900">--</p>
                </div>
            </div>
        </div>
    </section>
</main>
<?php
$footer = $_SERVER['DOCUMENT_ROOT'].'/templates/teacher_footer.php';
if (file_exists($footer)) include $footer;
?>
<script>
const CSRF = '<?= $csrf_token ?>';
const SELECTED_YEAR = '<?= $selected_year ?>';
const CURRENT_YEAR = '<?= $academic_year_for_mock ?>';
const MAJESTY_ID = <?= $majesty_id ?>;
const loader = document.getElementById('loader');
const BLOCKED_SUBJECTS = ['Music', 'Chinese'];
let currentMock = 1;
let currentYear = SELECTED_YEAR;

function showLoader() { loader.classList.remove('hidden'); }
function hideLoader() { loader.classList.add('hidden'); }

// Theme Toggle
document.getElementById('themeBtn').addEventListener('click', () => {
    document.documentElement.classList.toggle('dark');
    const isDark = document.documentElement.classList.contains('dark');
    localStorage.theme = isDark ? 'dark' : 'light';
    document.getElementById('themeBtn').innerHTML = isDark ? 
        '<i class="fas fa-sun mr-2"></i>Light' : 
        '<i class="fas fa-moon mr-2"></i>Dark';
});
if (localStorage.theme === 'dark') document.documentElement.classList.add('dark');

// Mock dropdown
const mockDropdown = document.getElementById('mockDropdown');
const mockList = document.getElementById('mockList');
const mockNumInput = document.getElementById('mockNum');
const deleteMockBtn = document.getElementById('deleteMockBtn');

mockDropdown.addEventListener('click', (e) => {
    e.stopPropagation();
    mockList.classList.toggle('hidden');
});

document.addEventListener('click', () => {
    mockList.classList.add('hidden');
});

document.querySelectorAll('.mock-option').forEach(option => {
    option.addEventListener('click', (e) => {
        e.stopPropagation();
        const mockNum = e.target.dataset.mock;
        mockNumInput.value = mockNum;
        mockList.classList.add('hidden');
        updateDeleteButton();
    });
});

// Update delete button state
function updateDeleteButton() {
    const mockNum = parseInt(mockNumInput.value);
    const isCurrentYear = currentYear === CURRENT_YEAR;
    deleteMockBtn.disabled = !isCurrentYear;
    deleteMockBtn.classList.toggle('opacity-50', !isCurrentYear);
    deleteMockBtn.classList.toggle('cursor-not-allowed', !isCurrentYear);
    deleteMockBtn.classList.toggle('cursor-pointer', isCurrentYear);
}

// Load Mock
document.getElementById('loadBtn').addEventListener('click', async () => {
    const mock = mockNumInput.value;
    currentMock = mock;
    
    showLoader();
    const fd = new FormData();
    fd.append('action', 'load_mock');
    fd.append('mock_number', mock);
    fd.append('academic_year', currentYear);
    fd.append('csrf_token', CSRF);
    
    try {
        const res = await fetch('', { method: 'POST', body: fd });
        const json = await res.json();
        if (json.success) {
            document.getElementById('scoresTable').innerHTML = json.html;
            document.getElementById('scoresSection').classList.remove('hidden');
            document.getElementById('mockTitle').textContent = `Mock ${mock} Scores`;
            document.getElementById('mockSubtitle').textContent = `Academic Year: ${currentYear} • Total Students: ${document.querySelectorAll('.score-form').length / <?= count($subjects) ?>}`;
            
            bindEvents();
            updateSummaryStats();
            updateDeleteButton();
        } else {
            alert('Load failed: ' + (json.msg || 'Unknown error'));
        }
    } catch (err) {
        console.error(err);
        alert('Network error. Please check your connection.');
    } finally {
        hideLoader();
    }
});

// Delete Mock
deleteMockBtn.addEventListener('click', async () => {
    if (!confirm(`Are you sure you want to delete Mock ${currentMock} for ${currentYear}? This action cannot be undone.`)) {
        return;
    }
    
    showLoader();
    const fd = new FormData();
    fd.append('action', 'delete_mock');
    fd.append('mock_number', currentMock);
    fd.append('academic_year', currentYear);
    fd.append('csrf_token', CSRF);
    
    try {
        const res = await fetch('', { method: 'POST', body: fd });
        const json = await res.json();
        if (json.success) {
            alert('Mock deleted successfully.');
            mockNumInput.value = 1;
            document.getElementById('scoresSection').classList.add('hidden');
        } else {
            alert('Delete failed: ' + (json.msg || 'Unknown error'));
        }
    } catch (err) {
        console.error(err);
        alert('Network error');
    } finally {
        hideLoader();
    }
});

function bindEvents() {
    document.querySelectorAll('.score-input').forEach(inp => {
        // Auto-save on blur
        inp.addEventListener('blur', async e => {
            await saveSingle(e.target.closest('.score-form'));
        });
        
        // Enter key to save
        inp.addEventListener('keydown', async e => {
            if (e.key === 'Enter') {
                e.preventDefault();
                await saveSingle(e.target.closest('.score-form'));
                // Move to next input
                const form = e.target.closest('td').nextElementSibling?.querySelector('.score-input');
                if (form) form.focus();
            }
        });
        
        // Real-time grade calculation
        inp.addEventListener('input', e => {
            const form = e.target.closest('.score-form');
            const subjectName = form.closest('td').previousElementSibling?.textContent.trim() || '';
            if (BLOCKED_SUBJECTS.some(s => subjectName.includes(s))) return;

            const score = e.target.value === '' ? null : parseFloat(e.target.value);
            const grade = getNumericGrade(score);
            const remark = grade ? getRemark(grade) : '-';
            form.querySelector('.grade-display').textContent = grade ?? '-';
            form.querySelector('.grade-display').className = `text-sm ${grade ? 'text-orange-600 font-semibold' : 'text-gray-500'} grade-display font-semibold`;
            form.querySelector('.comment-display').textContent = remark;
        });
    });
}

function getNumericGrade(s) {
    if (s === null || isNaN(s)) return null;
    if (s >= 95) return 1;
    if (s >= 85) return 2;
    if (s >= 75) return 3;
    if (s >= 65) return 4;
    if (s >= 55) return 5;
    if (s >= 45) return 6;
    if (s >= 36) return 7;
    if (s >= 31) return 8;
    return 9;
}

function getRemark(g) {
    const map = {
        1: 'Highest', 2: 'Higher', 3: 'High', 4: 'High Average',
        5: 'Average', 6: 'Low Average', 7: 'Low', 8: 'Lower', 9: 'Lowest'
    };
    return map[g] || '-';
}

async function saveSingle(form) {
    const subjectName = form.closest('td').previousElementSibling?.textContent.trim() || '';
    if (BLOCKED_SUBJECTS.some(s => subjectName.includes(s))) return;

    const rawInput = form.querySelector('[name="raw_score"]').value;
    if (rawInput === '' || rawInput === null) return; // Don't save empty scores
    
    const fd = new FormData(form);
    fd.append('action', 'save_score');
    fd.append('csrf_token', CSRF);
    fd.append('academic_year', currentYear);
    
    showLoader();
    try {
        const r = await fetch('', { method: 'POST', body: fd });
        const j = await r.json();
        if (j.success) {
            form.querySelector('.grade-display').textContent = j.grade;
            form.querySelector('.comment-display').textContent = j.comment;
            if (j.entry_id) {
                form.querySelector('[name="entry_id"]').value = j.entry_id;
            }
            // Visual feedback
            form.classList.add('bg-green-50');
            setTimeout(() => form.classList.remove('bg-green-50'), 1000);
            updateSummaryStats();
        } else {
            alert('Save failed: ' + (j.msg || ''));
        }
    } catch (err) {
        alert('Network error while saving');
    } finally {
        hideLoader();
    }
}

// Save All
document.getElementById('saveAllBtn').addEventListener('click', async () => {
    const forms = Array.from(document.querySelectorAll('.score-form'));
    if (!forms.length) return;
    
    const nonBlockedForms = forms.filter(form => {
        const subjectName = form.closest('td').previousElementSibling?.textContent.trim() || '';
        return !BLOCKED_SUBJECTS.some(s => subjectName.includes(s));
    });
    
    if (!nonBlockedForms.length) return;
    
    showLoader();
    let success = 0, failed = 0;
    
    for (const form of nonBlockedForms) {
        const rawInput = form.querySelector('[name="raw_score"]').value;
        if (rawInput === '') continue; // Skip empty scores
        
        const fd = new FormData(form);
        fd.append('action', 'save_score');
        fd.append('csrf_token', CSRF);
        fd.append('academic_year', currentYear);
        
        try {
            const r = await fetch('', { method: 'POST', body: fd });
            const j = await r.json();
            if (j.success) {
                success++;
                if (j.entry_id) form.querySelector('[name="entry_id"]').value = j.entry_id;
            } else {
                failed++;
            }
        } catch (err) { 
            failed++; 
        }
    }
    
    hideLoader();
    
    if (success > 0) {
        // Visual feedback for all saved forms
        nonBlockedForms.forEach(form => {
            form.classList.add('bg-green-50');
            setTimeout(() => form.classList.remove('bg-green-50'), 2000);
        });
    }
    
    alert(`Saved: ${success} scores | Failed: ${failed}`);
    updateSummaryStats();
});

// Export button
document.getElementById('exportBtn').addEventListener('click', () => {
    alert('Export functionality would be implemented here. This could generate a CSV or PDF report.');
});

// Update summary statistics
function updateSummaryStats() {
    const inputs = document.querySelectorAll('.score-input:not([readonly])');
    let total = 0;
    let count = 0;
    let highest = 0;
    let lowest = 100;
    
    inputs.forEach(input => {
        const val = parseFloat(input.value);
        if (!isNaN(val)) {
            total += val;
            count++;
            highest = Math.max(highest, val);
            lowest = Math.min(lowest, val);
        }
    });
    
    const average = count > 0 ? (total / count).toFixed(2) : '--';
    const completion = inputs.length > 0 ? Math.round((count / inputs.length) * 100) + '%' : '--';
    
    const statsDiv = document.getElementById('summaryStats');
    if (count > 0) {
        statsDiv.classList.remove('hidden');
        statsDiv.querySelectorAll('.text-xl')[0].textContent = average;
        statsDiv.querySelectorAll('.text-xl')[1].textContent = highest.toFixed(1);
        statsDiv.querySelectorAll('.text-xl')[2].textContent = lowest.toFixed(1);
        statsDiv.querySelectorAll('.text-xl')[3].textContent = completion;
    } else {
        statsDiv.classList.add('hidden');
    }
}

// Initialize
updateDeleteButton();
</script>
</body>
</html>
<?php
mysqli_close($conn);
ob_end_flush();