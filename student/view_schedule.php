<?php
ob_start();
session_start();

// === Auth & Session Guard ===
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'student') {
    header("Location: /login.php");
    exit();
}

require_once($_SERVER['DOCUMENT_ROOT'] . '/config/db.php');

$student_id  = (int)$_SESSION['user_id'];
$days_order  = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'];
$today_name  = date('l'); // e.g. "Wednesday"
$is_weekday  = in_array($today_name, $days_order);

// ── Helper: safe DB error without leaking internals ──────────────────────────
function db_fail(string $msg): void {
    error_log($msg);
}

// ── 1. Student name + academic year (read directly from DB, never computed) ──
$student_name = 'Student';
$current_year = null;   // will be set from the students row

try {
    // Join students to get the real academic_year stored against this user
    $stmt = $conn->prepare(
        "SELECT u.name, s.academic_year
         FROM users u
         LEFT JOIN students s ON s.user_id = u.id
         WHERE u.id = ? AND u.role = 'student'
         ORDER BY s.id DESC
         LIMIT 1"
    );
    if ($stmt) {
        $stmt->bind_param("i", $student_id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $student_name = htmlspecialchars($row['name'] ?? 'Student');
        // Use the year stored in the students table; fall back to class_schedules max year
        $current_year = $row['academic_year'] ?? null;
        $stmt->close();
    }
} catch (Throwable $e) {
    db_fail("view_schedule: fetch student name/year – " . $e->getMessage());
}

// If still null (student row missing), derive from the most recent year in class_schedules
if (empty($current_year)) {
    try {
        $r = $conn->query("SELECT academic_year FROM class_schedules ORDER BY id DESC LIMIT 1");
        if ($r) {
            $yr_row = $r->fetch_assoc();
            $current_year = $yr_row['academic_year'] ?? (date('Y') . '/' . (date('Y') + 1));
        }
    } catch (Throwable $e) {
        db_fail("view_schedule: fallback year – " . $e->getMessage());
        $current_year = date('Y') . '/' . (date('Y') + 1);
    }
}

// ── 2. Student's enrolled class IDs ─────────────────────────────────────────
// Primary source: student_classes join table (if it exists).
// Fallback: class_schedules filtered only by academic_year (shows all
//           classes for that year — used when student_classes is empty/missing).
$class_ids  = [];
$no_classes = false;

// Try student_classes first
try {
    $stmt = $conn->prepare(
        "SELECT c.id
         FROM classes c
         JOIN student_classes sc ON c.id = sc.class_id
         WHERE sc.user_id = ? AND sc.academic_year = ?"
    );
    if ($stmt) {
        $stmt->bind_param("is", $student_id, $current_year);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $class_ids = array_column($rows, 'id');
        $stmt->close();
    }
} catch (Throwable $e) {
    // student_classes table may not exist — not a fatal error, try fallback
    db_fail("view_schedule: student_classes query – " . $e->getMessage());
}

// Fallback: look up via users.class_id if the column exists
if (empty($class_ids)) {
    try {
        $stmt = $conn->prepare(
            "SELECT class_id FROM users WHERE id = ? AND class_id IS NOT NULL LIMIT 1"
        );
        if ($stmt) {
            $stmt->bind_param("i", $student_id);
            $stmt->execute();
            $r = $stmt->get_result()->fetch_assoc();
            if (!empty($r['class_id'])) {
                $class_ids = [(int)$r['class_id']];
            }
            $stmt->close();
        }
    } catch (Throwable $e) {
        db_fail("view_schedule: users.class_id fallback – " . $e->getMessage());
    }
}

// Final fallback: pull class IDs directly from class_schedules for this year
// (gives the student a view of all timetabled classes; better than a blank screen)
if (empty($class_ids)) {
    try {
        $stmt = $conn->prepare(
            "SELECT DISTINCT class_id FROM class_schedules WHERE academic_year = ? LIMIT 20"
        );
        if ($stmt) {
            $stmt->bind_param("s", $current_year);
            $stmt->execute();
            $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $class_ids = array_column($rows, 'class_id');
            $stmt->close();
        }
    } catch (Throwable $e) {
        db_fail("view_schedule: schedules fallback class ids – " . $e->getMessage());
    }
}

if (empty($class_ids)) {
    $no_classes = true;
}

// ── 4. Fetch schedules ───────────────────────────────────────────────────────
$schedules      = [];
$no_schedule    = false;
$fetch_error    = false;

if (!$no_classes) {
    try {
        $placeholders = implode(',', array_fill(0, count($class_ids), '?'));
        $types        = str_repeat('i', count($class_ids));

        // Join academic_calendar for term_name (LEFT JOIN so missing term
        // rows don't silently drop schedule records)
        $sql = "SELECT
                    cs.id            AS schedule_id,
                    cs.day_of_week,
                    cs.start_time,
                    cs.end_time,
                    cs.academic_year,
                    c.class_name,
                    s.subject_name,
                    u.name           AS teacher_name,
                    COALESCE(ac.term_name, '') AS term_name
                FROM class_schedules cs
                JOIN  classes  c  ON cs.class_id  = c.id
                JOIN  subjects s  ON cs.subject_id = s.id
                JOIN  users    u  ON cs.teacher_id = u.id
                LEFT JOIN academic_calendar ac ON cs.term_id = ac.id
                WHERE cs.class_id IN ($placeholders)
                  AND cs.academic_year = ?
                ORDER BY FIELD(cs.day_of_week,
                               'Monday','Tuesday','Wednesday','Thursday','Friday'),
                         cs.start_time ASC";

        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            throw new RuntimeException($conn->error);
        }

        // bind: all class IDs + academic_year string
        $bind_types  = $types . 's';
        $bind_values = array_merge($class_ids, [$current_year]);

        // Use spread operator for bind_param via references
        $refs = [];
        foreach ($bind_values as $k => $v) {
            $refs[$k] = &$bind_values[$k];
        }
        array_unshift($refs, $bind_types);
        call_user_func_array([$stmt, 'bind_param'], $refs);

        $stmt->execute();
        $schedules = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        if (empty($schedules)) {
            $no_schedule = true;
        }
    } catch (Throwable $e) {
        db_fail("view_schedule: fetch schedules – " . $e->getMessage());
        $fetch_error = true;
    }
}

