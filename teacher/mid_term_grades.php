<?php
// mid_term_grades.php - Simplified Version for QPMS (No Timetable Restriction)

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();

// Restrict to teachers only
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'teacher') {
    $_SESSION['error_msg'] = "Access denied. Teachers only.";
    header("Location: /login.php");
    exit();
}

$db_path = $_SERVER['DOCUMENT_ROOT'] . '/config/db.php';
if (!file_exists($db_path)) {
    error_log("DB config missing: $db_path");
    $_SESSION['error_msg'] = "Server configuration error.";
    header("Location: /login.php");
    exit();
}
require_once $db_path;

if (!isset($conn) || !$conn->ping()) {
    error_log("Database connection failed");
    $_SESSION['error_msg'] = "Database connection failed.";
    header("Location: /login.php");
    exit();
}

$teacher_id = $_SESSION['user_id'];
$teacher_name = $_SESSION['name'] ?? 'Teacher';

// Validate parameters
$student_id = isset($_GET['student_id']) ? (int)$_GET['student_id'] : 0;
$class_id = isset($_GET['class_id']) ? (int)$_GET['class_id'] : 0;
$term_id = isset($_GET['term_id']) ? (int)$_GET['term_id'] : 0;

if (!$student_id || !$class_id || !$term_id) {
    $_SESSION['error_msg'] = "Invalid access parameters.";
    header("Location: submit_grades.php");
    exit();
}

// ---------------------------------------------------------------------------
// "Back to Students" should return the teacher to the exact list view they
// came from (same class filter, search term, page and sort) instead of a
// reset list. submit_grades.php passes this along as a `back` query string;
// we fall back to just the class filter if it's missing (e.g. bookmarked link).
// PHP already URL-decodes $_GET values, so $back_qs is the raw "k=v&k2=v2" string.
// It is only ever used to build a query string on our own submit_grades.php
// link (never a full URL/host), so it carries no open-redirect risk - we just
// need to escape it for safe HTML output below.
// ---------------------------------------------------------------------------
$back_qs = isset($_GET['back']) ? trim($_GET['back']) : '';
$back_url = $back_qs !== '' ? ('submit_grades.php?' . $back_qs) : ('submit_grades.php?class_id=' . $class_id);

// Verify the term exists and get its details
$stmt = $conn->prepare("SELECT id, term_name, year FROM academic_calendar WHERE id = ?");
$stmt->bind_param("i", $term_id);
$stmt->execute();
$term_result = $stmt->get_result();
$term_data = $term_result->fetch_assoc();
$stmt->close();

if (!$term_data) {
    $_SESSION['error_msg'] = "Invalid term selected.";
    header("Location: submit_grades.php");
    exit();
}

$term_display = $term_data['term_name'] . ' ' . $term_data['year'];

