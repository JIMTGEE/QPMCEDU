<?php
ob_start();
if (session_status() === PHP_SESSION_NONE) session_start();

// ─── Auth Guard ────────────────────────────────────────────────────────────────
if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_role']) ||
    !in_array($_SESSION['user_role'], ['teacher', 'admin'])) {
    error_log("Unauthorized access: user_id=" . ($_SESSION['user_id'] ?? 'unset') .
              ", role=" . ($_SESSION['user_role'] ?? 'unset'));
    $_SESSION['error_msg'] = "Please log in as a teacher.";
    header("Location: /login.php");
    exit();
}

ini_set('log_errors', 1);
ini_set('error_log', dirname(__FILE__) . '/error.log');

// ─── Database ──────────────────────────────────────────────────────────────────
$db_path = $_SERVER['DOCUMENT_ROOT'] . '/config/db.php';
if (!file_exists($db_path)) {
    error_log("Database config missing: $db_path");
    $_SESSION['error_msg'] = "System error: Server configuration error.";
    header("Location: /login.php"); exit();
}
require_once($db_path);

// ─── Logging ──────────────────────────────────────────────────────────────────
$log_path = $_SERVER['DOCUMENT_ROOT'] . '/functions/log_activity.php';
if (!file_exists($log_path)) {
    function logActivity($conn, $user_id, $action, $description) {
        error_log("Activity: [$user_id] $action - $description");
    }
} else {
    require_once($log_path);
}

if (!isset($conn) || !$conn || mysqli_connect_error()) {
    error_log("DB connection failed: " . (mysqli_connect_error() ?: "Unknown"));
    $_SESSION['error_msg'] = "System error: Database connection failed.";
    header("Location: /login.php"); exit();
}

// ─── Session user ─────────────────────────────────────────────────────────────
$teacher_id = (int)$_SESSION['user_id'];
$user_check = $conn->prepare("SELECT id FROM users WHERE id = ? AND role IN ('teacher','admin')");
$user_check->bind_param("i", $teacher_id);
$user_check->execute();
if ($user_check->get_result()->num_rows === 0) {
    error_log("Invalid teacher_id in session: $teacher_id");
    $_SESSION['error_msg'] = "Invalid user session.";
    header("Location: /login.php"); exit();
}
$user_check->close();

logActivity($conn, $teacher_id, "Accessed Manage Resources Page", "Teacher accessed manage resources page");

// ─── CSRF token ───────────────────────────────────────────────────────────────
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// ─── Handle POST actions: delete / publish / unpublish ────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $conn->begin_transaction();
    try {
        if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
            throw new Exception("Invalid CSRF token.");
        }

        $action      = trim(filter_input(INPUT_POST, 'action', FILTER_SANITIZE_SPECIAL_CHARS) ?? '');
        $resource_id = filter_input(INPUT_POST, 'resource_id', FILTER_VALIDATE_INT);
        if (!$resource_id) throw new Exception("Invalid resource ID.");

        // Ownership check
        $verify = $conn->prepare("SELECT id FROM learning_resources WHERE id = ? AND teacher_id = ?");
        $verify->bind_param("ii", $resource_id, $teacher_id);
        $verify->execute();
        if ($verify->get_result()->num_rows === 0) {
            throw new Exception("You do not have permission to modify this resource.");
        }
        $verify->close();

        if ($action === 'delete') {
            // Remove related feedback first
            $del_fb = $conn->prepare("DELETE FROM resource_feedback WHERE resource_id = ?");
            $del_fb->bind_param("i", $resource_id);
            $del_fb->execute();
            $del_fb->close();

            $del = $conn->prepare("DELETE FROM learning_resources WHERE id = ?");
            $del->bind_param("i", $resource_id);
            $del->execute();
            $del->close();

            logActivity($conn, $teacher_id, "Deleted Resource", "Resource ID $resource_id deleted");
            $_SESSION['success_msg'] = "Resource deleted successfully.";

        } elseif ($action === 'publish' || $action === 'unpublish') {
            $is_approved = ($action === 'publish') ? 1 : 0;
            $upd = $conn->prepare(
                "UPDATE learning_resources SET is_approved = ?, approved_by = ?, approved_at = NOW() WHERE id = ?"
            );
            $upd->bind_param("iii", $is_approved, $teacher_id, $resource_id);
            $upd->execute();
            $upd->close();

            logActivity($conn, $teacher_id, "Updated Resource Status",
                "Resource ID $resource_id " . ($is_approved ? 'published' : 'unpublished'));
            $_SESSION['success_msg'] = "Resource " . ($is_approved ? 'published' : 'unpublished') . " successfully.";

        } else {
            throw new Exception("Invalid action specified.");
        }

        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        $conn->commit();
        header("Location: /teacher/manage_resources.php");
        exit();

    } catch (Exception $e) {
        $conn->rollback();
        error_log("Action error: " . $e->getMessage());
        $_SESSION['error_msg'] = $e->getMessage();
    }
}