// ── 5. Build timetable data structures ──────────────────────────────────────

// Collect all unique start times across all days (sorted)
$all_start_times = [];
foreach ($schedules as $s) {
    $all_start_times[] = $s['start_time'];
}
$all_start_times = array_values(array_unique($all_start_times));
sort($all_start_times);

// $timetable[day][start_time] = schedule_row
// Multiple subjects at same start time for same day? Collect as array.
$timetable = [];
foreach ($days_order as $day) {
    $timetable[$day] = [];
}
foreach ($schedules as $s) {
    $day  = $s['day_of_week'];
    $time = $s['start_time'];
    // Support multiple slots at the same time (shouldn't normally happen
    // but guards against duplicate data)
    $timetable[$day][$time][] = $s;
}

// Today's schedule (sorted by start_time)
$today_schedule = [];
if ($is_weekday && !empty($timetable[$today_name])) {
    foreach ($all_start_times as $t) {
        if (!empty($timetable[$today_name][$t])) {
            foreach ($timetable[$today_name][$t] as $slot) {
                $today_schedule[] = $slot;
            }
        }
    }
}

// ── 6. Helper functions ──────────────────────────────────────────────────────
function fmt_time(string $t): string {
    $ts = strtotime($t);
    return $ts !== false ? date('h:i A', $ts) : $t;
}

function duration_mins(string $start, string $end): int {
    $s = strtotime($start);
    $e = strtotime($end);
    if ($s === false || $e === false) return 0;
    return max(0, (int)(($e - $s) / 60));
}

// Colour palette for subjects (rotates by subject name hash)
$subject_colors = [
    ['bg' => '#FEF3C7', 'border' => '#F59E0B', 'text' => '#92400E'],
    ['bg' => '#DBEAFE', 'border' => '#3B82F6', 'text' => '#1E3A8A'],
    ['bg' => '#D1FAE5', 'border' => '#10B981', 'text' => '#065F46'],
    ['bg' => '#EDE9FE', 'border' => '#8B5CF6', 'text' => '#4C1D95'],
    ['bg' => '#FCE7F3', 'border' => '#EC4899', 'text' => '#831843'],
    ['bg' => '#CCFBF1', 'border' => '#14B8A6', 'text' => '#134E4A'],
    ['bg' => '#FEE2E2', 'border' => '#EF4444', 'text' => '#7F1D1D'],
    ['bg' => '#E0F2FE', 'border' => '#0EA5E9', 'text' => '#0C4A6E'],
];
$subject_color_map = [];

