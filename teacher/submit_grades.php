<?php
// submit_grades.php - Teacher grade portal: live search, pagination, class filter, current-term detection
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

$teacher_id = (int)$_SESSION['user_id'];
$session_term_key = "current_term_$teacher_id";

// CSRF token for the term-selection form (the only state-changing POST on this page)
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// ---------------------------------------------------------------------------
// Handle manual term selection (POST, mutates session -> full page reload)
// ---------------------------------------------------------------------------
if (isset($_POST['term_id']) && !empty($_POST['term_id'])) {
    if (!hash_equals($csrf_token, $_POST['csrf_token'] ?? '')) {
        $_SESSION['error_msg'] = "Your session expired. Please try again.";
        header("Location: submit_grades.php");
        exit();
    }
    $selected_term_id_post = (int)$_POST['term_id'];
    $stmt = $conn->prepare("SELECT id, term_name, year, start_date, end_date FROM academic_calendar WHERE id = ?");
    $stmt->bind_param("i", $selected_term_id_post);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        $_SESSION[$session_term_key] = $row;
    }
    $stmt->close();
    // Redirect so a page refresh doesn't re-POST the term change
    header("Location: submit_grades.php");
    exit();
}

// Load current active term if not in session
if (!isset($_SESSION[$session_term_key])) {
    $today = date('Y-m-d');
    $stmt = $conn->prepare("
        SELECT id, term_name, year, start_date, end_date
        FROM academic_calendar
        WHERE start_date <= ? AND end_date >= ?
        ORDER BY start_date DESC
        LIMIT 1
    ");
    $stmt->bind_param("ss", $today, $today);
    $stmt->execute();
    $result = $stmt->get_result();
    $_SESSION[$session_term_key] = $result->fetch_assoc() ?: null;
    $stmt->close();
}

$current_term = $_SESSION[$session_term_key];
$selected_term_id = $current_term['id'] ?? 0;

// ---------------------------------------------------------------------------
// Filters - all read-only (GET), used identically for the normal page load
// and for the AJAX fragment requests fired by the search/filter/pagination JS.
// ---------------------------------------------------------------------------
$search_query = isset($_GET['search']) ? trim($_GET['search']) : '';
$selected_class_id = isset($_GET['class_id']) ? (int)$_GET['class_id'] : 0;
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$per_page = 10;

$sort = $_GET['sort'] ?? 'class_asc';
$sort_map = [
    'class_asc'  => 'c.class_name ASC, u.name ASC',
    'class_desc' => 'c.class_name DESC, u.name ASC',
    'name_asc'   => 'u.name ASC',
    'name_desc'  => 'u.name DESC',
];
$order_by = $sort_map[$sort] ?? $sort_map['class_asc'];

// Fetch all terms for the term dropdown
$terms_result = $conn->query("SELECT id, term_name, year FROM academic_calendar ORDER BY year DESC, start_date DESC");

// Classes with students enrolled this term (visible to any teacher).
$classes_list = [];
$term_year = '';
$term_total_students = 0; // unfiltered headcount for this term, used in the stats strip
if ($selected_term_id > 0) {
    $year_stmt = $conn->prepare("SELECT year FROM academic_calendar WHERE id = ?");
    $year_stmt->bind_param("i", $selected_term_id);
    $year_stmt->execute();
    $term_year = $year_stmt->get_result()->fetch_assoc()['year'] ?? '';
    $year_stmt->close();

    $class_stmt = $conn->prepare("
        SELECT DISTINCT c.id, c.class_name
        FROM classes c
        INNER JOIN student_classes sc ON c.id = sc.class_id
        INNER JOIN users u ON sc.user_id = u.id AND u.role = 'student'
        WHERE sc.academic_year = ?
        ORDER BY c.class_name ASC
    ");
    $class_stmt->bind_param("s", $term_year);
    $class_stmt->execute();
    $class_result = $class_stmt->get_result();
    while ($row = $class_result->fetch_assoc()) {
        $classes_list[$row['id']] = $row['class_name'];
    }
    $class_stmt->close();

    // Read-only headcount for the stats strip (no filters applied)
    $total_stmt = $conn->prepare("
        SELECT COUNT(DISTINCT u.id) AS total
        FROM classes c
        INNER JOIN student_classes sc ON c.id = sc.class_id
        INNER JOIN users u ON sc.user_id = u.id AND u.role = 'student'
        WHERE sc.academic_year = ?
    ");
    $total_stmt->bind_param("s", $term_year);
    $total_stmt->execute();
    $term_total_students = (int)($total_stmt->get_result()->fetch_assoc()['total'] ?? 0);
    $total_stmt->close();
}

/**
 * Builds the shared WHERE clause / params for the student list, so the
 * COUNT query and the paginated SELECT never drift out of sync.
 */
function build_student_filter($term_year, $class_id, $search) {
    $where = " WHERE sc.academic_year = ? ";
    $params = [$term_year];
    $types = 's';

    if ($class_id > 0) {
        $where .= " AND c.id = ? ";
        $params[] = $class_id;
        $types .= 'i';
    }
    if (!empty($search)) {
        $where .= " AND (u.name LIKE ? OR u.email LIKE ?) ";
        $like = '%' . $search . '%';
        $params[] = $like;
        $params[] = $like;
        $types .= 'ss';
    }
    return [$where, $params, $types];
}

$students = [];
$total_students = 0;
$total_pages = 1;

if ($selected_term_id > 0) {
    [$where, $params, $types] = build_student_filter($term_year, $selected_class_id, $search_query);

    // Count for pagination
    $count_sql = "
        SELECT COUNT(*) AS total
        FROM classes c
        INNER JOIN student_classes sc ON c.id = sc.class_id
        INNER JOIN users u ON sc.user_id = u.id AND u.role = 'student'
        $where
    ";
    $count_stmt = $conn->prepare($count_sql);
    $count_stmt->bind_param($types, ...$params);
    $count_stmt->execute();
    $total_students = (int)($count_stmt->get_result()->fetch_assoc()['total'] ?? 0);
    $count_stmt->close();

    $total_pages = max(1, (int)ceil($total_students / $per_page));
    $page = min($page, $total_pages);
    $offset = ($page - 1) * $per_page;

    $sql = "
        SELECT c.id AS class_id, c.class_name, u.id AS student_id, u.name, u.email
        FROM classes c
        INNER JOIN student_classes sc ON c.id = sc.class_id
        INNER JOIN users u ON sc.user_id = u.id AND u.role = 'student'
        $where
        ORDER BY $order_by
        LIMIT ? OFFSET ?
    ";
    $stmt = $conn->prepare($sql);
    $params_with_limit = array_merge($params, [$per_page, $offset]);
    $types_with_limit = $types . 'ii';
    $stmt->bind_param($types_with_limit, ...$params_with_limit);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $students[] = $row;
    }
    $stmt->close();
}

// A small deterministic palette so each class badge/avatar gets a consistent,
// distinguishable color without needing a color column in the DB.
function class_color_classes($class_name) {
    $palette = [
        ['bg' => 'bg-orange-100', 'text' => 'text-orange-700'],
        ['bg' => 'bg-blue-100',   'text' => 'text-blue-700'],
        ['bg' => 'bg-emerald-100','text' => 'text-emerald-700'],
        ['bg' => 'bg-purple-100', 'text' => 'text-purple-700'],
        ['bg' => 'bg-rose-100',   'text' => 'text-rose-700'],
        ['bg' => 'bg-amber-100',  'text' => 'text-amber-700'],
        ['bg' => 'bg-cyan-100',   'text' => 'text-cyan-700'],
        ['bg' => 'bg-indigo-100', 'text' => 'text-indigo-700'],
    ];
    $idx = crc32($class_name) % count($palette);
    return $palette[$idx];
}

/**
 * Renders the student table + pagination as an HTML fragment. Used both
 * for the initial page load and for AJAX refreshes, so markup never drifts.
 * Renders a dual layout: a real <table> for md+ screens and stacked cards
 * for small screens, so the page stays fully usable on a phone.
 */
function render_students_fragment($students, $selected_term_id, $page, $per_page, $total_students, $total_pages, $search_query, $selected_class_id, $sort) {
    ob_start();
    if ($selected_term_id <= 0) {
        ?>
        <div class="text-center py-16 text-gray-500">
            <div class="w-16 h-16 mx-auto mb-4 rounded-2xl bg-gray-100 flex items-center justify-center">
                <i class="fas fa-calendar-alt text-2xl text-gray-400"></i>
            </div>
            <p class="text-lg font-medium text-gray-700">Select an academic term to get started</p>
            <p class="text-sm text-gray-400 mt-1">Your classes and students will appear here.</p>
        </div>
        <?php
    } elseif (empty($students)) {
        ?>
        <div class="text-center py-16 text-gray-500">
            <div class="w-16 h-16 mx-auto mb-4 rounded-2xl bg-gray-100 flex items-center justify-center">
                <i class="fas fa-user-slash text-2xl text-gray-400"></i>
            </div>
            <p class="text-lg font-medium text-gray-700">
                <?= (!empty($search_query) || $selected_class_id > 0)
                    ? 'No students match your filters'
                    : 'No students enrolled yet' ?>
            </p>
            <p class="text-sm text-gray-400 mt-1">
                <?= (!empty($search_query) || $selected_class_id > 0)
                    ? 'Try a different search term or class.'
                    : 'Students assigned to your classes this term will show up here.' ?>
            </p>
            <?php if (!empty($search_query) || $selected_class_id > 0): ?>
                <button type="button" data-clear-filters
                        class="mt-5 inline-flex items-center gap-2 text-primary font-semibold hover:underline">
                    <i class="fas fa-rotate-left text-xs"></i> Clear filters
                </button>
            <?php endif; ?>
        </div>
        <?php
    } else {
        $sort_link = function ($col, $label) use ($sort) {
            $asc = $col . '_asc';
            $desc = $col . '_desc';
            $next = ($sort === $asc) ? $desc : $asc;
            $active = ($sort === $asc || $sort === $desc);
            $icon = $sort === $asc ? 'fa-arrow-up-short-wide' : ($sort === $desc ? 'fa-arrow-down-wide-short' : 'fa-sort');
            echo '<button type="button" data-sort="' . htmlspecialchars($next) . '" class="inline-flex items-center gap-1.5 font-semibold transition ' . ($active ? 'text-primary' : 'text-gray-600 hover:text-primary') . '">'
                . htmlspecialchars($label) . ' <i class="fas ' . $icon . ' text-xs opacity-70"></i></button>';
        };

        // Carries the current class/search/page/sort so "Back to Students" on the
        // grade-entry pages can restore this exact view instead of resetting it.
        $back_qs = http_build_query(array_filter([
            'class_id' => $selected_class_id > 0 ? $selected_class_id : null,
            'search'   => $search_query !== '' ? $search_query : null,
            'page'     => $page > 1 ? $page : null,
            'sort'     => $sort !== 'class_asc' ? $sort : null,
        ], fn($v) => $v !== null));
        ?>
        <!-- Desktop / tablet table -->
        <div class="hidden md:block overflow-x-auto rounded-xl border border-gray-200">
            <table class="w-full border-collapse">
                <thead>
                    <tr class="bg-gray-50 text-gray-700 text-left text-sm">
                        <th class="px-6 py-3.5"><?php $sort_link('name', 'Student'); ?></th>
                        <th class="px-6 py-3.5"><?php $sort_link('class', 'Class'); ?></th>
                        <th class="px-6 py-3.5 text-right">Grade Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 bg-white">
                    <?php foreach ($students as $student): ?>
                        <?php
                            $initials = '';
                            $name_parts = preg_split('/\s+/', trim($student['name'] ?: $student['email']));
                            foreach (array_slice($name_parts, 0, 2) as $p) { $initials .= mb_strtoupper(mb_substr($p, 0, 1)); }
                            $cc = class_color_classes($student['class_name']);
                        ?>
                        <tr class="hover:bg-gray-50/80 transition">
                            <td class="px-6 py-3.5">
                                <div class="flex items-center gap-3">
                                    <div class="w-10 h-10 rounded-full <?= $cc['bg'] ?> <?= $cc['text'] ?> font-semibold flex items-center justify-center text-sm shrink-0">
                                        <?= htmlspecialchars($initials ?: '?') ?>
                                    </div>
                                    <div class="min-w-0">
                                        <div class="font-medium text-gray-800 truncate"><?= htmlspecialchars($student['name'] ?: $student['email']) ?></div>
                                        <div class="text-sm text-gray-500 truncate"><?= htmlspecialchars($student['email']) ?></div>
                                    </div>
                                </div>
                            </td>
                            <td class="px-6 py-3.5">
                                <span class="inline-block <?= $cc['bg'] ?> <?= $cc['text'] ?> text-xs font-semibold px-3 py-1 rounded-full">
                                    <?= htmlspecialchars($student['class_name']) ?>
                                </span>
                            </td>
                            <td class="px-6 py-3.5">
                                <div class="flex flex-wrap justify-end gap-2">
                                    <a href="mid_term_grades.php?student_id=<?= (int)$student['student_id'] ?>&class_id=<?= (int)$student['class_id'] ?>&term_id=<?= (int)$selected_term_id ?>&back=<?= urlencode($back_qs) ?>"
                                       class="inline-flex items-center gap-2 bg-amber-50 text-amber-700 border border-amber-200 px-3 py-2 rounded-lg hover:bg-amber-100 transition text-sm font-medium">
                                        <i class="fas fa-clipboard-list"></i> Mid-Term
                                    </a>
                                    <a href="end_term_grades.php?student_id=<?= (int)$student['student_id'] ?>&class_id=<?= (int)$student['class_id'] ?>&term_id=<?= (int)$selected_term_id ?>&back=<?= urlencode($back_qs) ?>"
                                       class="inline-flex items-center gap-2 bg-primary text-white px-3 py-2 rounded-lg hover:bg-primary-dark transition text-sm font-medium">
                                        <i class="fas fa-graduation-cap"></i> End of Term
                                    </a>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Mobile stacked cards -->
        <div class="md:hidden space-y-3">
            <?php foreach ($students as $student): ?>
                <?php
                    $initials = '';
                    $name_parts = preg_split('/\s+/', trim($student['name'] ?: $student['email']));
                    foreach (array_slice($name_parts, 0, 2) as $p) { $initials .= mb_strtoupper(mb_substr($p, 0, 1)); }
                    $cc = class_color_classes($student['class_name']);
                ?>
                <div class="rounded-xl border border-gray-200 bg-white p-4">
                    <div class="flex items-center gap-3">
                        <div class="w-11 h-11 rounded-full <?= $cc['bg'] ?> <?= $cc['text'] ?> font-semibold flex items-center justify-center text-sm shrink-0">
                            <?= htmlspecialchars($initials ?: '?') ?>
                        </div>
                        <div class="min-w-0 flex-1">
                            <div class="font-medium text-gray-800 truncate"><?= htmlspecialchars($student['name'] ?: $student['email']) ?></div>
                            <div class="text-sm text-gray-500 truncate"><?= htmlspecialchars($student['email']) ?></div>
                        </div>
                        <span class="shrink-0 <?= $cc['bg'] ?> <?= $cc['text'] ?> text-xs font-semibold px-2.5 py-1 rounded-full">
                            <?= htmlspecialchars($student['class_name']) ?>
                        </span>
                    </div>
                    <div class="flex gap-2 mt-3">
                        <a href="mid_term_grades.php?student_id=<?= (int)$student['student_id'] ?>&class_id=<?= (int)$student['class_id'] ?>&term_id=<?= (int)$selected_term_id ?>&back=<?= urlencode($back_qs) ?>"
                           class="flex-1 text-center inline-flex items-center justify-center gap-2 bg-amber-50 text-amber-700 border border-amber-200 px-3 py-2.5 rounded-lg text-sm font-medium">
                            <i class="fas fa-clipboard-list"></i> Mid-Term
                        </a>
                        <a href="end_term_grades.php?student_id=<?= (int)$student['student_id'] ?>&class_id=<?= (int)$student['class_id'] ?>&term_id=<?= (int)$selected_term_id ?>&back=<?= urlencode($back_qs) ?>"
                           class="flex-1 text-center inline-flex items-center justify-center gap-2 bg-primary text-white px-3 py-2.5 rounded-lg text-sm font-medium">
                            <i class="fas fa-graduation-cap"></i> End of Term
                        </a>
                    </div>
                </div>
            <?php endforeach; ?>
            <!-- Mobile sort control (table header sort buttons are hidden on this breakpoint) -->
            <div class="flex items-center gap-2 pt-1 text-sm text-gray-500">
                <span>Sort:</span>
                <?php $sort_link('name', 'Name'); ?>
                <span class="text-gray-300">&middot;</span>
                <?php $sort_link('class', 'Class'); ?>
            </div>
        </div>

        <div class="flex flex-col sm:flex-row items-center justify-between gap-3 mt-6 text-sm text-gray-600">
            <div>
                Showing <strong><?= count($students) ?></strong> of <strong><?= $total_students ?></strong> student<?= $total_students !== 1 ? 's' : '' ?>
            </div>
            <?php if ($total_pages > 1): ?>
                <div class="flex items-center gap-1" role="navigation" aria-label="Pagination">
                    <button type="button" data-page="<?= max(1, $page - 1) ?>" <?= $page <= 1 ? 'disabled' : '' ?>
                            class="px-3 py-2 rounded-lg border border-gray-300 disabled:opacity-40 disabled:cursor-not-allowed hover:bg-gray-100 transition">
                        <i class="fas fa-chevron-left"></i>
                    </button>
                    <?php for ($p = 1; $p <= $total_pages; $p++): ?>
                        <?php if ($p === 1 || $p === $total_pages || abs($p - $page) <= 1): ?>
                            <button type="button" data-page="<?= $p ?>"
                                    class="px-3 py-2 rounded-lg border transition <?= $p === $page ? 'bg-primary text-white border-primary' : 'border-gray-300 hover:bg-gray-100' ?>">
                                <?= $p ?>
                            </button>
                        <?php elseif (abs($p - $page) === 2): ?>
                            <span class="px-1 text-gray-400">&hellip;</span>
                        <?php endif; ?>
                    <?php endfor; ?>
                    <button type="button" data-page="<?= min($total_pages, $page + 1) ?>" <?= $page >= $total_pages ? 'disabled' : '' ?>
                            class="px-3 py-2 rounded-lg border border-gray-300 disabled:opacity-40 disabled:cursor-not-allowed hover:bg-gray-100 transition">
                        <i class="fas fa-chevron-right"></i>
                    </button>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }
    return ob_get_clean();
}

// ---------------------------------------------------------------------------
// AJAX branch: return just the table/pagination fragment as JSON and stop.
// ---------------------------------------------------------------------------
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');
    echo json_encode([
        'html' => render_students_fragment($students, $selected_term_id, $page, $per_page, $total_students, $total_pages, $search_query, $selected_class_id, $sort),
        'total' => $total_students,
        'page' => $page,
        'total_pages' => $total_pages,
    ]);
    $conn->close();
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Submit Grades - QPMS</title>
    <meta name="description" content="Teacher portal for submitting and managing student grades">
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
        .glass { backdrop-filter: blur(12px); }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(4px); } to { opacity: 1; transform: translateY(0); } }
        .fade-in { animation: fadeIn 0.2s ease-out; }
        .skeleton-row { background: linear-gradient(90deg, #f3f4f6 25%, #e9ecef 37%, #f3f4f6 63%); background-size: 400% 100%; animation: shimmer 1.4s ease infinite; }
        @keyframes shimmer { 0% { background-position: 100% 0; } 100% { background-position: -100% 0; } }
        [data-sort], [data-page], [data-clear-filters], [data-class-chip] { cursor: pointer; }
        :focus-visible { outline: 2px solid #ea580c; outline-offset: 2px; }
        .chip-scroll::-webkit-scrollbar { display: none; }
        .chip-scroll { -ms-overflow-style: none; scrollbar-width: none; }
        #students-container { transition: opacity 0.15s ease; }
    </style>
</head>
<body class="bg-gray-50 min-h-screen flex flex-col">
    <!-- Header -->
    <header class="bg-white shadow-sm border-b border-gray-200 sticky top-0 z-20">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 py-3.5 flex justify-between items-center">
            <div class="flex items-center gap-3">
                <div class="w-9 h-9 rounded-lg bg-primary text-white flex items-center justify-center font-bold text-sm shrink-0">QP</div>
                <div class="hidden sm:block">
                    <div class="text-sm font-semibold text-gray-800 leading-tight">QPMS</div>
                    <div class="text-xs text-gray-400 leading-tight">Submit Student Grades</div>
                </div>
            </div>
            <nav class="flex items-center gap-3 sm:gap-5">
                <a href="/teacher/dashboard.php" class="text-gray-500 hover:text-primary transition flex items-center gap-2 text-sm font-medium">
                    <i class="fas fa-arrow-left"></i> <span class="hidden sm:inline">Dashboard</span>
                </a>
                <a href="/logout.php" class="text-gray-400 hover:text-red-600 transition" title="Log out">
                    <i class="fas fa-sign-out-alt"></i>
                </a>
            </nav>
        </div>
    </header>

    <!-- Main Content -->
    <main class="flex-grow max-w-7xl mx-auto w-full px-4 sm:px-6 py-6 sm:py-10">

        <!-- Page heading + messages -->
        <div class="mb-6">
            <h1 class="text-2xl sm:text-3xl font-extrabold text-gray-900">Grade Management</h1>
            <p class="text-gray-500 mt-1">Find a student and submit their mid-term or end-of-term grades.</p>
        </div>

        <?php if (isset($_SESSION['error_msg'])): ?>
            <div id="flash-msg" class="mb-6 p-4 bg-red-50 border border-red-200 text-red-700 rounded-xl flex items-center gap-3 fade-in">
                <i class="fas fa-exclamation-triangle"></i>
                <?= htmlspecialchars($_SESSION['error_msg']); unset($_SESSION['error_msg']); ?>
            </div>
        <?php endif; ?>
        <?php if (isset($_SESSION['success_msg'])): ?>
            <div id="flash-msg" class="mb-6 p-4 bg-green-50 border border-green-200 text-green-700 rounded-xl flex items-center gap-3 fade-in">
                <i class="fas fa-check-circle"></i>
                <?= htmlspecialchars($_SESSION['success_msg']); unset($_SESSION['success_msg']); ?>
            </div>
        <?php endif; ?>

        <!-- Term picker -->
        <div class="bg-white rounded-2xl border border-gray-200 p-5 sm:p-6 mb-5">
            <div class="flex flex-col sm:flex-row sm:items-end gap-4 justify-between">
                <div class="flex-1 max-w-sm">
                    <label class="block text-xs font-semibold uppercase tracking-wide text-gray-500 mb-2">Academic Term</label>
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                        <div class="relative">
                            <select name="term_id" onchange="this.form.submit()" required
                                    class="w-full appearance-none pl-4 pr-10 py-3 border border-gray-300 rounded-xl bg-white font-medium text-gray-800 focus:ring-4 focus:ring-primary/20 focus:border-primary transition">
                                <option value="">-- Choose Term --</option>
                                <?php
                                $terms_result->data_seek(0);
                                while ($row = $terms_result->fetch_assoc()): ?>
                                    <option value="<?= $row['id'] ?>" <?= $row['id'] == $selected_term_id ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($row['term_name'] . ' ' . $row['year']) ?>
                                    </option>
                                <?php endwhile; ?>
                            </select>
                            <i class="fas fa-chevron-down absolute right-4 top-1/2 -translate-y-1/2 text-gray-400 text-xs pointer-events-none"></i>
                        </div>
                    </form>
                </div>

                <?php if ($current_term): ?>
                <!-- Quick stats -->
                <div class="flex gap-3 sm:gap-4">
                    <div class="flex items-center gap-2.5 bg-primary-light rounded-xl px-4 py-2.5">
                        <i class="fas fa-chalkboard text-primary"></i>
                        <div>
                            <div class="text-lg font-bold text-gray-800 leading-none"><?= count($classes_list) ?></div>
                            <div class="text-xs text-gray-500">Classes</div>
                        </div>
                    </div>
                    <div class="flex items-center gap-2.5 bg-primary-light rounded-xl px-4 py-2.5">
                        <i class="fas fa-users text-primary"></i>
                        <div>
                            <div class="text-lg font-bold text-gray-800 leading-none"><?= $term_total_students ?></div>
                            <div class="text-xs text-gray-500">Students</div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($selected_term_id > 0): ?>
            <!-- Sticky filter toolbar -->
            <div class="sticky top-[61px] z-10 bg-gray-50/95 backdrop-blur -mx-4 sm:mx-0 px-4 sm:px-0 py-3 mb-1">
                <div class="bg-white rounded-2xl border border-gray-200 p-4 sm:p-5">
                    <!-- Search -->
                    <div class="relative mb-4">
                        <i class="fas fa-search absolute left-4 top-1/2 -translate-y-1/2 text-gray-400"></i>
                        <input type="text" id="student-search" value="<?= htmlspecialchars($search_query) ?>"
                               placeholder="Search students by name or email...  (press / to focus)" autocomplete="off"
                               class="w-full pl-11 pr-10 py-3 border border-gray-300 rounded-xl focus:ring-4 focus:ring-primary/20 focus:border-primary transition">
                        <button type="button" id="clear-search" class="absolute right-3.5 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600 <?= empty($search_query) ? 'hidden' : '' ?>">
                            <i class="fas fa-circle-xmark"></i>
                        </button>
                    </div>

                    <!-- Class chips -->
                    <div class="flex items-center gap-2 overflow-x-auto chip-scroll pb-1" id="class-chips">
                        <button type="button" data-class-chip="0"
                                class="chip shrink-0 px-4 py-2 rounded-full text-sm font-semibold border transition <?= $selected_class_id === 0 ? 'bg-primary text-white border-primary' : 'bg-white text-gray-600 border-gray-300 hover:border-primary/50' ?>">
                            All classes
                        </button>
                        <?php foreach ($classes_list as $id => $name): ?>
                            <button type="button" data-class-chip="<?= $id ?>"
                                    class="chip shrink-0 px-4 py-2 rounded-full text-sm font-semibold border transition <?= $id == $selected_class_id ? 'bg-primary text-white border-primary' : 'bg-white text-gray-600 border-gray-300 hover:border-primary/50' ?>">
                                <?= htmlspecialchars($name) ?>
                            </button>
                        <?php endforeach; ?>
                    </div>
                </div>
                <!-- Active filter status -->
                <div id="filter-status" class="px-1 pt-2.5 text-sm text-gray-500 min-h-[1.5rem]" aria-live="polite"></div>
            </div>

            <!-- Results (server-rendered on load, refreshed via AJAX after that) -->
            <div id="students-container" class="mt-2">
                <?= render_students_fragment($students, $selected_term_id, $page, $per_page, $total_students, $total_pages, $search_query, $selected_class_id, $sort) ?>
            </div>
        <?php else: ?>
            <div class="bg-white rounded-2xl border border-gray-200 text-center py-16 text-gray-500">
                <div class="w-16 h-16 mx-auto mb-4 rounded-2xl bg-gray-100 flex items-center justify-center">
                    <i class="fas fa-calendar-alt text-2xl text-gray-400"></i>
                </div>
                <p class="text-lg font-medium text-gray-700">Select an academic term to get started</p>
            </div>
        <?php endif; ?>
    </main>

    <!-- Footer -->
    <footer class="text-gray-400 py-8 mt-auto">
        <div class="max-w-7xl mx-auto px-6 text-center text-sm">
            <p>&copy; <?= date('Y') ?> QPMS &middot; Quality Progress Management System</p>
        </div>
    </footer>

    <?php if ($selected_term_id > 0): ?>
    <script>
    (function () {
        const container = document.getElementById('students-container');
        const searchInput = document.getElementById('student-search');
        const clearSearchBtn = document.getElementById('clear-search');
        const chipsWrap = document.getElementById('class-chips');
        const statusEl = document.getElementById('filter-status');

        let state = {
            search: <?= json_encode($search_query) ?>,
            class_id: <?= (int)$selected_class_id ?>,
            page: <?= (int)$page ?>,
            sort: <?= json_encode($sort) ?>,
        };
        let debounceTimer = null;
        let currentRequest = null;

        function activeClassLabel() {
            const btn = chipsWrap.querySelector(`[data-class-chip="${state.class_id}"]`);
            return btn ? btn.textContent.trim() : '';
        }

        function updateStatus() {
            const parts = [];
            if (state.class_id > 0) parts.push(`Class: <strong>${escapeHtml(activeClassLabel())}</strong>`);
            if (state.search) parts.push(`Search: <strong>&quot;${escapeHtml(state.search)}&quot;</strong>`);
            statusEl.innerHTML = parts.length ? 'Filtering by ' + parts.join(' &middot; ') : 'Showing all students for this term';
        }

        function escapeHtml(str) {
            const div = document.createElement('div');
            div.textContent = str;
            return div.innerHTML;
        }

        function setChipsActive() {
            chipsWrap.querySelectorAll('.chip').forEach(chip => {
                const isActive = parseInt(chip.dataset.classChip, 10) === state.class_id;
                chip.classList.toggle('bg-primary', isActive);
                chip.classList.toggle('text-white', isActive);
                chip.classList.toggle('border-primary', isActive);
                chip.classList.toggle('bg-white', !isActive);
                chip.classList.toggle('text-gray-600', !isActive);
                chip.classList.toggle('border-gray-300', !isActive);
            });
        }

        function skeletonMarkup() {
            let rows = '';
            for (let i = 0; i < 5; i++) {
                rows += `<div class="flex items-center gap-3 px-6 py-4 border-b border-gray-100 last:border-0">
                            <div class="w-10 h-10 rounded-full skeleton-row"></div>
                            <div class="flex-1 space-y-2">
                                <div class="h-3 w-1/3 rounded skeleton-row"></div>
                                <div class="h-2.5 w-1/2 rounded skeleton-row"></div>
                            </div>
                            <div class="h-8 w-24 rounded-lg skeleton-row hidden sm:block"></div>
                         </div>`;
            }
            return `<div class="rounded-xl border border-gray-200 bg-white overflow-hidden">${rows}</div>`;
        }

        function fetchResults() {
            container.style.opacity = '0.5';
            updateStatus();
            setChipsActive();

            const params = new URLSearchParams({
                ajax: '1',
                search: state.search,
                class_id: state.class_id,
                page: state.page,
                sort: state.sort,
            });

            // Keep the URL shareable/bookmarkable without a full reload
            const shareParams = new URLSearchParams(window.location.search);
            ['search', 'class_id', 'page', 'sort'].forEach(k => shareParams.delete(k));
            if (state.search) shareParams.set('search', state.search);
            if (state.class_id) shareParams.set('class_id', state.class_id);
            if (state.page > 1) shareParams.set('page', state.page);
            if (state.sort !== 'class_asc') shareParams.set('sort', state.sort);
            const newUrl = window.location.pathname + (shareParams.toString() ? '?' + shareParams.toString() : '');
            window.history.replaceState({}, '', newUrl);

            if (currentRequest) currentRequest.abort();
            const controller = new AbortController();
            currentRequest = controller;

            fetch('?' + params.toString(), { signal: controller.signal })
                .then(r => r.json())
                .then(data => {
                    container.innerHTML = data.html;
                    container.style.opacity = '1';
                    container.classList.add('fade-in');
                    clearSearchBtn.classList.toggle('hidden', !state.search);
                    bindFragmentEvents();
                })
                .catch(err => {
                    if (err.name !== 'AbortError') {
                        container.style.opacity = '1';
                        container.innerHTML = '<div class="text-center py-16 text-red-500"><i class="fas fa-exclamation-triangle text-4xl mb-3"></i><p>Something went wrong loading students. Please try again.</p></div>';
                    }
                });
        }

        function bindFragmentEvents() {
            container.querySelectorAll('[data-page]').forEach(btn => {
                btn.addEventListener('click', () => {
                    if (btn.disabled) return;
                    state.page = parseInt(btn.dataset.page, 10);
                    fetchResults();
                    container.scrollIntoView({ behavior: 'smooth', block: 'start' });
                });
            });
            container.querySelectorAll('[data-sort]').forEach(btn => {
                btn.addEventListener('click', () => {
                    state.sort = btn.dataset.sort;
                    state.page = 1;
                    fetchResults();
                });
            });
            const clearBtn = container.querySelector('[data-clear-filters]');
            if (clearBtn) {
                clearBtn.addEventListener('click', () => {
                    state.search = '';
                    state.class_id = 0;
                    state.page = 1;
                    searchInput.value = '';
                    fetchResults();
                });
            }
        }

        searchInput.addEventListener('input', () => {
            clearSearchBtn.classList.toggle('hidden', !searchInput.value);
            clearTimeout(debounceTimer);
            debounceTimer = setTimeout(() => {
                state.search = searchInput.value.trim();
                state.page = 1;
                fetchResults();
            }, 300);
        });

        clearSearchBtn.addEventListener('click', () => {
            searchInput.value = '';
            state.search = '';
            state.page = 1;
            clearSearchBtn.classList.add('hidden');
            fetchResults();
            searchInput.focus();
        });

        chipsWrap.addEventListener('click', (e) => {
            const chip = e.target.closest('[data-class-chip]');
            if (!chip) return;
            state.class_id = parseInt(chip.dataset.classChip, 10) || 0;
            state.page = 1;
            fetchResults();
        });

        // "/" focuses the search box from anywhere on the page, like most modern apps
        document.addEventListener('keydown', (e) => {
            if (e.key === '/' && document.activeElement !== searchInput) {
                e.preventDefault();
                searchInput.focus();
            }
        });

        // Wire up the buttons rendered in the initial server-side HTML too
        bindFragmentEvents();
        updateStatus();
    })();
    </script>
    <?php endif; ?>

    <?php if (isset($_SESSION['error_msg']) || isset($_SESSION['success_msg'])): ?>
    <script>
        setTimeout(() => {
            const el = document.getElementById('flash-msg');
            if (el) { el.style.transition = 'opacity 0.4s'; el.style.opacity = '0'; setTimeout(() => el.remove(), 400); }
        }, 4000);
    </script>
    <?php endif; ?>
</body>
</html>
<?php $conn->close(); ?>