// ─── Pagination & filters ─────────────────────────────────────────────────────
$page         = max(1, (int)filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT, ['options' => ['default' => 1, 'min_range' => 1]]));
$per_page     = 10;
$offset       = ($page - 1) * $per_page;
$search       = trim(filter_input(INPUT_GET, 'search', FILTER_SANITIZE_SPECIAL_CHARS) ?? '');
$status_filter= trim(filter_input(INPUT_GET, 'status', FILTER_SANITIZE_SPECIAL_CHARS) ?? '');
$class_filter = (int)(filter_input(INPUT_GET, 'class_id', FILTER_VALIDATE_INT) ?: 0);
$sort_by      = trim(filter_input(INPUT_GET, 'sort_by', FILTER_SANITIZE_SPECIAL_CHARS) ?? 'created_at');
$sort_order   = strtoupper(trim(filter_input(INPUT_GET, 'sort_order', FILTER_SANITIZE_SPECIAL_CHARS) ?? 'DESC'));

$valid_sort = ['title','created_at','resource_type','is_approved','rating'];
$sort_by    = in_array($sort_by, $valid_sort) ? $sort_by : 'created_at';
$sort_order = $sort_order === 'ASC' ? 'ASC' : 'DESC';

$where_conditions = ["lr.teacher_id = ?"];
$params = [$teacher_id];
$types  = "i";

if ($search !== '') {
    $where_conditions[] = "(lr.title LIKE ? OR lr.description LIKE ? OR lr.tags LIKE ?)";
    $sp = "%$search%";
    $params[] = $sp; $params[] = $sp; $params[] = $sp;
    $types .= "sss";
}
if ($status_filter && in_array($status_filter, ['published','unpublished'])) {
    $is_ap = $status_filter === 'published' ? 1 : 0;
    $where_conditions[] = "lr.is_approved = ?";
    $params[] = $is_ap; $types .= "i";
}
if ($class_filter > 0) {
    $where_conditions[] = "lr.class_id = ?";
    $params[] = $class_filter; $types .= "i";
}

$where_clause = implode(" AND ", $where_conditions);

// Total count
$count_stmt = $conn->prepare("SELECT COUNT(*) as total FROM learning_resources lr WHERE $where_clause");
$count_stmt->bind_param($types, ...$params);
$count_stmt->execute();
$total_resources = (int)$count_stmt->get_result()->fetch_assoc()['total'];
$count_stmt->close();
$total_pages = max(1, (int)ceil($total_resources / $per_page));

// Resources query
$res_sql = "SELECT lr.id, lr.title, lr.description, lr.resource_type, lr.is_approved,
                   lr.file_path, lr.url, lr.created_at, lr.views_count, lr.downloads_count,
                   lr.rating, lr.rating_count, lr.ghanaian_content, lr.access_level,
                   lr.tags,
                   c.class_name, s.subject_name
            FROM learning_resources lr
            LEFT JOIN classes c  ON lr.class_id   = c.id
            LEFT JOIN subjects s ON lr.subject_id  = s.id
            WHERE $where_clause
            ORDER BY lr.$sort_by $sort_order
            LIMIT ? OFFSET ?";
