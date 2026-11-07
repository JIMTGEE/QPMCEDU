<?php
// ============================================
// CONFIGURATION & INITIALIZATION
// ============================================
ob_start();
session_start();
require_once '../config/db.php';

// Set error reporting
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// ============================================
// DATABASE VALIDATION
// ============================================
if (!$conn || $conn->connect_error) {
    error_log("Database connection failed: " . ($conn->connect_error ?? 'Connection is null'));
    die("System error: Database connection failed. Please contact the administrator.");
}

// ============================================
// AUTHENTICATION CHECK
// ============================================
if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    $_SESSION['error_msg'] = "Please log in as an admin to access this page.";
    header('Location: ../login.php');
    exit;
}

$admin_id = $_SESSION['user_id'];

// ============================================
// HELPER FUNCTIONS
// ============================================
function normalizeProfilePicture($profile_picture) {
    // Default profile picture path
    $default_picture = '/assets/images/default_profile.png';
    
    // If profile picture is empty, return default
    if (empty($profile_picture)) {
        return $default_picture;
    }
    
    // Clean the path - remove any variations of uploads/profiles
    $clean_path = str_replace(['uploads/profiles/', 'Uploads/profiles/', '../'], '', $profile_picture);
    $clean_path = ltrim($clean_path, '/');
    
    // Construct the full server path
    $full_path = $_SERVER['DOCUMENT_ROOT'] . '/Uploads/profiles/' . $clean_path;
    
    // Check if file exists and is valid (minimum size check to avoid empty/corrupt files)
    if (file_exists($full_path) && filesize($full_path) > 100) {
        // Return the clean web-accessible path
        return '/Uploads/profiles/' . $clean_path;
    }
    
    // If file doesn't exist or is invalid, return default
    return $default_picture;
}

function fetch_data($conn, $query, $params = [], $fetch_all = true) {
    $stmt = $conn->prepare($query);
    if (!$stmt) {
        error_log("Query preparation failed: " . $conn->error);
        return false;
    }
    
    if (!empty($params)) {
        $types = '';
        foreach ($params as $param) {
            if (is_int($param)) $types .= 'i';
            elseif (is_float($param)) $types .= 'd';
            else $types .= 's';
        }
        $stmt->bind_param($types, ...$params);
    }
    
    if (!$stmt->execute()) {
        error_log("Query execution failed: " . $stmt->error);
        $stmt->close();
        return false;
    }
    
    $result = $stmt->get_result();
    $data = $fetch_all ? $result->fetch_all(MYSQLI_ASSOC) : $result->fetch_assoc();
    
    $result->free();
    $stmt->close();
    
    return $data;
}

