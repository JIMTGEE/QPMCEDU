<?php
session_start();
require_once($_SERVER['DOCUMENT_ROOT'] . '/config/db.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/functions/log_activity.php');

// Verify user is logged in and is a student
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'student') {
    header("Location: /login.php?error=unauthorized");
    exit;
}

$user_id = $_SESSION['user_id'];
$academic_year = $_SESSION['academic_year'] ?? '2024/2025';
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$per_page = 6;
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$type_filter = isset($_GET['type']) ? $_GET['type'] : 'all';
$access_filter = isset($_GET['access']) ? $_GET['access'] : 'all';
$class_filter = isset($_GET['class_id']) ? intval($_GET['class_id']) : 0;
$sort = isset($_GET['sort']) ? $_GET['sort'] : 'created_at_desc';

// Validate filters
$valid_types = ['all', 'document', 'video', 'audio', 'link', 'interactive', 'image'];
$valid_access = ['all', 'public', 'class'];
$valid_sorts = ['title_asc', 'title_desc', 'created_at_asc', 'created_at_desc', 'updated_at_asc', 'updated_at_desc'];
if (!in_array($type_filter, $valid_types)) $type_filter = 'all';
if (!in_array($access_filter, $valid_access)) $access_filter = 'all';
if (!in_array($sort, $valid_sorts)) $sort = 'created_at_desc';

// Map sort to SQL
$sort_map = [
    'title_asc' => 'lr.title ASC',
    'title_desc' => 'lr.title DESC',
    'created_at_asc' => 'lr.created_at ASC',
    'created_at_desc' => 'lr.created_at DESC',
    'updated_at_asc' => 'lr.updated_at ASC',
    'updated_at_desc' => 'lr.updated_at DESC'
];

// Fetch student’s classes for class filter
$stmt = $conn->prepare("SELECT c.id, c.class_name FROM classes c JOIN student_classes sc ON c.id = sc.class_id WHERE sc.user_id = ? AND sc.academic_year = ?");
$stmt->bind_param("is", $user_id, $academic_year);
$stmt->execute();
$classes = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Cache resource count
if (!isset($_SESSION['resource_count']) || $_SESSION['resource_count']['timestamp'] < time() - 300) {
    $query = "SELECT COUNT(*) as count FROM learning_resources lr
              WHERE lr.access_level IN ('public', 'class')";
    $params = [];
    $types = '';
    if ($access_filter !== 'all') {
        $query .= " AND lr.access_level = ?";
        $params[] = $access_filter;
        $types .= 's';
    }
    if ($type_filter !== 'all') {
        $query .= " AND lr.resource_type = ?";
        $params[] = $type_filter;
        $types .= 's';
    }
    if ($access_filter === 'class' || $class_filter > 0) {
        $query .= " AND lr.class_id IN (SELECT class_id FROM student_classes WHERE user_id = ? AND academic_year = ?)";
        $params[] = $user_id;
        $params[] = $academic_year;
        $types .= 'is';
    }
    if ($class_filter > 0) {
        $query .= " AND lr.class_id = ?";
        $params[] = $class_filter;
        $types .= 'i';
    }
    if ($search) {
        $query .= " AND (lr.title LIKE ? OR lr.description LIKE ?)";
        $search_param = "%$search%";
        $params[] = $search_param;
        $params[] = $search_param;
        $types .= 'ss';
    }
    $stmt = $conn->prepare($query);
    if ($params) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $total_resources = $stmt->get_result()->fetch_assoc()['count'];
    $stmt->close();
    $_SESSION['resource_count'] = ['count' => $total_resources, 'timestamp' => time()];
} else {
    $total_resources = $_SESSION['resource_count']['count'];
}