$params[] = $per_page; $params[] = $offset; $types .= "ii";

$res_stmt = $conn->prepare($res_sql);
if (!$res_stmt) {
    error_log("Resources query prepare failed: " . $conn->error);
    $_SESSION['error_msg'] = "System error: Unable to fetch resources.";
    header("Location: /teacher/dashboard.php"); exit();
}
$res_stmt->bind_param($types, ...$params);
$res_stmt->execute();
$resources = $res_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$res_stmt->close();

// Classes for filter
$cls_stmt = $conn->prepare(
    "SELECT DISTINCT c.id, c.class_name
     FROM classes c
     LEFT JOIN class_schedules cs ON c.id = cs.class_id
     WHERE c.teacher_id = ? OR cs.teacher_id = ?
     ORDER BY c.class_name"
);
$cls_stmt->bind_param("ii", $teacher_id, $teacher_id);
$cls_stmt->execute();
$classes = $cls_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$cls_stmt->close();

function h(string $val): string {
    return htmlspecialchars($val, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Manage learning resources for QueensPa School.">
    <meta name="robots" content="noindex, nofollow">
    <title>Manage Resources – QueensPa School</title>

    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css"
          integrity="sha512-Fo3rlrZj/k7ujTnHg4CGR2D7kSs0v4LLanw2qksYuRlEzO+tcaEPQogQ0KaoGN26/zrn20ImR1DfuLWnOo7aBA==" crossorigin="anonymous">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"
            integrity="sha256-/xUj+3OJU5yExlq6GSYGSHk7tPXikynS7ogEvDej/m4=" crossorigin="anonymous"></script>

    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: { 'sans': ['Poppins','ui-sans-serif','system-ui'] },
                    colors: {
                        primary: { 500:'#0288d1', 600:'#0270a8' },
                        accent:  { 500:'#4caf50', 600:'#43a047' },
                    },
                },
            },
        };
    </script>

    <style>
        body { font-family:'Poppins',sans-serif; line-height:1.6; }
        .table-row:hover { background-color:#f1f5f9; transition:background-color 0.2s; }
        /* Success popup */
        #toast-container {
            position:fixed; top:1.5rem; right:1.5rem; z-index:9999;
            display:flex; flex-direction:column; gap:.5rem; pointer-events:none;
        }
        .toast-msg {
            background:#1b5e20; color:#fff; border-radius:10px;
            padding:.85rem 1.2rem; font-size:.88rem; font-weight:500;
            display:flex; align-items:center; gap:.6rem;
            box-shadow:0 4px 20px rgba(0,0,0,.18);
            animation: toastIn .35s ease, toastOut .4s ease 3.6s forwards;
            pointer-events:auto;
        }
        .toast-msg.is-error { background:#b71c1c; }
        @keyframes toastIn  { from{opacity:0;transform:translateX(60px)} to{opacity:1;transform:none} }
        @keyframes toastOut { from{opacity:1;transform:none} to{opacity:0;transform:translateX(60px)} }
        /* Modal */
        .modal-overlay {
            position:fixed; inset:0; background:rgba(0,0,0,.55); z-index:50;
            display:flex; align-items:center; justify-content:center;
        }
        .modal-box {
            background:#fff; border-radius:12px; box-shadow:0 8px 40px rgba(0,0,0,.18);
            width:100%; max-width:680px; max-height:88vh; overflow-y:auto; padding:0;
        }
        .form-field {
            width:100%; padding:.55rem .8rem; border:1.5px solid #d1d5db;
            border-radius:8px; font-size:.88rem; outline:none;
            transition:border-color .2s, box-shadow .2s;
        }
        .form-field:focus { border-color:#0288d1; box-shadow:0 0 0 3px rgba(2,136,209,.12); }
    </style>
</head>
<body class="min-h-screen bg-gray-50">

<!-- ── Toast container ────────────────────────────────────────────────────── -->
<div id="toast-container"></div>

<?php
// Show PHP-session flash messages as toasts via inline JS
$flash_success = $_SESSION['success_msg'] ?? '';
$flash_error   = $_SESSION['error_msg']   ?? '';
unset($_SESSION['success_msg'], $_SESSION['error_msg']);
?>
<?php if ($flash_success || $flash_error): ?>
<script>
document.addEventListener('DOMContentLoaded', () => {
    <?php if ($flash_success): ?>
    showToast(<?= json_encode($flash_success) ?>);
    <?php endif; ?>
    <?php if ($flash_error): ?>
    showToast(<?= json_encode($flash_error) ?>, true);
    <?php endif; ?>
});
</script>
<?php endif; ?>

<!-- ── Edit Resource Modal ────────────────────────────────────────────────── -->
<div id="edit-modal" class="modal-overlay" style="display:none" role="dialog" aria-modal="true" aria-labelledby="edit-modal-title">
    <div class="modal-box">
        <div class="bg-blue-600 text-white px-6 py-4 rounded-t-xl flex justify-between items-center">
            <h3 id="edit-modal-title" class="text-lg font-semibold">Edit Resource</h3>
            <button id="close-edit-modal" class="text-white hover:text-gray-200 text-xl leading-none" aria-label="Close">&times;</button>
        </div>
        <form id="edit-form" action="/teacher/create_resource.php" method="POST"
              enctype="multipart/form-data" class="p-6 space-y-4" novalidate>
            <input type="hidden" name="csrf_token" value="<?= h($csrf_token) ?>">
            <input type="hidden" name="action"      value="edit">
            <input type="hidden" name="resource_id" id="edit_resource_id">

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div class="md:col-span-2">
                    <label for="edit_title" class="block text-sm font-semibold text-gray-700 mb-1">
                        Title <span class="text-red-500">*</span>
                    </label>
                    <input type="text" id="edit_title" name="title" class="form-field" required maxlength="255">
                </div>
                <div class="md:col-span-2">
                    <label for="edit_description" class="block text-sm font-semibold text-gray-700 mb-1">Description</label>
                    <textarea id="edit_description" name="description" rows="3" class="form-field" maxlength="2000"></textarea>
                </div>
                <div>
                    <label for="edit_resource_type" class="block text-sm font-semibold text-gray-700 mb-1">
                        Resource Type <span class="text-red-500">*</span>
                    </label>
                    <select id="edit_resource_type" name="resource_type" class="form-field" required>
                        <option value="">Select Type</option>
                        <?php foreach (['document','pdf','video','audio','link','interactive','image','worksheet','presentation','quiz'] as $t): ?>
                        <option value="<?= h($t) ?>"><?= h(ucfirst($t)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label for="edit_access_level" class="block text-sm font-semibold text-gray-700 mb-1">Access Level</label>
                    <select id="edit_access_level" name="access_level" class="form-field">
                        <option value="class">Class Only</option>
                        <option value="public">Public</option>
                        <option value="private">Private (Me only)</option>
                    </select>
                </div>
                <div class="md:col-span-2">
                    <label for="edit_url" class="block text-sm font-semibold text-gray-700 mb-1">URL (optional)</label>
                    <input type="url" id="edit_url" name="url" class="form-field" placeholder="https://example.com/resource">
                </div>
                <div class="md:col-span-2">
                    <label for="edit_file" class="block text-sm font-semibold text-gray-700 mb-1">
                        Replace File <span class="text-xs text-gray-400">(optional, max 20 MB)</span>
                    </label>
                    <input type="file" id="edit_file" name="file" class="form-field"
                           accept=".pdf,.doc,.docx,.ppt,.pptx,.xls,.xlsx,.jpg,.jpeg,.png,.gif,.webp,.mp4,.webm,.mp3,.ogg,.wav,.txt">
                </div>
                <div class="md:col-span-2">
                    <label for="edit_tags" class="block text-sm font-semibold text-gray-700 mb-1">Tags</label>
                    <input type="text" id="edit_tags" name="tags" class="form-field" placeholder="comma-separated tags">
                </div>
                <div class="md:col-span-2 flex items-center gap-3">
                    <input type="checkbox" id="edit_ghanaian" name="ghanaian_content" value="1"
                           class="h-4 w-4 text-blue-500 border-gray-300 rounded cursor-pointer">
                    <label for="edit_ghanaian" class="text-sm text-gray-700 cursor-pointer">
                        🇬🇭 Contains Ghanaian Curriculum Content
                    </label>
                </div>
            </div>

            <div class="flex justify-end gap-3 pt-2 border-t border-gray-100">
                <button type="button" id="cancel-edit-modal"
                        class="bg-gray-100 hover:bg-gray-200 text-gray-700 px-5 py-2 rounded-lg text-sm font-semibold transition">
                    Cancel
                </button>
                <button type="submit" id="edit-submit-btn"
                        class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-2 rounded-lg text-sm font-semibold transition flex items-center gap-2">
                    <i class="fas fa-save"></i> Save Changes
                </button>
            </div>
        </form>
    </div>
</div>

<?php include $_SERVER['DOCUMENT_ROOT'] . '/templates/teacher_header.php'; ?>

<div class="container mx-auto px-4 py-10 max-w-7xl">

    <!-- Page header -->
    <div class="flex flex-col sm:flex-row justify-between items-center mb-8">
        <h1 class="text-3xl font-bold text-gray-800 flex items-center gap-3">
            <i class="fas fa-book-open text-blue-500"></i> Manage Resources
        </h1>
        <a href="/teacher/create_resource.php"
           class="mt-4 sm:mt-0 bg-green-500 hover:bg-green-600 text-white px-5 py-2 rounded-lg text-sm font-semibold transition flex items-center gap-2">
            <i class="fas fa-plus"></i> Create New Resource
        </a>
    </div>

    <!-- Filters -->
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-5 mb-6">
        <form id="filter-form" method="GET" class="flex flex-wrap gap-3 items-end">
            <div class="flex-1 min-w-[180px]">
                <label for="search" class="block text-xs font-semibold text-gray-500 uppercase tracking-wider mb-1">Search</label>
                <input type="text" id="search" name="search" class="form-field"
                       placeholder="Title, description, tags…"
                       value="<?= h($search) ?>">
            </div>
            <div class="min-w-[140px]">
                <label for="status_filter" class="block text-xs font-semibold text-gray-500 uppercase tracking-wider mb-1">Status</label>
                <select id="status_filter" name="status" class="form-field">
                    <option value="">All Statuses</option>
                    <option value="published"   <?= $status_filter === 'published'   ? 'selected' : '' ?>>Published</option>
                    <option value="unpublished" <?= $status_filter === 'unpublished' ? 'selected' : '' ?>>Unpublished</option>
                </select>
            </div>
            <div class="min-w-[160px]">
                <label for="class_filter" class="block text-xs font-semibold text-gray-500 uppercase tracking-wider mb-1">Class</label>
                <select id="class_filter" name="class_id" class="form-field">
                    <option value="">All Classes</option>
                    <?php foreach ($classes as $cls): ?>
                    <option value="<?= h($cls['id']) ?>" <?= $class_filter == $cls['id'] ? 'selected' : '' ?>>
                        <?= h($cls['class_name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </form>
    </div>

    <!-- Resources table -->
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-100">
                <thead class="bg-gray-50">
                    <tr>
                        <?php
                        $cols = [
                            ['sort'=>'title',       'label'=>'Title'],
                            ['sort'=>null,          'label'=>'Class'],
                            ['sort'=>null,          'label'=>'Subject'],
                            ['sort'=>'resource_type','label'=>'Type'],
                            ['sort'=>'rating',      'label'=>'Rating'],
                            ['sort'=>null,          'label'=>'Views'],
                            ['sort'=>null,          'label'=>'Downloads'],
                            ['sort'=>'created_at',  'label'=>'Created'],
                            ['sort'=>'is_approved', 'label'=>'Status'],
                            ['sort'=>null,          'label'=>'Actions'],
                        ];
                        foreach ($cols as $col):
                            $cls = $col['sort'] ? 'cursor-pointer hover:text-blue-600 select-none' : '';
                            $arrow = '';
                            if ($col['sort'] && $col['sort'] === $sort_by) {
                                $arrow = $sort_order === 'ASC' ? ' ↑' : ' ↓';
                            }
                        ?>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider <?= $cls ?>"
                            <?= $col['sort'] ? 'data-sort="' . $col['sort'] . '"' : '' ?>>
                            <?= h($col['label']) . $arrow ?>
                        </th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-100 text-sm text-gray-700">
                    <?php if (empty($resources)): ?>
                    <tr>
                        <td colspan="10" class="px-6 py-10 text-center text-gray-400">
                            <i class="fas fa-folder-open text-2xl mb-2 block"></i>
                            No resources found.
                        </td>
                    </tr>
                    <?php else: ?>
                    <?php foreach ($resources as $r): ?>
                    <tr class="table-row">
                        <td class="px-4 py-3 font-medium text-gray-900 max-w-[200px]">
                            <div class="truncate" title="<?= h($r['title']) ?>">
                                <?= h($r['title']) ?>
                                <?php if ($r['ghanaian_content']): ?><span class="ml-1" title="Ghanaian Content">🇬🇭</span><?php endif; ?>
                            </div>
                        </td>
                        <td class="px-4 py-3 text-gray-500"><?= h($r['class_name']   ?? 'N/A') ?></td>
                        <td class="px-4 py-3 text-gray-500"><?= h($r['subject_name'] ?? 'N/A') ?></td>
                        <td class="px-4 py-3">
                            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-blue-50 text-blue-700">
                                <?= h(ucfirst($r['resource_type'] ?? '')) ?>
                            </span>
                        </td>
                        <td class="px-4 py-3 text-gray-500">
                            <?= number_format((float)($r['rating'] ?? 0), 1) ?>
                            <span class="text-gray-400 text-xs">(<?= (int)($r['rating_count'] ?? 0) ?>)</span>
                        </td>
                        <td class="px-4 py-3 text-gray-500"><?= (int)($r['views_count']     ?? 0) ?></td>
                        <td class="px-4 py-3 text-gray-500"><?= (int)($r['downloads_count'] ?? 0) ?></td>
                        <td class="px-4 py-3 text-gray-400 text-xs"><?= date('d M Y', strtotime($r['created_at'] ?? 'now')) ?></td>
                        <td class="px-4 py-3">
                            <?php if ($r['is_approved']): ?>
                            <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">
                                <i class="fas fa-check-circle text-xs"></i> Published
                            </span>
                            <?php else: ?>
                            <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium bg-yellow-100 text-yellow-800">
                                <i class="fas fa-clock text-xs"></i> Unpublished
                            </span>
                            <?php endif; ?>
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex items-center gap-2">
                                <?php $viewUrl = !empty($r['file_path']) ? $r['file_path'] : ($r['url'] ?? ''); ?>
                                <?php if ($viewUrl): ?>
                                <a href="<?= h($viewUrl) ?>" target="_blank" rel="noopener noreferrer"
                                   class="text-blue-500 hover:text-blue-700 transition" title="View Resource">
                                    <i class="fas fa-eye"></i>
                                </a>
                                <?php endif; ?>

                                <!-- Edit button — opens modal -->
                                <button type="button"
                                        class="edit-btn text-indigo-500 hover:text-indigo-700 transition"
                                        title="Edit Resource"
                                        data-id="<?=           h($r['id']            ?? '') ?>"
                                        data-title="<?=        h($r['title']         ?? '') ?>"
                                        data-description="<?= h($r['description']    ?? '') ?>"
                                        data-type="<?=         h($r['resource_type'] ?? '') ?>"
                                        data-url="<?=          h($r['url']           ?? '') ?>"
                                        data-tags="<?=         h($r['tags']          ?? '') ?>"
                                        data-access="<?=       h($r['access_level']  ?? 'class') ?>"
                                        data-ghanaian="<?=     (int)($r['ghanaian_content'] ?? 0) ?>">
                                    <i class="fas fa-edit"></i>
                                </button>

                                <!-- Publish / Unpublish -->
                                <form action="/teacher/manage_resources.php" method="POST" class="inline"
                                      onsubmit="return confirm('<?= $r['is_approved'] ? 'Unpublish' : 'Publish' ?> this resource?')">
                                    <input type="hidden" name="csrf_token"   value="<?= h($csrf_token) ?>">
                                    <input type="hidden" name="action"       value="<?= $r['is_approved'] ? 'unpublish' : 'publish' ?>">
                                    <input type="hidden" name="resource_id"  value="<?= h($r['id']) ?>">
                                    <button type="submit"
                                            class="<?= $r['is_approved'] ? 'text-orange-500 hover:text-orange-700' : 'text-green-500 hover:text-green-700' ?> transition"
                                            title="<?= $r['is_approved'] ? 'Unpublish' : 'Publish' ?>">
                                        <i class="fas fa-<?= $r['is_approved'] ? 'lock' : 'paper-plane' ?>"></i>
                                    </button>
                                </form>

                                <!-- Delete -->
                                <form action="/teacher/manage_resources.php" method="POST" class="inline"
                                      onsubmit="return confirm('Delete this resource? This cannot be undone.')">
                                    <input type="hidden" name="csrf_token"  value="<?= h($csrf_token) ?>">
                                    <input type="hidden" name="action"      value="delete">
                                    <input type="hidden" name="resource_id" value="<?= h($r['id']) ?>">
                                    <button type="submit" class="text-red-500 hover:text-red-700 transition" title="Delete Resource">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </form>
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
        <div class="flex justify-between items-center px-5 py-4 border-t border-gray-100 text-sm text-gray-500">
            <span>
                Showing <?= $offset + 1 ?>–<?= min($offset + $per_page, $total_resources) ?> of <?= $total_resources ?> resources
            </span>
            <div class="flex gap-1">
                <?php
                $qp = ['search' => $search, 'status' => $status_filter, 'class_id' => $class_filter, 'sort_by' => $sort_by, 'sort_order' => $sort_order];
                function pageUrl(int $p, array $q): string {
                    return '?' . http_build_query(array_merge($q, ['page' => $p]));
                }
                ?>
                <?php if ($page > 1): ?>
                <a href="<?= pageUrl($page - 1, $qp) ?>"
                   class="px-3 py-1 rounded-lg bg-blue-500 text-white hover:bg-blue-600 transition">
                    <i class="fas fa-chevron-left text-xs"></i>
                </a>
                <?php endif; ?>
                <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                <a href="<?= pageUrl($i, $qp) ?>"
                   class="px-3 py-1 rounded-lg <?= $i === $page ? 'bg-blue-500 text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200' ?> transition">
                    <?= $i ?>
                </a>
                <?php endfor; ?>
                <?php if ($page < $total_pages): ?>
                <a href="<?= pageUrl($page + 1, $qp) ?>"
                   class="px-3 py-1 rounded-lg bg-blue-500 text-white hover:bg-blue-600 transition">
                    <i class="fas fa-chevron-right text-xs"></i>
                </a>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php include $_SERVER['DOCUMENT_ROOT'] . '/templates/teacher_footer.php'; ?>

<script>
/* ── Toast helper ─────────────────────────────────────────────────────────── */
function showToast(msg, isError = false) {
    const tc = document.getElementById('toast-container');
    const el = document.createElement('div');
    el.className = 'toast-msg' + (isError ? ' is-error' : '');
    el.innerHTML = `<i class="fas fa-${isError ? 'exclamation-circle' : 'check-circle'}"></i> ${msg}`;
    tc.appendChild(el);
    setTimeout(() => el.remove(), 4100);
}

/* ── Filter form: debounced auto-submit ───────────────────────────────────── */
let filterTimeout;
['search','status_filter','class_filter'].forEach(id => {
    const el = document.getElementById(id);
    if (!el) return;
    el.addEventListener(el.tagName === 'INPUT' ? 'input' : 'change', () => {
        clearTimeout(filterTimeout);
        filterTimeout = setTimeout(() => document.getElementById('filter-form').submit(), 350);
    });
});

/* ── Sort columns ─────────────────────────────────────────────────────────── */
document.querySelectorAll('th[data-sort]').forEach(th => {
    th.addEventListener('click', () => {
        const sortBy     = th.dataset.sort;
        const currentSB  = <?= json_encode($sort_by) ?>;
        const currentSO  = <?= json_encode($sort_order) ?>;
        const sortOrder  = (sortBy === currentSB && currentSO === 'ASC') ? 'DESC' : 'ASC';
        const params = new URLSearchParams({
            search:     <?= json_encode($search) ?>,
            status:     <?= json_encode($status_filter) ?>,
            class_id:   <?= json_encode((string)$class_filter) ?>,
            sort_by:    sortBy,
            sort_order: sortOrder,
        });
        window.location.href = '?' + params.toString();
    });
});

/* ── Edit modal ───────────────────────────────────────────────────────────── */
const editModal    = document.getElementById('edit-modal');
const editForm     = document.getElementById('edit-form');
const closeEditBtn = document.getElementById('close-edit-modal');
const cancelEditBtn= document.getElementById('cancel-edit-modal');

function openEditModal(btn) {
    document.getElementById('edit_resource_id').value   = btn.dataset.id;
    document.getElementById('edit_title').value         = btn.dataset.title;
    document.getElementById('edit_description').value   = btn.dataset.description;
    document.getElementById('edit_resource_type').value = btn.dataset.type;
    document.getElementById('edit_url').value           = btn.dataset.url;
    document.getElementById('edit_tags').value          = btn.dataset.tags;
    document.getElementById('edit_access_level').value  = btn.dataset.access;
    document.getElementById('edit_ghanaian').checked    = btn.dataset.ghanaian === '1';
    editModal.style.display = 'flex';
    document.getElementById('edit_title').focus();
}

function closeEditModal() { editModal.style.display = 'none'; }

document.querySelectorAll('.edit-btn').forEach(btn => {
    btn.addEventListener('click', () => openEditModal(btn));
});
closeEditBtn.addEventListener('click', closeEditModal);
cancelEditBtn.addEventListener('click', closeEditModal);
editModal.addEventListener('click', e => { if (e.target === editModal) closeEditModal(); });

// Prevent double-submit & show spinner on edit form
editForm.addEventListener('submit', function (e) {
    const titleVal = document.getElementById('edit_title').value.trim();
    const typeVal  = document.getElementById('edit_resource_type').value;
    if (!titleVal || !typeVal) {
        e.preventDefault();
        showToast('Please fill in all required fields.', true);
        return;
    }
    const submitBtn = document.getElementById('edit-submit-btn');
    submitBtn.disabled = true;
    submitBtn.innerHTML = '<i class="fas fa-circle-notch fa-spin"></i> Saving…';
});

// Keyboard close
document.addEventListener('keydown', e => {
    if (e.key === 'Escape' && editModal.style.display !== 'none') closeEditModal();
});
</script>
</body>
</html>
<?php
mysqli_close($conn);
ob_end_flush();
?>