// ============================================
// GET CURRENT ACTIVE TERM (Based on today's date)
// ============================================
$today = date('Y-m-d');
$current_term = fetch_data($conn, "
    SELECT id, term_name, year, start_date, end_date 
    FROM academic_calendar 
    WHERE start_date <= ? AND end_date >= ? 
    ORDER BY start_date DESC LIMIT 1
", [$today, $today], false);

// Fallback: get most recent term if no active term by date
if (!$current_term) {
    $current_term = fetch_data($conn, "
        SELECT id, term_name, year, start_date, end_date 
        FROM academic_calendar 
        ORDER BY year DESC, start_date DESC LIMIT 1
    ", [], false);
}

$current_term_id = $current_term['id'] ?? 0;
$current_term_display = $current_term ? $current_term['term_name'] . ' (' . $current_term['year'] . ')' : 'No active term';
$current_academic_year = $current_term['year'] ?? '';

// ============================================
// FETCH FILTER DATA
// ============================================
$departments = fetch_data($conn, "SELECT id, name FROM departments ORDER BY name") ?: [];
$classes = fetch_data($conn, "SELECT id, class_name, department_id FROM classes ORDER BY class_name") ?: [];

// Get all terms from academic_calendar
$all_terms = fetch_data($conn, "
    SELECT id, term_name, year, CONCAT(term_name, ' (', year, ')') as display
    FROM academic_calendar 
    ORDER BY year DESC, start_date DESC
") ?: [];

// Get unique academic years
$academic_years = fetch_data($conn, "
    SELECT DISTINCT year as year_label FROM academic_calendar ORDER BY year DESC
") ?: [];

// ============================================
// HANDLE FILTERS
// ============================================
$filter_values = [
    'search_term' => $_GET['search'] ?? '',
    'department_id' => $_GET['department_id'] ?? '',
    'class_id' => $_GET['class_id'] ?? '',
    'term_id' => $_GET['term_id'] ?? $current_term_id,
    'academic_year' => $_GET['academic_year'] ?? $current_academic_year
];

// Build WHERE conditions
$where_conditions = ["u.role = 'student'", "u.status = 'active'"];
$filter_params = [];

// Search by name
if (!empty($filter_values['search_term'])) {
    $where_conditions[] = "u.name LIKE ?";
    $filter_params[] = "%{$filter_values['search_term']}%";
}

// Department filter
if (!empty($filter_values['department_id'])) {
    $where_conditions[] = "c.department_id = ?";
    $filter_params[] = $filter_values['department_id'];
}

// Class filter
if (!empty($filter_values['class_id'])) {
    $where_conditions[] = "sc.class_id = ?";
    $filter_params[] = $filter_values['class_id'];
}

// Term filter - THIS IS CRITICAL for showing the right term's data
if (!empty($filter_values['term_id'])) {
    $where_conditions[] = "sr.term_id = ?";
    $filter_params[] = $filter_values['term_id'];
}

// Academic year filter
if (!empty($filter_values['academic_year'])) {
    $where_conditions[] = "sc.academic_year = ?";
    $filter_params[] = $filter_values['academic_year'];
}

// ============================================
// FETCH STUDENTS WITH REPORTS
// ============================================
$where_clause = implode(' AND ', $where_conditions);

$query = "
    SELECT 
        c.class_name, 
        c.id as class_id,
        u.id AS user_id, 
        u.name, 
        u.profile_picture, 
        sc.academic_year, 
        sr.id AS report_id, 
        sr.term_id, 
        ac.term_name, 
        ac.year as term_academic_year
    FROM users u
    INNER JOIN student_classes sc ON u.id = sc.user_id
    INNER JOIN classes c ON sc.class_id = c.id
    INNER JOIN student_reports sr ON u.id = sr.user_id AND sc.class_id = sr.class_id
    INNER JOIN academic_calendar ac ON sr.term_id = ac.id
    WHERE $where_clause
    ORDER BY c.class_name, u.name
";

$students_data = fetch_data($conn, $query, $filter_params) ?: [];

// Group students by class
$grouped_students = [];
foreach ($students_data as $student) {
    $class_name = $student['class_name'];
    
    // Use the robust normalizeProfilePicture function
    $student['profile_picture'] = normalizeProfilePicture($student['profile_picture']);
    $student['term_display'] = $student['term_name'] . ' (' . $student['term_academic_year'] . ')';
    
    if (!isset($grouped_students[$class_name])) {
        $grouped_students[$class_name] = [
            'class_id' => $student['class_id'],
            'students' => []
        ];
    }
    $grouped_students[$class_name]['students'][] = $student;
}

$total_students = count($students_data);

// Get selected term display
$selected_term_display = $current_term_display;
if (!empty($filter_values['term_id'])) {
    foreach ($all_terms as $term) {
        if ($term['id'] == $filter_values['term_id']) {
            $selected_term_display = $term['display'];
            break;
        }
    }
}

// ============================================
// HANDLE AJAX SEARCH SUGGESTIONS
// ============================================
if (isset($_GET['action']) && $_GET['action'] === 'get_student_suggestions') {
    $search_term = $_GET['term'] ?? '';
    $dept_id = $_GET['department_id'] ?? '';
    $cls_id = $_GET['class_id'] ?? '';
    
    $suggest_query = "
        SELECT DISTINCT u.name 
        FROM users u 
        JOIN student_classes sc ON u.id = sc.user_id
        JOIN classes c ON sc.class_id = c.id
        WHERE u.role = 'student' AND u.status = 'active' AND u.name LIKE ?
    ";
    $suggest_params = ["%$search_term%"];
    
    if (!empty($dept_id)) {
        $suggest_query .= " AND c.department_id = ?";
        $suggest_params[] = $dept_id;
    }
    if (!empty($cls_id)) {
        $suggest_query .= " AND sc.class_id = ?";
        $suggest_params[] = $cls_id;
    }
    
    $suggest_query .= " ORDER BY u.name LIMIT 10";
    
    $suggestions = fetch_data($conn, $suggest_query, $suggest_params) ?: [];
    $names = array_column($suggestions, 'name');
    
    header('Content-Type: application/json');
    echo json_encode($names);
    exit;
}

// ============================================
// BUILD FILTER URL FOR LINKS
// ============================================
function buildFilterUrl($overrides = []) {
    global $filter_values;
    $params = array_merge($filter_values, $overrides);
    $params = array_filter($params);
    return '?' . http_build_query($params);
}
?>

<!DOCTYPE html>
<html lang="en" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Report Cards - Queen's Paradise Montessori School</title>
    
    <!-- CSS Dependencies -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" rel="stylesheet">
    <link href="https://code.jquery.com/ui/1.12.1/themes/base/jquery-ui.css" rel="stylesheet">
    
    <style>
        :root {
            --primary-color: #ff6200;
            --secondary-color: #ff8c00;
            --shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
        }
        
        body {
            background-color: #f9fafb;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        .card {
            border-radius: 12px;
            box-shadow: var(--shadow);
            margin-bottom: 1.5rem;
            border: none;
        }
        
        .card-header {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
            border-radius: 12px 12px 0 0;
            padding: 1.2rem 1.5rem;
            border-bottom: none;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            border: none;
            border-radius: 8px;
            padding: 0.5rem 1.5rem;
            font-weight: 500;
        }
        
        .btn-primary:hover {
            background: linear-gradient(135deg, #e55a00, #e57a00);
            transform: translateY(-2px);
            box-shadow: 0 6px 15px rgba(255, 98, 0, 0.3);
        }
        
        .btn-success { background-color: #28a745; border-color: #28a745; }
        .btn-warning { background-color: #ffc107; border-color: #ffc107; color: #212529; }
        .btn-info { background-color: #17a2b8; border-color: #17a2b8; color: white; }
        .btn-secondary { background-color: #6c757d; border-color: #6c757d; }
        .btn-danger { background-color: #dc3545; border-color: #dc3545; }
        
        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
        }
        
        .table-responsive {
            border-radius: 12px;
            overflow: hidden;
            border: 1px solid #dee2e6;
        }
        
        .table thead {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
        }
        
        .table th {
            border: none;
            padding: 1rem;
            font-weight: 600;
        }
        
        .table td {
            padding: 0.75rem 1rem;
            vertical-align: middle;
        }
        
        .table-hover tbody tr:hover {
            background-color: rgba(255, 98, 0, 0.05);
        }
        
        .profile-img {
            width: 45px;
            height: 45px;
            object-fit: cover;
            border-radius: 50%;
            border: 3px solid var(--primary-color);
            background-color: #f0f0f0;
        }
        
        .filter-section {
            background: white;
            padding: 1.5rem;
            border-radius: 12px;
            box-shadow: var(--shadow);
            margin-bottom: 2rem;
        }
        
        .form-control, .form-select {
            border-radius: 8px;
            border: 1px solid #ced4da;
            padding: 0.5rem 0.75rem;
        }
        
        .form-control:focus, .form-select:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 0.25rem rgba(255, 98, 0, 0.25);
        }
        
        .ui-autocomplete {
            max-height: 200px;
            overflow-y: auto;
            border-radius: 8px;
            box-shadow: var(--shadow);
            border: 1px solid var(--primary-color);
        }
        
        .badge {
            padding: 0.35em 0.65em;
            font-size: 0.75em;
            font-weight: 600;
        }
        
        .action-buttons {
            display: flex;
            gap: 5px;
            flex-wrap: wrap;
        }
        
        .action-buttons .btn {
            padding: 0.25rem 0.5rem;
            font-size: 0.875rem;
        }
        
        .term-badge {
            background: linear-gradient(135deg, #17a2b8, #138496);
            color: white;
            padding: 0.5rem 1rem;
            border-radius: 8px;
            font-weight: 500;
        }
        
        .bulk-print-bar {
            background: #e8f4fd;
            border-radius: 8px;
            padding: 12px 20px;
            margin-bottom: 20px;
            border-left: 4px solid var(--primary-color);
        }
        
        .student-checkbox {
            width: 20px;
            height: 20px;
            cursor: pointer;
        }
        
        .select-all-checkbox {
            width: 20px;
            height: 20px;
            cursor: pointer;
        }
        
        .class-bulk-actions {
            display: flex;
            gap: 10px;
            align-items: center;
        }
    </style>
</head>
<body>
    <?php include '../templates/admin_header.php'; ?>
    
    <div class="container-fluid py-4">
        <div class="card">
            <div class="card-header">
                <div class="d-flex justify-content-between align-items-center flex-wrap">
                    <h3 class="mb-0">
                        <i class="fas fa-graduation-cap me-2"></i>Student Report Cards
                    </h3>
                    <div class="mt-2 mt-sm-0">
                        <span class="badge bg-light text-dark fs-6">
                            <i class="fas fa-users me-1"></i>
                            <?php echo $total_students; ?> Reports
                        </span>
                        <span class="badge bg-info text-white fs-6 ms-2">
                            <i class="fas fa-calendar-alt me-1"></i>
                            <?php echo htmlspecialchars($selected_term_display); ?>
                        </span>
                    </div>
                </div>
            </div>
            
            <div class="card-body">
                <!-- Current Term Alert -->
                <div class="alert alert-info">
                    <i class="fas fa-info-circle me-2"></i>
                    <strong>Showing reports for:</strong> <?php echo htmlspecialchars($selected_term_display); ?>
                    <?php if ($filter_values['term_id'] == $current_term_id): ?>
                        <span class="badge bg-primary ms-2">Current Active Term</span>
                    <?php endif; ?>
                </div>
                
                <!-- Bulk Print Bar -->
                <div class="bulk-print-bar" id="bulkPrintBar" style="display: none;">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <i class="fas fa-print me-2" style="color: var(--primary-color);"></i>
                            <strong>Bulk Print Mode</strong>
                            <span class="ms-3">
                                <span id="selectedCount">0</span> student(s) selected
                            </span>
                        </div>
                        <div>
                            <button class="btn btn-success me-2" onclick="bulkPrintSelected()">
                                <i class="fas fa-print me-1"></i>Print Selected Reports
                            </button>
                            <button class="btn btn-outline-secondary" onclick="clearAllSelections()">
                                <i class="fas fa-times me-1"></i>Cancel
                            </button>
                        </div>
                    </div>
                </div>
                
                <!-- Filter Form -->
                <div class="filter-section">
                    <form method="GET" id="filterForm">
                        <div class="row g-3">
                            <div class="col-md-3">
                                <label class="form-label fw-semibold">
                                    <i class="fas fa-search me-1"></i>Search Student
                                </label>
                                <input type="text" name="search" id="search_term" 
                                       class="form-control" 
                                       placeholder="Type student name..."
                                       value="<?php echo htmlspecialchars($filter_values['search_term']); ?>"
                                       autocomplete="off">
                            </div>
                            
                            <div class="col-md-2">
                                <label class="form-label fw-semibold">Department</label>
                                <select name="department_id" id="department_id" class="form-select">
                                    <option value="">All Departments</option>
                                    <?php foreach ($departments as $dept): ?>
                                        <option value="<?php echo $dept['id']; ?>"
                                            <?php echo $filter_values['department_id'] == $dept['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($dept['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="col-md-2">
                                <label class="form-label fw-semibold">Class</label>
                                <select name="class_id" id="class_id" class="form-select">
                                    <option value="">All Classes</option>
                                    <?php foreach ($classes as $class): ?>
                                        <option value="<?php echo $class['id']; ?>" 
                                                data-dept="<?php echo $class['department_id']; ?>"
                                                <?php echo $filter_values['class_id'] == $class['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($class['class_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="col-md-2">
                                <label class="form-label fw-semibold">Term</label>
                                <select name="term_id" id="term_id" class="form-select">
                                    <option value="">All Terms</option>
                                    <?php foreach ($all_terms as $term): ?>
                                        <option value="<?php echo $term['id']; ?>"
                                            <?php echo $filter_values['term_id'] == $term['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($term['display']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="col-md-2">
                                <label class="form-label fw-semibold">Academic Year</label>
                                <select name="academic_year" class="form-select">
                                    <option value="">All Years</option>
                                    <?php foreach ($academic_years as $year): ?>
                                        <option value="<?php echo htmlspecialchars($year['year_label']); ?>"
                                            <?php echo $filter_values['academic_year'] == $year['year_label'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($year['year_label']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="col-md-1 d-flex align-items-end">
                                <button type="submit" class="btn btn-primary w-100">
                                    <i class="fas fa-filter"></i> Filter
                                </button>
                            </div>
                        </div>
                        
                        <div class="row mt-3">
                            <div class="col-md-12">
                                <a href="report_card.php" class="btn btn-outline-secondary btn-sm">
                                    <i class="fas fa-times me-1"></i>Clear All Filters
                                </a>
                                <a href="report_card.php?term_id=<?php echo $current_term_id; ?>" class="btn btn-outline-primary btn-sm ms-2">
                                    <i class="fas fa-calendar-check me-1"></i>Show Current Term Only
                                </a>
                                <button type="button" class="btn btn-danger btn-sm ms-2" onclick="toggleBulkPrintMode()">
                                    <i class="fas fa-layer-group me-1"></i>Bulk Print Mode
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
                
                <!-- Results -->
                <?php if (empty($grouped_students)): ?>
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        No report cards found for the selected filters.
                        <?php if ($filter_values['term_id'] == $current_term_id): ?>
                            <br>Teachers may not have submitted reports for this term yet.
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <?php foreach ($grouped_students as $class_name => $data): ?>
                        <div class="card mb-4 class-card" data-class-id="<?php echo $data['class_id']; ?>">
                            <div class="card-header">
                                <div class="d-flex justify-content-between align-items-center">
                                    <h4 class="mb-0">
                                        <i class="fas fa-chalkboard-teacher me-2"></i>
                                        <?php echo htmlspecialchars($class_name); ?>
                                        <span class="badge bg-light text-dark ms-2">
                                            <?php echo count($data['students']); ?> student(s)
                                        </span>
                                    </h4>
                                    <div class="class-bulk-actions bulk-actions" style="display: none;">
                                        <button class="btn btn-sm btn-outline-light" onclick="selectAllInClass('<?php echo $data['class_id']; ?>')">
                                            <i class="fas fa-check-square me-1"></i>Select All in Class
                                        </button>
                                        <button class="btn btn-sm btn-outline-light" onclick="printClass('<?php echo $data['class_id']; ?>')">
                                            <i class="fas fa-print me-1"></i>Print Class
                                        </button>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="card-body p-0">
                                <div class="table-responsive">
                                    <table class="table table-hover mb-0" id="class-table-<?php echo $data['class_id']; ?>">
                                        <thead>
                                            <tr>
                                                <th width="40" class="bulk-print-col" style="display: none;">
                                                    <input type="checkbox" class="select-all-checkbox" 
                                                           onchange="toggleSelectAllClass(this, '<?php echo $data['class_id']; ?>')">
                                                </th>
                                                <th width="60">Photo</th>
                                                <th>Student Name</th>
                                                <th>Term</th>
                                                <th>Academic Year</th>
                                                <th width="280">Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($data['students'] as $student): ?>
                                                <tr data-student-id="<?php echo $student['user_id']; ?>" 
                                                    data-report-id="<?php echo $student['report_id']; ?>"
                                                    data-term-id="<?php echo $student['term_id']; ?>"
                                                    data-class-id="<?php echo $data['class_id']; ?>">
                                                    <td class="bulk-print-col" style="display: none;">
                                                        <input type="checkbox" class="student-checkbox" 
                                                               value="<?php echo $student['report_id']; ?>"
                                                               data-user-id="<?php echo $student['user_id']; ?>"
                                                               data-term-id="<?php echo $student['term_id']; ?>"
                                                               onchange="updateSelectedCount()">
                                                    </td>
                                                    <td>
                                                        <img src="<?php echo htmlspecialchars($student['profile_picture']); ?>" 
                                                             alt="Profile" class="profile-img"
                                                             loading="lazy">
                                                    </td>
                                                    
                                                    <td>
                                                        <a href="view_report_card.php?report_id=<?php echo $student['report_id']; ?>&user_id=<?php echo $student['user_id']; ?>&term_id=<?php echo $student['term_id']; ?>" 
                                                           class="text-decoration-none fw-semibold text-primary">
                                                            <?php echo htmlspecialchars($student['name']); ?>
                                                        </a>
                                                    </td>
                                                    
                                                    <td><?php echo htmlspecialchars($student['term_display']); ?></td>
                                                    <td><?php echo htmlspecialchars($student['academic_year']); ?></td>
                                                    
                                                    <td>
                                                        <div class="action-buttons">
                                                            <a href="view_report_card.php?report_id=<?php echo $student['report_id']; ?>&user_id=<?php echo $student['user_id']; ?>&term_id=<?php echo $student['term_id']; ?>" 
                                                               class="btn btn-info btn-sm" title="View Report">
                                                                <i class="fas fa-eye"></i>
                                                            </a>
                                                            
                                                            <a href="print_report_grades.php?report_id=<?php echo $student['report_id']; ?>&user_id=<?php echo $student['user_id']; ?>&term_id=<?php echo $student['term_id']; ?>" 
                                                               class="btn btn-secondary btn-sm" title="Print Report">
                                                                <i class="fas fa-print"></i>
                                                            </a>
                                                            
                                                            <a href="student_statistics.php?user_id=<?php echo $student['user_id']; ?>&term_id=<?php echo $student['term_id']; ?>" 
                                                               class="btn btn-success btn-sm" title="Statistics">
                                                                <i class="fas fa-chart-bar"></i>
                                                            </a>
                                                            
                                                            <a href="grade_graph.php?user_id=<?php echo $student['user_id']; ?>&term_id=<?php echo $student['term_id']; ?>" 
                                                               class="btn btn-warning btn-sm" title="Grade Graph">
                                                                <i class="fas fa-chart-line"></i>
                                                            </a>
                                                            
                                                            <a href="edit_remarks.php?report_id=<?php echo $student['report_id']; ?>" 
                                                               class="btn btn-primary btn-sm" title="Edit Remarks">
                                                                <i class="fas fa-edit"></i>
                                                            </a>
                                                        </div>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            
            <div class="card-footer text-muted">
                <div class="row">
                    <div class="col-md-6">
                        <small>
                            <i class="fas fa-info-circle me-1"></i>
                            Showing <?php echo $total_students; ?> report(s) across <?php echo count($grouped_students); ?> class(es)
                        </small>
                    </div>
                    <div class="col-md-6 text-end">
                        <small>Last updated: <?php echo date('F j, Y, g:i a'); ?></small>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <?php include '../templates/admin_footer.php'; ?>
    
    <!-- Bulk Print Form (Hidden) -->
    <form id="bulkPrintForm" method="POST" action="print_report_grades.php" target="_blank" style="display: none;">
        <input type="hidden" name="bulk_print" value="1">
        <input type="hidden" name="report_ids" id="bulkReportIds" value="">
        <input type="hidden" name="user_ids" id="bulkUserIds" value="">
        <input type="hidden" name="term_ids" id="bulkTermIds" value="">
    </form>
    
    <!-- JavaScript -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://code.jquery.com/ui/1.12.1/jquery-ui.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
    let bulkPrintMode = false;
    
    $(document).ready(function() {
        // Autocomplete for search
        $("#search_term").autocomplete({
            source: function(request, response) {
                $.ajax({
                    url: window.location.pathname,
                    dataType: 'json',
                    data: {
                        action: 'get_student_suggestions',
                        term: request.term,
                        department_id: $('#department_id').val(),
                        class_id: $('#class_id').val()
                    },
                    success: function(data) {
                        response(data);
                    }
                });
            },
            minLength: 2
        });
        
        // Filter classes based on department
        function filterClasses() {
            var deptId = $('#department_id').val();
            $('#class_id option').each(function() {
                var $option = $(this);
                if ($option.val() === '' || deptId === '') {
                    $option.show();
                } else {
                    $option.toggle($option.data('dept') == deptId);
                }
            });
            
            // Reset class if hidden
            if ($('#class_id option:selected').is(':hidden')) {
                $('#class_id').val('');
            }
        }
        
        $('#department_id').on('change', filterClasses);
        filterClasses();
        
        // Highlight current term in dropdown
        <?php if ($filter_values['term_id'] == $current_term_id): ?>
            $('#term_id').addClass('border-info');
        <?php endif; ?>
    });
    
    // Toggle Bulk Print Mode
    function toggleBulkPrintMode() {
        bulkPrintMode = !bulkPrintMode;
        
        // Show/hide bulk print columns
        if (bulkPrintMode) {
            $('.bulk-print-col').show();
            $('.bulk-actions').show();
            $('#bulkPrintBar').show();
        } else {
            $('.bulk-print-col').hide();
            $('.bulk-actions').hide();
            $('#bulkPrintBar').hide();
            clearAllSelections();
        }
    }
    
    // Select all students in a class
    function selectAllInClass(classId) {
        const table = $(`#class-table-${classId}`);
        const checkboxes = table.find('.student-checkbox');
        const selectAllCheckbox = table.find('.select-all-checkbox');
        const isChecked = selectAllCheckbox.prop('checked');
        
        checkboxes.prop('checked', !isChecked);
        selectAllCheckbox.prop('checked', !isChecked);
        
        updateSelectedCount();
    }
    
    // Toggle select all for class
    function toggleSelectAllClass(checkbox, classId) {
        const table = $(`#class-table-${classId}`);
        const checkboxes = table.find('.student-checkbox');
        checkboxes.prop('checked', checkbox.checked);
        
        updateSelectedCount();
    }
    
    // Update selected count display
    function updateSelectedCount() {
        const selected = $('.student-checkbox:checked').length;
        $('#selectedCount').text(selected);
        
        // Update select-all checkboxes
        $('.class-card').each(function() {
            const classId = $(this).data('class-id');
            const table = $(`#class-table-${classId}`);
            const total = table.find('.student-checkbox').length;
            const checked = table.find('.student-checkbox:checked').length;
            const selectAll = table.find('.select-all-checkbox');
            
            if (total > 0) {
                selectAll.prop('checked', checked === total);
                selectAll.prop('indeterminate', checked > 0 && checked < total);
            }
        });
    }
    
    // Clear all selections
    function clearAllSelections() {
        $('.student-checkbox').prop('checked', false);
        $('.select-all-checkbox').prop('checked', false).prop('indeterminate', false);
        $('#selectedCount').text('0');
    }
    
    // Print entire class
    function printClass(classId) {
        const table = $(`#class-table-${classId}`);
        const checkboxes = table.find('.student-checkbox');
        
        const reportIds = [];
        const userIds = [];
        const termIds = [];
        
        checkboxes.each(function() {
            const $row = $(this).closest('tr');
            reportIds.push($(this).val());
            userIds.push($(this).data('user-id'));
            termIds.push($(this).data('term-id'));
        });
        
        if (reportIds.length === 0) {
            alert('No students in this class to print.');
            return;
        }
        
        submitBulkPrint(reportIds, userIds, termIds);
    }
    
    // Print selected students
    function bulkPrintSelected() {
        const selectedCheckboxes = $('.student-checkbox:checked');
        
        if (selectedCheckboxes.length === 0) {
            alert('Please select at least one student to print.');
            return;
        }
        
        const reportIds = [];
        const userIds = [];
        const termIds = [];
        
        selectedCheckboxes.each(function() {
            reportIds.push($(this).val());
            userIds.push($(this).data('user-id'));
            termIds.push($(this).data('term-id'));
        });
        
        submitBulkPrint(reportIds, userIds, termIds);
    }
    
    // Submit bulk print form
    function submitBulkPrint(reportIds, userIds, termIds) {
        $('#bulkReportIds').val(reportIds.join(','));
        $('#bulkUserIds').val(userIds.join(','));
        $('#bulkTermIds').val(termIds.join(','));
        
        // Submit the form to open print page
        $('#bulkPrintForm').submit();
        
        // Optionally exit bulk print mode
        setTimeout(() => {
            toggleBulkPrintMode();
        }, 500);
    }
    </script>
</body>
</html>
<?php
$conn->close();
ob_end_flush();
?>