function get_subject_color(string $subject_name, array &$map, array $palette): array {
    if (!isset($map[$subject_name])) {
        $map[$subject_name] = $palette[count($map) % count($palette)];
    }
    return $map[$subject_name];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Timetable – QueensPa School</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        body { font-family: 'Inter', sans-serif; }

        /* ── Scrollable timetable wrapper ── */
        .timetable-scroll { overflow-x: auto; -webkit-overflow-scrolling: touch; }

        /* ── Grid: first col fixed width, rest equal ── */
        .timetable-grid {
            display: grid;
            min-width: 700px;
        }

        .cell {
            padding: 10px 8px;
            border: 1px solid #e2e8f0;
            font-size: 0.8rem;
            min-height: 90px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            text-align: center;
            background: #fff;
        }
        .cell.header-corner { background: #ff4500; color: #fff; font-weight: 700;
                              font-size: 0.75rem; min-height: 52px; border-radius: 0; }
        .cell.header-time   { background: #fff7ed; font-weight: 600; color: #9a3412;
                              min-height: 52px; font-size: 0.72rem; line-height: 1.3; }
        .cell.day-label     { background: #ff4500; color: #fff; font-weight: 700;
                              font-size: 0.78rem; }
        .cell.day-label.today-col { background: #c23600; }
        .cell.empty         { background: #f8fafc; color: #94a3b8; font-size: 0.7rem; }
        .cell.break-cell    { background: #f1f5f9; color: #64748b; font-size: 0.68rem;
                              font-style: italic; min-height: 52px; }

        /* Lesson card inside cell */
        .lesson-card {
            width: 100%;
            border-radius: 6px;
            padding: 6px 5px;
            border-left: 3px solid;
            text-align: left;
        }
        .lesson-card .subject  { font-weight: 700; font-size: 0.78rem; line-height: 1.2; }
        .lesson-card .meta     { font-size: 0.67rem; margin-top: 3px; opacity: 0.85; }

        /* Today highlight on timetable column */
        .today-cell { background: #fffbeb !important; }

        /* Tab active */
        .tab-btn.active { background: #ff4500; color: #fff; }
        .tab-btn        { transition: background 0.2s, color 0.2s; }

        /* Today badge */
        .today-badge { animation: pulse-soft 2s infinite; }
        @keyframes pulse-soft {
            0%, 100% { box-shadow: 0 0 0 0 rgba(255,69,0,.35); }
            50%       { box-shadow: 0 0 0 6px rgba(255,69,0,0); }
        }

        /* Print */
        @media print {
            .no-print { display: none !important; }
            body { background: #fff; }
        }
    </style>
</head>
<body class="bg-gradient-to-br from-orange-50 to-teal-50 min-h-screen">

<?php
// Include header (gracefully skip if missing)
$header = $_SERVER['DOCUMENT_ROOT'] . '/templates/student_header.php';
if (file_exists($header)) include $header;
?>

<main class="max-w-7xl mx-auto px-4 py-8">

    <!-- ── Page Title ─────────────────────────────────────────── -->
    <div class="text-center mb-8">
        <h1 class="text-3xl md:text-4xl font-extrabold text-gray-800">
            <i class="fas fa-calendar-week text-orange-500 mr-2"></i>My Weekly Timetable
        </h1>
        <p class="text-gray-500 mt-2">
            Hello, <span class="font-semibold text-orange-600"><?= $student_name ?></span>!
            Academic Year: <span class="font-semibold"><?= htmlspecialchars($current_year) ?></span>
        </p>
    </div>

    <?php if ($no_classes): ?>
    <!-- ── No enrolment ──────────────────────────────────────── -->
    <div class="bg-white rounded-2xl shadow p-12 text-center max-w-md mx-auto">
        <div class="text-6xl mb-4">📚</div>
        <h3 class="text-xl font-bold text-gray-700">Not Enrolled in Any Class</h3>
        <p class="text-gray-500 mt-2">Contact the school administration to get assigned to a class for <?= htmlspecialchars($current_year) ?>.</p>
    </div>

    <?php elseif ($fetch_error): ?>
    <!-- ── DB error ──────────────────────────────────────────── -->
    <div class="bg-red-50 border border-red-200 rounded-2xl p-10 text-center max-w-md mx-auto">
        <div class="text-5xl mb-4">⚠️</div>
        <h3 class="text-xl font-bold text-red-700">Could Not Load Timetable</h3>
        <p class="text-red-500 mt-2">There was a problem retrieving your schedule. Please try refreshing the page or contact support.</p>
    </div>

    <?php elseif ($no_schedule): ?>
    <!-- ── Enrolled but no schedule set ─────────────────────── -->
    <div class="bg-white rounded-2xl shadow p-12 text-center max-w-md mx-auto">
        <div class="text-6xl mb-4">🗓️</div>
        <h3 class="text-xl font-bold text-gray-700">No Schedule Available Yet</h3>
        <p class="text-gray-500 mt-2">Your teachers haven't published the timetable yet. Check back soon.</p>
    </div>

    <?php else: ?>
    <!-- ══════════════════════════════════════════════════════════
         TODAY'S CLASSES PANEL
    ═══════════════════════════════════════════════════════════ -->
    <div class="mb-8">
        <div class="bg-white rounded-2xl shadow-lg overflow-hidden">
            <div class="bg-orange-500 px-6 py-4 flex items-center justify-between">
                <h2 class="text-white font-bold text-lg flex items-center gap-2">
                    <i class="fas fa-sun"></i>
                    Today's Classes —
                    <span class="font-extrabold"><?= $today_name ?>, <?= date('F j, Y') ?></span>
                </h2>
                <?php if ($is_weekday && !empty($today_schedule)): ?>
                    <span class="today-badge bg-white text-orange-600 text-xs font-bold px-3 py-1 rounded-full">
                        <?= count($today_schedule) ?> period<?= count($today_schedule) !== 1 ? 's' : '' ?>
                    </span>
                <?php endif; ?>
            </div>

            <div class="p-6">
            <?php if (!$is_weekday): ?>
                <div class="text-center py-6 text-gray-500">
                    <i class="fas fa-couch text-3xl mb-2 text-orange-300"></i>
                    <p class="font-medium">No school today — enjoy your weekend! 🎉</p>
                </div>

            <?php elseif (empty($today_schedule)): ?>
                <div class="text-center py-6 text-gray-500">
                    <i class="fas fa-check-circle text-3xl mb-2 text-green-400"></i>
                    <p class="font-medium">No classes scheduled for today.</p>
                </div>

            <?php else: ?>
                <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    <?php foreach ($today_schedule as $i => $slot):
                        $color = get_subject_color($slot['subject_name'], $subject_color_map, $subject_colors);
                        $dur   = duration_mins($slot['start_time'], $slot['end_time']);
                    ?>
                    <div class="rounded-xl p-4 border-l-4 flex flex-col gap-1"
                         style="background:<?= $color['bg'] ?>; border-color:<?= $color['border'] ?>">
                        <div class="flex items-start justify-between">
                            <span class="text-xs font-bold rounded px-2 py-0.5"
                                  style="background:<?= $color['border'] ?>; color:#fff">
                                Period <?= $i + 1 ?>
                            </span>
                            <span class="text-xs font-medium" style="color:<?= $color['text'] ?>">
                                <?= $dur ?> min
                            </span>
                        </div>
                        <p class="font-bold text-base leading-tight mt-1"
                           style="color:<?= $color['text'] ?>">
                            <?= htmlspecialchars($slot['subject_name']) ?>
                        </p>
                        <p class="text-xs" style="color:<?= $color['text'] ?>">
                            <i class="fas fa-clock mr-1 opacity-70"></i>
                            <?= fmt_time($slot['start_time']) ?> – <?= fmt_time($slot['end_time']) ?>
                        </p>
                        <p class="text-xs" style="color:<?= $color['text'] ?>">
                            <i class="fas fa-chalkboard-teacher mr-1 opacity-70"></i>
                            <?= htmlspecialchars($slot['teacher_name']) ?>
                        </p>
                        <p class="text-xs mt-1 opacity-75" style="color:<?= $color['text'] ?>">
                            <i class="fas fa-door-open mr-1"></i>
                            <?= htmlspecialchars($slot['class_name']) ?>
                        </p>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- ══════════════════════════════════════════════════════════
         TAB NAVIGATION  (Weekly Grid  |  Day-by-Day List)
    ═══════════════════════════════════════════════════════════ -->
    <div class="flex gap-2 mb-4 no-print">
        <button class="tab-btn active rounded-full px-5 py-2 text-sm font-semibold border border-orange-400 text-orange-600"
                onclick="switchTab('grid')">
            <i class="fas fa-table mr-1"></i>Weekly Grid
        </button>
        <button class="tab-btn rounded-full px-5 py-2 text-sm font-semibold border border-gray-300 text-gray-600"
                onclick="switchTab('list')">
            <i class="fas fa-list mr-1"></i>Day by Day
        </button>
        <button class="ml-auto rounded-full px-4 py-2 text-sm font-semibold border border-gray-300 text-gray-600 no-print"
                onclick="window.print()">
            <i class="fas fa-print mr-1"></i>Print
        </button>
    </div>

    <!-- ══════════════════════════════════════════════════════════
         TAB 1: WEEKLY GRID
    ═══════════════════════════════════════════════════════════ -->
    <div id="tab-grid" class="bg-white rounded-2xl shadow-xl p-4 md:p-6 mb-6">
        <div class="timetable-scroll">
            <?php
            $col_count = count($all_start_times);
            $grid_cols = "120px " . implode(' ', array_fill(0, $col_count, '1fr'));
            ?>
            <div class="timetable-grid rounded-xl overflow-hidden border border-slate-200"
                 style="grid-template-columns: <?= $grid_cols ?>">

                <!-- Header row -->
                <div class="cell header-corner">Day / Time</div>
                <?php foreach ($all_start_times as $t): ?>
                    <div class="cell header-time">
                        <?= fmt_time($t) ?>
                    </div>
                <?php endforeach; ?>

                <!-- Data rows -->
                <?php foreach ($days_order as $day):
                    $is_today_row = ($day === $today_name);
                ?>
                    <!-- Day label cell -->
                    <div class="cell day-label <?= $is_today_row ? 'today-col' : '' ?> text-xs">
                        <?= $day ?>
                        <?php if ($is_today_row): ?>
                            <span class="block text-orange-200 font-normal text-xxs mt-0.5" style="font-size:0.6rem">TODAY</span>
                        <?php endif; ?>
                    </div>

                    <!-- Time slot cells -->
                    <?php foreach ($all_start_times as $t):
                        $lessons  = $timetable[$day][$t] ?? [];
                        $is_today_cell = $is_today_row;
                    ?>
                        <div class="cell <?= $is_today_cell ? 'today-cell' : '' ?> <?= empty($lessons) ? 'empty' : '' ?>">
                            <?php if (empty($lessons)): ?>
                                <span class="text-slate-300 text-xs">—</span>
                            <?php else:
                                foreach ($lessons as $lesson):
                                    $color = get_subject_color($lesson['subject_name'], $subject_color_map, $subject_colors);
                            ?>
                                <div class="lesson-card w-full"
                                     style="background:<?= $color['bg'] ?>; border-color:<?= $color['border'] ?>">
                                    <div class="subject" style="color:<?= $color['text'] ?>">
                                        <?= htmlspecialchars($lesson['subject_name']) ?>
                                    </div>
                                    <div class="meta" style="color:<?= $color['text'] ?>">
                                        <?= fmt_time($lesson['start_time']) ?> – <?= fmt_time($lesson['end_time']) ?><br>
                                        <span class="opacity-80"><?= htmlspecialchars($lesson['teacher_name']) ?></span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                <?php endforeach; ?>

            </div><!-- /timetable-grid -->
        </div><!-- /timetable-scroll -->

        <p class="text-xs text-gray-400 mt-3 text-right no-print">
            <i class="fas fa-arrows-left-right mr-1"></i>Scroll horizontally to see all periods
        </p>
    </div>

    <!-- ══════════════════════════════════════════════════════════
         TAB 2: DAY-BY-DAY LIST
    ═══════════════════════════════════════════════════════════ -->
    <div id="tab-list" class="hidden space-y-6 mb-6">
        <?php foreach ($days_order as $day):
            $is_today_day   = ($day === $today_name);
            $day_slots      = [];
            foreach ($all_start_times as $t) {
                if (!empty($timetable[$day][$t])) {
                    foreach ($timetable[$day][$t] as $sl) $day_slots[] = $sl;
                }
            }
        ?>
        <div class="bg-white rounded-2xl shadow overflow-hidden <?= $is_today_day ? 'ring-2 ring-orange-400' : '' ?>">
            <div class="px-5 py-3 flex items-center justify-between
                        <?= $is_today_day ? 'bg-orange-500' : 'bg-slate-700' ?>">
                <h3 class="text-white font-bold text-base">
                    <?= $day ?>
                    <?php if ($is_today_day): ?>
                        <span class="ml-2 text-xs bg-white text-orange-600 font-bold rounded-full px-2 py-0.5">TODAY</span>
                    <?php endif; ?>
                </h3>
                <span class="text-white text-sm opacity-80">
                    <?= count($day_slots) ?> period<?= count($day_slots) !== 1 ? 's' : '' ?>
                </span>
            </div>

            <?php if (empty($day_slots)): ?>
                <div class="px-5 py-6 text-gray-400 text-sm text-center">
                    <i class="fas fa-mug-hot mr-1"></i>No classes scheduled
                </div>
            <?php else: ?>
                <div class="divide-y divide-slate-100">
                    <?php foreach ($day_slots as $i => $slot):
                        $color = get_subject_color($slot['subject_name'], $subject_color_map, $subject_colors);
                        $dur   = duration_mins($slot['start_time'], $slot['end_time']);
                    ?>
                        <div class="flex items-stretch px-5 py-3 gap-4 hover:bg-slate-50 transition">
                            <!-- Time column -->
                            <div class="w-24 shrink-0 text-center">
                                <div class="text-xs font-bold text-gray-700"><?= fmt_time($slot['start_time']) ?></div>
                                <div class="text-xs text-gray-400 leading-none">↓</div>
                                <div class="text-xs text-gray-500"><?= fmt_time($slot['end_time']) ?></div>
                                <div class="text-xs text-gray-400 mt-1"><?= $dur ?> min</div>
                            </div>
                            <!-- Colour stripe -->
                            <div class="w-1 rounded-full shrink-0" style="background:<?= $color['border'] ?>"></div>
                            <!-- Details -->
                            <div class="flex-1 min-w-0">
                                <p class="font-bold text-sm text-gray-800 truncate">
                                    <?= htmlspecialchars($slot['subject_name']) ?>
                                </p>
                                <p class="text-xs text-gray-500 mt-0.5">
                                    <i class="fas fa-chalkboard-teacher mr-1 text-gray-400"></i>
                                    <?= htmlspecialchars($slot['teacher_name']) ?>
                                </p>
                                <p class="text-xs text-gray-400 mt-0.5">
                                    <i class="fas fa-door-open mr-1"></i>
                                    <?= htmlspecialchars($slot['class_name']) ?>
                                    <?php if (!empty($slot['term_name'])): ?>
                                        · <span><?= htmlspecialchars($slot['term_name']) ?></span>
                                    <?php endif; ?>
                                </p>
                            </div>
                            <!-- Period badge -->
                            <div class="shrink-0 self-center">
                                <span class="text-xs font-bold rounded-full px-2 py-1"
                                      style="background:<?= $color['bg'] ?>; color:<?= $color['text'] ?>">
                                    P<?= $i + 1 ?>
                                </span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>

    <?php endif; // end main content block ?>

    <!-- Back button -->
    <div class="text-center mt-6 no-print">
        <a href="/student/dashboard.php"
           class="inline-block bg-orange-500 hover:bg-orange-600 text-white font-semibold
                  py-3 px-8 rounded-full transition shadow-md">
            <i class="fas fa-arrow-left mr-2"></i>Back to Dashboard
        </a>
    </div>

</main>

<?php
$footer = $_SERVER['DOCUMENT_ROOT'] . '/templates/student_footer.php';
if (file_exists($footer)) include $footer;
?>

<script>
function switchTab(tab) {
    document.getElementById('tab-grid').classList.toggle('hidden', tab !== 'grid');
    document.getElementById('tab-list').classList.toggle('hidden', tab !== 'list');
    document.querySelectorAll('.tab-btn').forEach((btn, i) => {
        const active = (tab === 'grid' && i === 0) || (tab === 'list' && i === 1);
        btn.classList.toggle('active', active);
        btn.classList.toggle('text-orange-600', active);
        btn.classList.toggle('border-orange-400', active);
        btn.classList.toggle('text-gray-600', !active);
        btn.classList.toggle('border-gray-300', !active);
    });
}
</script>

</body>
</html>