// Fetch student details
$stmt = $conn->prepare("SELECT name, profile_picture FROM users WHERE id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

// Generate CSRF token
$csrf_token = bin2hex(random_bytes(32));
$stmt = $conn->prepare("INSERT INTO csrf_tokens (user_id, token, created_at, expires_at) VALUES (?, ?, NOW(), NOW() + INTERVAL 1 HOUR)");
$stmt->bind_param("is", $user_id, $csrf_token);
$stmt->execute();
$stmt->close();

// Store recently viewed resources in session
if (!isset($_SESSION['recent_resources'])) {
    $_SESSION['recent_resources'] = [];
}

// Log activity
logActivity($conn, $user_id, "Accessed Learning Resources", "Student accessed learning resources page");
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Learning Resources - QueensPa School Management System</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link href="https://www.w3schools.com/w3css/4/w3.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/js-confetti@0.12.0/dist/js-confetti.browser.js" defer></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js" defer></script>
    <style>
        :root {
            --primary-color: #ff4500;
            --secondary-color: #ff6b00;
            --accent-color: #38a169;
            --accent-blue: #1e90ff;
            --text-color: #333;
            --bg-color: #f4f4f4;
        }
        body {
            font-family: 'Inter', sans-serif;
            background-color: var(--bg-color);
            color: var(--text-color);
        }
        .gradient-header {
            background: linear-gradient(to right, var(--primary-color), var(--secondary-color));
        }
        .card {
            transition: transform 0.3s ease, box-shadow 0.3s ease;
            @apply w3-card w3-hover-shadow rounded-lg;
        }
        .card:hover {
            transform: translateY(-5px);
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.1);
        }
        .bounce { animation: bounce 1s ease-in-out; }
        .pulse { animation: pulse 1.5s infinite; }
        .spin { animation: spin 2s linear infinite; }
        @keyframes bounce {
            0%, 20%, 50%, 80%, 100% { transform: translateY(0); }
            40% { transform: translateY(-10px); }
            60% { transform: translateY(-5px); }
        }
        @keyframes pulse {
            0% { transform: scale(1); }
            50% { transform: scale(1.05); }
            100% { transform: scale(1); }
        }
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        .w3-table th, .w3-table td {
            @apply p-3;
        }
        .dark {
            --bg-color: #1a202c;
            --text-color: #e2e8f0;
            @apply bg-gray-900 text-white;
        }
        .dark .card { @apply bg-gray-800 text-white; }
        .dark .gradient-header { @apply bg-gradient-to-r from-orange-600 to-red-600; }
        .modal-content { @apply rounded-lg shadow-lg; }
        .modal-body iframe, .modal-body embed, .modal-body video, .modal-body audio {
            max-width: 100%;
            width: 100%;
            height: 400px;
            border: none;
            @apply rounded-lg;
        }
        .modal-body img {
            max-width: 100%;
            height: auto;
            border-radius: 8px;
        }
        .modal-body audio { height: 50px; }
        .modal-body .loading-spinner {
            display: flex;
            justify-content: center;
            align-items: center;
            height: 200px;
        }
        .modal-body .loading-spinner i { @apply text-4xl text-primary animate-spin; }
        .modal-body .error-message { @apply text-red-500 font-semibold mt-2; }
        .external-link-btn, .access-resource-btn {
            @apply btn btn-primary btn-lg mt-4 w-full sm:w-auto px-6 py-3 text-white font-bold rounded-lg w3-hover-opacity transform hover:scale-105 transition-transform;
            background-color: var(--accent-blue);
        }
        .external-link-btn:hover, .access-resource-btn:hover {
            background-color: #1c86ee;
        }
        .modal-footer .btn-secondary {
            @apply px-6 py-3 rounded-lg w3-hover-opacity transform hover:scale-105 transition-transform;
        }
        @media print {
            .no-print { display: none; }
            .print-section { display: block !important; }
        }
        @media (max-width: 640px) {
            .w3-table { @apply w3-responsive; }
            .card { @apply p-2; }
            h1 { @apply text-2xl; }
            h2 { @apply text-xl; }
            .modal-dialog { @apply w-11/12; }
            .modal-body iframe, .modal-body embed, .modal-body video { height: 200px; }
            .modal-body audio { height: 50px; }
            .modal-body img { max-height: 300px; }
            .external-link-btn, .access-resource-btn { @apply w-full; }
        }
        .notification-bell { @apply relative; }
        .notification-bell .badge { @apply absolute top-0 right-0 bg-red-500 text-white rounded-full px-2 text-xs; }
        .view-toggle button { @apply p-2 rounded-lg; }
        .view-toggle .active { @apply bg-primary text-white; }
    </style>