// Fetch student, class, and department details
$stmt = $conn->prepare("
    SELECT 
        u.name AS student_name, 
        u.email, 
        c.class_name, 
        d.id AS department_id,
        d.name AS dept_name
    FROM users u
    JOIN student_classes sc ON u.id = sc.user_id
    JOIN classes c ON sc.class_id = c.id
    JOIN departments d ON c.department_id = d.id
    WHERE u.id = ? AND c.id = ? AND u.role = 'student'
");
$stmt->bind_param("ii", $student_id, $class_id);
$stmt->execute();
$result = $stmt->get_result();
$info = $result->fetch_assoc();
$stmt->close();

if (!$info) {
    $_SESSION['error_msg'] = "Student or class not found.";
    header("Location: submit_grades.php");
    exit();
}

$student_name = $info['student_name'] ?: $info['email'];
$class_name = $info['class_name'];
$dept_name = $info['dept_name'];
$department_id = $info['department_id'];

$initials = '';
foreach (array_slice(preg_split('/\s+/', trim($student_name)), 0, 2) as $p) {
    $initials .= mb_strtoupper(mb_substr($p, 0, 1));
}

// Fetch ALL active subjects for the student's department
$subjects = [];
$stmt = $conn->prepare("
    SELECT id, subject_name, subject_code
    FROM subjects
    WHERE department_id = ? AND status = 'Active'
    ORDER BY subject_name
");
$stmt->bind_param("i", $department_id);
$stmt->execute();
$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    $subjects[] = $row;
}
$stmt->close();

if (empty($subjects)) {
    $_SESSION['error_msg'] = "No active subjects found for this department. Contact admin.";
    header("Location: submit_grades.php");
    exit();
}

// Letter grade function
function getGradeInfo($score) {
    if (!is_numeric($score) || $score === '' || $score <= 0) return ['letter' => 'N/A', 'comment' => 'Pending', 'color' => 'gray'];
    $score = (float)$score;
    if ($score >= 90) return ['letter' => 'A+', 'comment' => 'Outstanding', 'color' => 'emerald'];
    if ($score >= 80) return ['letter' => 'A', 'comment' => 'Excellent', 'color' => 'emerald'];
    if ($score >= 70) return ['letter' => 'B', 'comment' => 'Good', 'color' => 'blue'];
    if ($score >= 60) return ['letter' => 'C', 'comment' => 'Satisfactory', 'color' => 'yellow'];
    if ($score >= 50) return ['letter' => 'D', 'comment' => 'Pass', 'color' => 'orange'];
    return ['letter' => 'F', 'comment' => 'Fail', 'color' => 'red'];
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['grades'])) {
    $conn->autocommit(false);
    $success = true;
    $updated_count = 0;

    foreach ($_POST['grades'] as $subject_id => $score) {
        $score = trim($score);
        if ($score === '') continue;

        $score = floatval($score);
        if ($score < 0 || $score > 100) {
            $_SESSION['error_msg'] = "Score must be between 0 and 100.";
            $success = false;
            break;
        }

        $stmt = $conn->prepare("
            INSERT INTO grades (user_id, class_id, subject_id, term_id, mid_term_exam, updated_at)
            VALUES (?, ?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE 
                mid_term_exam = VALUES(mid_term_exam), 
                updated_at = NOW()
        ");
        $stmt->bind_param("iiiid", $student_id, $class_id, $subject_id, $term_id, $score);
        if ($stmt->execute()) $updated_count++;
        else $success = false;
        $stmt->close();
    }

    if ($success && $updated_count > 0) {
        $conn->commit();
        $_SESSION['success_msg'] = "Mid-term grades saved successfully! ($updated_count subject(s) updated)";
    } elseif ($success && $updated_count === 0) {
        $_SESSION['info_msg'] = "No changes – nothing saved.";
    } else {
        $conn->rollback();
        $_SESSION['error_msg'] = $_SESSION['error_msg'] ?? "Failed to save grades.";
    }

    $conn->autocommit(true);
    $redirect_qs = "student_id=$student_id&class_id=$class_id&term_id=$term_id";
    if ($back_qs !== '') {
        $redirect_qs .= '&back=' . urlencode($back_qs);
    }
    header("Location: " . $_SERVER['PHP_SELF'] . "?" . $redirect_qs);
    exit();
}

// Fetch existing mid-term grades for THIS SPECIFIC TERM
$existing = [];
$stmt = $conn->prepare("
    SELECT subject_id, mid_term_exam 
    FROM grades 
    WHERE user_id = ? AND class_id = ? AND term_id = ?
");
$stmt->bind_param("iii", $student_id, $class_id, $term_id);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $existing[$row['subject_id']] = (float)$row['mid_term_exam'];
}
$stmt->close();

// Progress: how many of this student's subjects already have a score
$filled_count = 0;
foreach ($subjects as $subject) {
    if (!empty($existing[$subject['id']])) $filled_count++;
}
$total_subjects = count($subjects);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mid-Term Grades • QPMS</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        primary: '#ea580c',
                        'primary-dark': '#c2410c',
                        'primary-light': '#fff7ed',
                    }
                }
            }
        }
    </script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; }
        .grade-input:focus { outline: none; box-shadow: 0 0 0 4px #ea580c33; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(4px); } to { opacity: 1; transform: translateY(0); } }
        .fade-in { animation: fadeIn 0.2s ease-out; }
        :focus-visible { outline: 2px solid #ea580c; outline-offset: 2px; }
    </style>
</head>
<body class="bg-gray-50 min-h-screen flex flex-col">
    <!-- Header -->
    <header class="bg-white shadow-sm border-b border-gray-200 sticky top-0 z-20">
        <div class="max-w-5xl mx-auto px-4 sm:px-6 py-3.5 flex justify-between items-center gap-4">
            <div class="flex items-center gap-3 min-w-0">
                <div class="w-9 h-9 rounded-lg bg-primary text-white flex items-center justify-center font-bold text-sm shrink-0">QP</div>
                <div class="min-w-0 hidden sm:block">
                    <div class="text-sm font-semibold text-gray-800 leading-tight">QPMS</div>
                    <div class="text-xs text-gray-400 leading-tight truncate">Mid-Term Grade Entry</div>
                </div>
            </div>
            <a href="<?= htmlspecialchars($back_url) ?>"
               class="text-gray-500 hover:text-primary transition flex items-center gap-2 text-sm font-medium shrink-0">
                <i class="fas fa-arrow-left"></i> <span class="hidden sm:inline">Back to Students</span>
            </a>
        </div>
    </header>

    <main class="flex-grow max-w-5xl mx-auto w-full px-4 sm:px-6 py-6 sm:py-10">

        <!-- Breadcrumb -->
        <nav class="flex items-center gap-2 text-sm text-gray-400 mb-4 flex-wrap" aria-label="Breadcrumb">
            <a href="<?= htmlspecialchars($back_url) ?>" class="hover:text-primary transition">Students</a>
            <i class="fas fa-chevron-right text-[10px]"></i>
            <span class="text-gray-600 font-medium"><?= htmlspecialchars($class_name) ?></span>
            <i class="fas fa-chevron-right text-[10px]"></i>
            <span class="text-gray-600 font-medium"><?= htmlspecialchars($student_name) ?></span>
        </nav>

        <!-- Student header card -->
        <div class="bg-white rounded-2xl border border-gray-200 p-5 sm:p-6 mb-5 flex flex-col sm:flex-row sm:items-center gap-4 sm:gap-6">
            <div class="flex items-center gap-4 flex-1 min-w-0">
                <div class="w-14 h-14 rounded-full bg-primary-light text-primary font-bold flex items-center justify-center text-lg shrink-0">
                    <?= htmlspecialchars($initials ?: '?') ?>
                </div>
                <div class="min-w-0">
                    <h1 class="text-lg sm:text-xl font-bold text-gray-900 truncate"><?= htmlspecialchars($student_name) ?></h1>
                    <p class="text-sm text-gray-500 truncate"><?= htmlspecialchars($class_name) ?> &middot; <?= htmlspecialchars($dept_name) ?></p>
                </div>
            </div>
            <div class="flex items-center gap-4 sm:gap-6 shrink-0">
                <div>
                    <div class="text-xs text-gray-400 uppercase tracking-wide font-semibold">Term</div>
                    <div class="text-sm font-semibold text-gray-800"><?= htmlspecialchars($term_display) ?></div>
                </div>
                <div>
                    <div class="text-xs text-gray-400 uppercase tracking-wide font-semibold">Progress</div>
                    <div class="text-sm font-semibold text-gray-800"><?= $filled_count ?> / <?= $total_subjects ?> subjects</div>
                </div>
            </div>
        </div>

        <!-- Messages -->
        <?php if (isset($_SESSION['error_msg'])): ?>
            <div class="mb-5 p-4 bg-red-50 border border-red-200 text-red-700 rounded-xl flex items-center gap-3 fade-in">
                <i class="fas fa-exclamation-triangle"></i>
                <?= htmlspecialchars($_SESSION['error_msg']); unset($_SESSION['error_msg']); ?>
            </div>
        <?php endif; ?>
        <?php if (isset($_SESSION['success_msg'])): ?>
            <div class="mb-5 p-4 bg-green-50 border border-green-200 text-green-700 rounded-xl flex items-center gap-3 fade-in">
                <i class="fas fa-check-circle"></i>
                <?= htmlspecialchars($_SESSION['success_msg']); unset($_SESSION['success_msg']); ?>
            </div>
        <?php endif; ?>
        <?php if (isset($_SESSION['info_msg'])): ?>
            <div class="mb-5 p-4 bg-blue-50 border border-blue-200 text-blue-700 rounded-xl flex items-center gap-3 fade-in">
                <i class="fas fa-info-circle"></i>
                <?= htmlspecialchars($_SESSION['info_msg']); unset($_SESSION['info_msg']); ?>
            </div>
        <?php endif; ?>

        <!-- Grade Entry Form -->
        <form method="POST" class="mb-8">
            <div class="bg-white rounded-2xl border border-gray-200 p-5 sm:p-6">
                <h2 class="text-base font-bold text-gray-800 mb-5 flex items-center gap-2.5">
                    <i class="fas fa-pen-to-square text-primary"></i> Enter Mid-Term Scores
                    <span class="text-xs font-normal text-gray-400">(0–100)</span>
                </h2>
                <div class="grid gap-3">
                    <?php foreach ($subjects as $subject):
                        $sid = $subject['id'];
                        $current = $existing[$sid] ?? '';
                        $gradeInfo = getGradeInfo($current);
                    ?>
                        <div class="flex items-center justify-between gap-4 bg-gray-50 rounded-xl p-4 border border-gray-100">
                            <div class="min-w-0 flex-1">
                                <h3 class="font-semibold text-gray-800 truncate"><?= htmlspecialchars($subject['subject_name']) ?></h3>
                                <p class="text-xs text-gray-500">Code: <?= htmlspecialchars($subject['subject_code']) ?></p>
                            </div>
                            <div class="flex items-center gap-4 sm:gap-6 shrink-0">
                                <input type="number" min="0" max="100" step="0.1" name="grades[<?= $sid ?>]"
                                       value="<?= $current !== '' ? htmlspecialchars($current) : '' ?>"
                                       placeholder="—"
                                       class="w-20 sm:w-24 px-3 py-2.5 text-base font-semibold text-center border border-gray-300 rounded-lg bg-white focus:ring-4 focus:ring-primary/20 focus:border-primary transition grade-input"
                                       onchange="updatePreview(this, 'preview-<?= $sid ?>')">
                                <div class="text-right w-24 shrink-0">
                                    <div id="preview-<?= $sid ?>" class="text-xl font-bold text-<?= $gradeInfo['color'] ?>-600 leading-tight">
                                        <?= $gradeInfo['letter'] ?>
                                    </div>
                                    <div class="text-xs text-gray-500 leading-tight"><?= $gradeInfo['comment'] ?></div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div class="mt-6 flex flex-col sm:flex-row items-center justify-between gap-4 pt-5 border-t border-gray-100">
                    <p class="text-sm text-gray-400 order-2 sm:order-1">Leave a score blank to skip it — only filled scores are saved.</p>
                    <button type="submit"
                            class="order-1 sm:order-2 w-full sm:w-auto bg-primary text-white font-semibold py-3 px-8 rounded-xl hover:bg-primary-dark transition flex items-center justify-center gap-2">
                        <i class="fas fa-save"></i> Save Mid-Term Grades
                    </button>
                </div>
            </div>
        </form>

        <!-- Entered Grades Summary -->
        <?php
        $entered_subjects = [];
        foreach ($subjects as $subject) {
            $sid = $subject['id'];
            if (!empty($existing[$sid])) {
                $score = $existing[$sid];
                $g = getGradeInfo($score);
                $entered_subjects[] = [
                    'name' => $subject['subject_name'],
                    'code' => $subject['subject_code'],
                    'score' => $score,
                    'letter' => $g['letter'],
                    'comment' => $g['comment'],
                    'color' => $g['color']
                ];
            }
        }

        $total = 0; $count = count($entered_subjects);
        foreach ($entered_subjects as $es) {
            $total += $es['score'];
        }
        $average = $count > 0 ? round($total / $count, 2) : 0;
        $avgGrade = getGradeInfo($average);
        ?>

        <div>
            <h2 class="text-base font-bold text-gray-800 mb-4 flex items-center gap-2.5">
                <i class="fas fa-list-check text-primary"></i> Entered Grades Summary
                <span class="text-xs font-normal text-gray-400">(<?= htmlspecialchars($term_display) ?>)</span>
            </h2>

            <?php if ($count > 0): ?>
                <div class="grid sm:grid-cols-3 gap-5">
                    <div class="sm:col-span-2 overflow-x-auto rounded-xl border border-gray-200 bg-white">
                        <table class="w-full border-collapse">
                            <thead>
                                <tr class="bg-gray-50 text-left text-xs uppercase tracking-wide text-gray-500">
                                    <th class="px-5 py-3">Subject</th>
                                    <th class="px-5 py-3 text-center">Score</th>
                                    <th class="px-5 py-3 text-center">Grade</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                <?php foreach ($entered_subjects as $es): ?>
                                    <tr class="hover:bg-gray-50/80 transition">
                                        <td class="px-5 py-3">
                                            <div class="font-medium text-gray-800"><?= htmlspecialchars($es['name']) ?></div>
                                            <div class="text-xs text-gray-400"><?= htmlspecialchars($es['code']) ?></div>
                                        </td>
                                        <td class="px-5 py-3 text-center font-semibold text-gray-800"><?= $es['score'] ?>%</td>
                                        <td class="px-5 py-3 text-center">
                                            <span class="inline-flex items-center gap-1.5 text-<?= $es['color'] ?>-700 bg-<?= $es['color'] ?>-50 px-2.5 py-1 rounded-full text-xs font-bold">
                                                <?= $es['letter'] ?>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="bg-primary-light rounded-2xl p-6 text-center flex flex-col items-center justify-center">
                        <div class="text-xs font-semibold uppercase tracking-wide text-primary/70 mb-1">Mid-Term Average</div>
                        <div class="text-5xl font-extrabold text-primary leading-none"><?= $average ?>%</div>
                        <div class="text-lg font-bold text-<?= $avgGrade['color'] ?>-600 mt-3"><?= $avgGrade['letter'] ?></div>
                        <p class="text-sm text-gray-600 mt-1"><?= $avgGrade['comment'] ?> Performance</p>
                        <p class="text-xs text-gray-400 mt-3">Based on <?= $count ?> of <?= $total_subjects ?> subject(s)</p>
                    </div>
                </div>
            <?php else: ?>
                <div class="bg-white rounded-2xl border border-gray-200 text-center py-14 text-gray-500">
                    <div class="w-14 h-14 mx-auto mb-3 rounded-2xl bg-gray-100 flex items-center justify-center">
                        <i class="fas fa-inbox text-xl text-gray-400"></i>
                    </div>
                    <p class="font-medium text-gray-700">No mid-term scores entered yet</p>
                    <p class="text-sm mt-1">Enter scores above and save to see them here.</p>
                </div>
            <?php endif; ?>
        </div>
    </main>

    <footer class="text-gray-400 py-8 mt-auto">
        <div class="max-w-5xl mx-auto px-6 text-center text-sm">
            <p>&copy; <?= date('Y') ?> QPMS &middot; Quality Progress Management System</p>
        </div>
    </footer>

    <script>
        function updatePreview(input, previewId) {
            const score = parseFloat(input.value);
            let letter = 'N/A', comment = 'Pending', color = 'gray';
            if (isNaN(score) || score <= 0) {
                letter = 'N/A'; comment = 'Pending';
            } else if (score >= 90) { letter = 'A+'; comment = 'Outstanding'; color = 'emerald'; }
            else if (score >= 80) { letter = 'A'; comment = 'Excellent'; color = 'emerald'; }
            else if (score >= 70) { letter = 'B'; comment = 'Good'; color = 'blue'; }
            else if (score >= 60) { letter = 'C'; comment = 'Satisfactory'; color = 'yellow'; }
            else if (score >= 50) { letter = 'D'; comment = 'Pass'; color = 'orange'; }
            else { letter = 'F'; comment = 'Fail'; color = 'red'; }

            const preview = document.getElementById(previewId);
            preview.textContent = letter;
            preview.className = `text-xl font-bold text-${color}-600 leading-tight`;
            preview.nextElementSibling.textContent = comment;
        }

        document.querySelectorAll('.grade-input').forEach(input => {
            if (input.value) {
                const match = input.name.match(/\[(\d+)\]/);
                if (match) updatePreview(input, 'preview-' + match[1]);
            }
        });
    </script>
</body>
</html>
<?php $conn->close(); ?>