</head>
<body class="min-h-screen">
    <!-- Header -->
    <?php
    $header_path = $_SERVER['DOCUMENT_ROOT'] . '/templates/student_header.php';
    try {
        if (!file_exists($header_path)) {
            throw new Exception("Student header file missing: $header_path");
        }
        include $header_path;
    } catch (Exception $e) {
        error_log("Failed to include student_header.php: " . $e->getMessage());
        logActivity($conn, $user_id, "Error", "Failed to load student header template: " . $e->getMessage());
        $_SESSION['error_msg'] = "System error: Unable to load page header.";
        header("Location: /login.php");
        exit();
    }
    ?>

    <main class="container mx-auto p-4 sm:p-6">
        <!-- Welcome Section -->
        <section class="mb-8 bounce">
            <div class="gradient-header text-white p-6 rounded-lg shadow-lg w3-animate-zoom">
                <div class="flex flex-col sm:flex-row items-center">
                    <img src="<?php echo htmlspecialchars($user['profile_picture'] ?: '/images/default-avatar.png'); ?>" alt="Profile Picture" class="w-20 h-20 rounded-full mr-0 sm:mr-4 mb-4 sm:mb-0">
                    <div>
                        <h1 class="text-3xl sm:text-4xl font-bold">Explore Learning Resources, <?php echo htmlspecialchars($user['name']); ?>!</h1>
                        <p class="text-lg">Discover videos, documents, audio, links, images, and interactive resources to supercharge your learning!</p>
                    </div>
                </div>
            </div>
        </section>

        <!-- Search, Filter, Sort, and View Toggle -->
        <section class="mb-8 w3-container">
            <div class="flex flex-col sm:flex-row justify-between items-center gap-4">
                <input type="text" id="resourceSearch" class="p-2 border rounded-lg w-full sm:w-1/3 focus:ring-2 focus:ring-primary" placeholder="Search resources..." aria-label="Search resources" value="<?php echo htmlspecialchars($search); ?>">
                <div class="flex flex-col sm:flex-row gap-4 w-full sm:w-auto">
                    <select id="typeFilter" class="p-2 border rounded-lg w-full sm:w-auto focus:ring-2 focus:ring-primary" aria-label="Filter by resource type">
                        <option value="all" <?php echo $type_filter === 'all' ? 'selected' : ''; ?>>All Types</option>
                        <option value="document" <?php echo $type_filter === 'document' ? 'selected' : ''; ?>>Document</option>
                        <option value="video" <?php echo $type_filter === 'video' ? 'selected' : ''; ?>>Video</option>
                        <option value="audio" <?php echo $type_filter === 'audio' ? 'selected' : ''; ?>>Audio</option>
                        <option value="link" <?php echo $type_filter === 'link' ? 'selected' : ''; ?>>Link</option>
                        <option value="interactive" <?php echo $type_filter === 'interactive' ? 'selected' : ''; ?>>Interactive</option>
                        <option value="image" <?php echo $type_filter === 'image' ? 'selected' : ''; ?>>Image</option>
                    </select>
                    <select id="accessFilter" class="p-2 border rounded-lg w-full sm:w-auto focus:ring-2 focus:ring-primary" aria-label="Filter by access level">
                        <option value="all" <?php echo $access_filter === 'all' ? 'selected' : ''; ?>>All Access</option>
                        <option value="public" <?php echo $access_filter === 'public' ? 'selected' : ''; ?>>Public</option>
                        <option value="class" <?php echo $access_filter === 'class' ? 'selected' : ''; ?>>Class</option>
                    </select>
                    <select id="classFilter" class="p-2 border rounded-lg w-full sm:w-auto focus:ring-2 focus:ring-primary" aria-label="Filter by class">
                        <option value="0" <?php echo $class_filter === 0 ? 'selected' : ''; ?>>All Classes</option>
                        <?php foreach ($classes as $class): ?>
                            <option value="<?php echo $class['id']; ?>" <?php echo $class_filter === $class['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($class['class_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <select id="sortOptions" class="p-2 border rounded-lg w-full sm:w-auto focus:ring-2 focus:ring-primary" aria-label="Sort resources">
                        <option value="title_asc" <?php echo $sort === 'title_asc' ? 'selected' : ''; ?>>Title (A-Z)</option>
                        <option value="title_desc" <?php echo $sort === 'title_desc' ? 'selected' : ''; ?>>Title (Z-A)</option>
                        <option value="created_at_asc" <?php echo $sort === 'created_at_asc' ? 'selected' : ''; ?>>Oldest First</option>
                        <option value="created_at_desc" <?php echo $sort === 'created_at_desc' ? 'selected' : ''; ?>>Newest First</option>
                        <option value="updated_at_asc" <?php echo $sort === 'updated_at_asc' ? 'selected' : ''; ?>>Oldest Updated</option>
                        <option value="updated_at_desc" <?php echo $sort === 'updated_at_desc' ? 'selected' : ''; ?>>Recently Updated</option>
                    </select>
                </div>
                <div class="view-toggle flex gap-2 no-print">
                    <button id="gridView" class="p-2 rounded-lg <?php echo isset($_GET['view']) && $_GET['view'] === 'grid' ? 'active' : ''; ?>" aria-label="Grid view"><i class="fas fa-th"></i></button>
                    <button id="listView" class="p-2 rounded-lg <?php echo !isset($_GET['view']) || $_GET['view'] === 'list' ? 'active' : ''; ?>" aria-label="List view"><i class="fas fa-list"></i></button>
                </div>
            </div>
            <button class="mt-4 btn btn-primary no-print w3-hover-opacity transform hover:scale-105 transition-transform" onclick="printResources()">Print Resources</button>
        </section>

        <!-- Resources Display -->
        <section class="mb-8 w3-container">
            <h2 class="text-2xl font-bold mb-4"><i class="fas fa-book-open mr-2 text-primary"></i>Available Resources (<?php echo $total_resources; ?>)</h2>
            <div id="resourcesContainer" class="<?php echo isset($_GET['view']) && $_GET['view'] === 'grid' ? 'grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4' : 'space-y-4'; ?>">
                <?php
                $query = "SELECT lr.id, lr.title, lr.description, lr.url, lr.file_path, lr.resource_type, lr.access_level, lr.created_at, lr.updated_at, c.class_name
                         FROM learning_resources lr
                         LEFT JOIN classes c ON lr.class_id = c.id
                         WHERE lr.access_level IN ('public', 'class')";
                $params = [];
                $types = '';
                if ($access_filter !== 'all') {
                    $query .= " AND lr.access_level = ?";
                    $params[] = $access_filter;
                    $types .= 's';
                }
                if ($type_filter !== 'all') {
                    $query .= " AND lr.resource_type = ?";
                    $params[] = $type_filter;
                    $types .= 's';
                }
                if ($access_filter === 'class' || $class_filter > 0) {
                    $query .= " AND lr.class_id IN (SELECT class_id FROM student_classes WHERE user_id = ? AND academic_year = ?)";
                    $params[] = $user_id;
                    $params[] = $academic_year;
                    $types .= 'is';
                }
                if ($class_filter > 0) {
                    $query .= " AND lr.class_id = ?";
                    $params[] = $class_filter;
                    $types .= 'i';
                }
                if ($search) {
                    $query .= " AND (lr.title LIKE ? OR lr.description LIKE ?)";
                    $search_param = "%$search%";
                    $params[] = $search_param;
                    $params[] = $search_param;
                    $types .= 'ss';
                }
                $query .= " ORDER BY " . $sort_map[$sort] . " LIMIT ? OFFSET ?";
                $params[] = $per_page;
                $params[] = ($page - 1) * $per_page;
                $types .= 'ii';

                $stmt = $conn->prepare($query);
                if ($params) {
                    $stmt->bind_param($types, ...$params);
                }
                $stmt->execute();
                $result = $stmt->get_result();
                while ($row = $result->fetch_assoc()) {
                    $description = $row['description'] ? substr(htmlspecialchars($row['description']), 0, 100) . '...' : 'No description';
                    $icon = $row['resource_type'] === 'video' ? 'fa-video' : ($row['resource_type'] === 'document' ? 'fa-file-alt' : ($row['resource_type'] === 'audio' ? 'fa-music' : ($row['resource_type'] === 'interactive' ? 'fa-gamepad' : ($row['resource_type'] === 'image' ? 'fa-image' : 'fa-link'))));
                    $class_info = $row['access_level'] === 'class' && $row['class_name'] ? " (Class: " . htmlspecialchars($row['class_name']) . ")" : '';
                    // Determine resource URL: prefer file_path if available, else use url
                    $resource_url = $row['file_path'] && $row['file_path'] !== '' ? $row['file_path'] : $row['url'];
                    // Prepend server origin for file_path if it's a relative path
                    if ($resource_url && $row['file_path'] && !preg_match('/^https?:\/\//', $resource_url)) {
                        $resource_url = $_SERVER['REQUEST_SCHEME'] . '://' . $_SERVER['HTTP_HOST'] . (strpos($resource_url, '/') === 0 ? $resource_url : '/' . $resource_url);
                    }
                    // Store in recent resources
                    $resource_data = [
                        'id' => $row['id'],
                        'title' => $row['title'],
                        'description' => $row['description'],
                        'url' => $row['url'],
                        'file_path' => $row['file_path'],
                        'resource_type' => $row['resource_type'],
                        'access_level' => $row['access_level'],
                        'created_at' => $row['created_at'],
                        'updated_at' => $row['updated_at'],
                        'class_name' => $row['class_name']
                    ];
                    if (!in_array($row['id'], array_column($_SESSION['recent_resources'], 'id'))) {
                        array_unshift($_SESSION['recent_resources'], $resource_data);
                        $_SESSION['recent_resources'] = array_slice($_SESSION['recent_resources'], 0, 6);
                    }
                    if (isset($_GET['view']) && $_GET['view'] === 'grid') {
                        echo "<div class='card bg-white p-4 rounded-lg'>
                            <div class='flex items-center mb-2'>
                                <i class='fas $icon mr-2 text-primary'></i>
                                <h3 class='text-lg font-semibold'>" . htmlspecialchars($row['title']) . $class_info . "</h3>
                            </div>
                            <p>$description</p>
                            <p class='text-sm text-gray-500'>Created: " . htmlspecialchars($row['created_at']) . "</p>
                            <p class='text-sm text-gray-500'>Updated: " . htmlspecialchars($row['updated_at']) . "</p>";
                        if ($resource_url) {
                            echo "<a href='" . htmlspecialchars($resource_url) . "' class='text-primary hover:underline no-print focus:ring-2 focus:ring-primary' target='_blank' rel='noopener noreferrer' aria-label='Access resource {$row['title']}'>Access Resource</a>";
                        } else {
                            echo "<p class='text-red-500'>No resource URL available</p>";
                        }
                        echo "</div>";
                    } else {
                        echo "<div class='card bg-white p-4 rounded-lg flex flex-col sm:flex-row items-start sm:items-center justify-between'>
                            <div class='flex-1'>
                                <div class='flex items-center mb-2'>
                                    <i class='fas $icon mr-2 text-primary'></i>
                                    <h3 class='text-lg font-semibold'>" . htmlspecialchars($row['title']) . $class_info . "</h3>
                                </div>
                                <p>$description</p>
                                <p class='text-sm text-gray-500'>Created: " . htmlspecialchars($row['created_at']) . "</p>
                                <p class='text-sm text-gray-500'>Updated: " . htmlspecialchars($row['updated_at']) . "</p>
                            </div>";
                        if ($resource_url) {
                            echo "<a href='" . htmlspecialchars($resource_url) . "' class='text-primary hover:underline no-print mt-2 sm:mt-0 focus:ring-2 focus:ring-primary' target='_blank' rel='noopener noreferrer' aria-label='Access resource {$row['title']}'>Access Resource</a>";
                        } else {
                            echo "<p class='text-red-500'>No resource URL available</p>";
                        }
                        echo "</div>";
                    }
                }
                $stmt->close();
                ?>
            </div>
            <!-- Pagination -->
            <?php
            $pages = ceil($total_resources / $per_page);
            if ($pages > 1):
            ?>
            <nav class="mt-4 flex justify-center no-print">
                <ul class="pagination">
                    <?php for ($i = 1; $i <= $pages; $i++): ?>
                        <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>">
                            <a class="page-link" href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>&type=<?php echo urlencode($type_filter); ?>&access=<?php echo urlencode($access_filter); ?>&class_id=<?php echo $class_filter; ?>&sort=<?php echo urlencode($sort); ?>&view=<?php echo urlencode($_GET['view'] ?? 'list'); ?>" aria-current="<?php echo $i === $page ? 'page' : 'false'; ?>"><?php echo $i; ?></a>
                        </li>
                    <?php endfor; ?>
                </ul>
            </nav>
            <?php endif; ?>
        </section>

        <!-- Recently Viewed Resources -->
        <section class="mb-8 w3-container">
            <h2 class="text-2xl font-bold mb-4"><i class="fas fa-history mr-2 text-primary"></i>Recently Viewed Resources</h2>
            <div id="recentResources" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                <?php
                foreach ($_SESSION['recent_resources'] as $row) {
                    $description = $row['description'] ? substr(htmlspecialchars($row['description']), 0, 100) . '...' : 'No description';
                    $icon = $row['resource_type'] === 'video' ? 'fa-video' : ($row['resource_type'] === 'document' ? 'fa-file-alt' : ($row['resource_type'] === 'audio' ? 'fa-music' : ($row['resource_type'] === 'interactive' ? 'fa-gamepad' : ($row['resource_type'] === 'image' ? 'fa-image' : 'fa-link'))));
                    $class_info = $row['access_level'] === 'class' && $row['class_name'] ? " (Class: " . htmlspecialchars($row['class_name']) . ")" : '';
                    // Determine resource URL for recent resources
                    $resource_url = $row['file_path'] && $row['file_path'] !== '' ? $row['file_path'] : $row['url'];
                    if ($resource_url && $row['file_path'] && !preg_match('/^https?:\/\//', $resource_url)) {
                        $resource_url = $_SERVER['REQUEST_SCHEME'] . '://' . $_SERVER['HTTP_HOST'] . (strpos($resource_url, '/') === 0 ? $resource_url : '/' . $resource_url);
                    }
                    echo "<div class='card bg-white p-4 rounded-lg'>
                        <div class='flex items-center mb-2'>
                            <i class='fas $icon mr-2 text-primary'></i>
                            <h3 class='text-lg font-semibold'>" . htmlspecialchars($row['title']) . $class_info . "</h3>
                        </div>
                        <p>$description</p>
                        <p class='text-sm text-gray-500'>Created: " . htmlspecialchars($row['created_at']) . "</p>
                        <p class='text-sm text-gray-500'>Updated: " . htmlspecialchars($row['updated_at']) . "</p>";
                    if ($resource_url) {
                        echo "<a href='" . htmlspecialchars($row['url']) . "' class='text-primary hover:underline no-print mt-2 focus:ring-2 focus:ring-primary' target='_blank' rel='noopener noreferrer' aria-label='Access resource {$row['title']}'>Access Resource</a>";
                    } else {
                        echo "<p class='text-red-500'>No resource URL available</p>";
                    }
                    echo "</div>";
                }
                ?>
            </div>
        </section>

        <!-- Print-Friendly Resource List -->
        <section class="print-section hidden w3-container">
            <h2 class="text-2xl font-bold mb-4">Available Resources</h2>
            <div class="w3-responsive">
                <table class="w3-table w3-striped w3-bordered">
                    <thead class="gradient-header text-white">
                        <tr>
                            <th class="p-3">Title</th>
                            <th class="p-3">Type</th>
                            <th class="p-3">Class</th>
                            <th class="p-3">Created</th>
                            <th class="p-3">Updated</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $query = "SELECT lr.title, lr.resource_type, lr.created_at, lr.updated_at, c.class_name
                                 FROM learning_resources lr
                                 LEFT JOIN classes c ON lr.class_id = c.id
                                 WHERE lr.access_level IN ('public', 'class')";
                        $params = [];
                        $types = '';
                        if ($access_filter !== 'all') {
                            $query .= " AND lr.access_level = ?";
                            $params[] = $access_filter;
                            $types .= 's';
                        }
                        if ($type_filter !== 'all') {
                            $query .= " AND lr.resource_type = ?";
                            $params[] = $type_filter;
                            $types .= 's';
                        }
                        if ($access_filter === 'class' || $class_filter > 0) {
                            $query .= " AND lr.class_id IN (SELECT class_id FROM student_classes WHERE user_id = ? AND academic_year = ?)";
                            $params[] = $user_id;
                            $params[] = $academic_year;
                            $types .= 'is';
                        }
                        if ($class_filter > 0) {
                            $query .= " AND lr.class_id = ?";
                            $params[] = $class_filter;
                            $types .= 'i';
                        }
                        if ($search) {
                            $query .= " AND (lr.title LIKE ? OR lr.description LIKE ?)";
                            $search_param = "%$search%";
                            $params[] = $search_param;
                            $params[] = $search_param;
                            $types .= 'ss';
                        }
                        $query .= " ORDER BY " . $sort_map[$sort];
                        $stmt = $conn->prepare($query);
                        if ($params) {
                            $stmt->bind_param($types, ...$params);
                        }
                        $stmt->execute();
                        $result = $stmt->get_result();
                        while ($row = $result->fetch_assoc()) {
                            echo "<tr>
                                <td>" . htmlspecialchars($row['title']) . "</td>
                                <td>" . htmlspecialchars($row['resource_type']) . "</td>
                                <td>" . htmlspecialchars($row['class_name'] ?: 'N/A') . "</td>
                                <td>" . htmlspecialchars($row['created_at']) . "</td>
                                <td>" . htmlspecialchars($row['updated_at']) . "</td>
                            </tr>";
                        }
                        $stmt->close();
                        ?>
                    </tbody>
                </table>
            </div>
        </section>
    </main>

    <!-- Footer -->
    <footer class="gradient-header text-white p-6">
        <div class="container mx-auto text-center">
            <p class="mb-2">© 2025 QueensPa School. All rights reserved.</p>
            <div class="flex justify-center space-x-4 mb-2">
                <a href="https://facebook.com/profile" class="text-white hover:underline"><i class="fab fa-facebook mr-1"></i>Facebook Profile</a>
                <a href="https://facebook.com/page" class="text-white hover:underline"><i class="fab fa-facebook-square mr-1"></i>Facebook Page</a>
                <a href="https://twitter.com" class="text-white hover:underline"><i class="fab fa-twitter mr-1"></i>Twitter</a>
                <a href="https://instagram.com" class="text-white hover:underline"><i class="fab fa-instagram mr-1"></i>Instagram</a>
                <a href="https://linkedin.com" class="text-white hover:underline"><i class="fab fa-linkedin mr-1"></i>LinkedIn</a>
                <a href="https://youtube.com" class="text-white hover:underline"><i class="fab fa-youtube mr-1"></i>YouTube</a>
            </div>
            <p>Developed by Kadevelops | Contact: <a href="mailto:contact@kadevelops.com" class="hover:underline">Email</a> | <a href="https://wa.me/1234567890" class="hover:underline">WhatsApp</a></p>
        </div>
    </footer>

    <script>
        // Initialize Confetti
        const confetti = new JSConfetti();

        document.addEventListener('DOMContentLoaded', function() {
            // Search, Filter, and Sort
            const searchInput = document.getElementById('resourceSearch');
            const typeFilter = document.getElementById('typeFilter');
            const accessFilter = document.getElementById('accessFilter');
            const classFilter = document.getElementById('classFilter');
            const sortOptions = document.getElementById('sortOptions');
            const gridViewBtn = document.getElementById('gridView');
            const listViewBtn = document.getElementById('listView');

            function updateUrl() {
                const params = new URLSearchParams({
                    search: searchInput.value,
                    type: typeFilter.value,
                    access: accessFilter.value,
                    class_id: classFilter.value,
                    sort: sortOptions.value,
                    view: gridViewBtn.classList.contains('active') ? 'grid' : 'list',
                    page: 1
                });
                window.location.href = `?${params.toString()}`;
            }

            searchInput.addEventListener('input', () => setTimeout(updateUrl, 500));
            typeFilter.addEventListener('change', updateUrl);
            accessFilter.addEventListener('change', () => {
                classFilter.disabled = accessFilter.value !== 'class';
                updateUrl();
            });
            classFilter.addEventListener('change', updateUrl);
            sortOptions.addEventListener('change', updateUrl);

            // Enable/disable class filter based on access filter
            classFilter.disabled = accessFilter.value !== 'class';

            // View Toggle
            gridViewBtn.addEventListener('click', () => {
                gridViewBtn.classList.add('active');
                listViewBtn.classList.remove('active');
                document.getElementById('resourcesContainer').className = 'grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4';
                updateUrl();
            });
            listViewBtn.addEventListener('click', () => {
                listViewBtn.classList.add('active');
                gridViewBtn.classList.remove('active');
                document.getElementById('resourcesContainer').className = 'space-y-4';
                updateUrl();
            });

            // Print Resources
            window.printResources = function() {
                window.print();
            };

            // Dark Mode Toggle
            const darkModeToggle = document.createElement('button');
            darkModeToggle.innerHTML = '<i class="fas fa-moon spin"></i>';
            darkModeToggle.className = 'fixed bottom-4 right-4 bg-primary text-white p-2 rounded-full no-print w3-hover-opacity transform hover:scale-105 transition-transform';
            darkModeToggle.setAttribute('aria-label', 'Toggle dark mode');
            document.body.appendChild(darkModeToggle);
            darkModeToggle.addEventListener('click', () => {
                document.body.classList.toggle('dark');
                localStorage.setItem('darkMode', document.body.classList.contains('dark'));
                confetti.addConfetti({
                    emojis: ['🌙', '⭐'],
                    emojiSize: 50,
                    confettiNumber: 50
                });
            });
            if (localStorage.getItem('darkMode') === 'true') document.body.classList.add('dark');
        });
    </script>
</body>
</html>