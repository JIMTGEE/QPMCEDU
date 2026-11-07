<?php
// Academic Scores Management System - Professional Grade
// Fixed: CSRF token rotation, bind_param type strings, MariaDB self-referencing UPDATE

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('max_execution_time', 120);
ini_set('post_max_size', '32M');
ini_set('upload_max_filesize', '32M');
ini_set('memory_limit', '256M');

ob_start();
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_secure', isset($_SERVER['HTTPS']) ? '1' : '0');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.use_strict_mode', '1');
    session_start();
}

// Authentication check
if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'teacher') {
    $_SESSION['error_msg'] = "Please log in as a teacher.";
    header("Location: /login.php");
    exit();
}

// Database connection
$db_path = $_SERVER['DOCUMENT_ROOT'] . '/config/db.php';
if (!file_exists($db_path)) {
    error_log("Database config file missing: $db_path");
    $_SESSION['error_msg'] = "System error: Server configuration error.";
    header("Location: /login.php");
    exit();
}
require_once($db_path);

// Activity logging
$log_path = $_SERVER['DOCUMENT_ROOT'] . '/functions/log_activity.php';
if (file_exists($log_path)) {
    require_once($log_path);
} else {
    function logActivity($conn, $user_id, $action, $description) {
        error_log("Activity: [$user_id] $action - $description");
    }
}

if (!isset($conn) || !$conn || mysqli_connect_error()) {
    error_log("Database connection failed: " . (mysqli_connect_error() ?: "Unknown error"));
    $_SESSION['error_msg'] = "System error: Database connection failed.";
    header("Location: /login.php");
    exit();
}

$teacher_id = (int)$_SESSION['user_id'];

// Validate teacher
$teacher_query = "SELECT u.id, u.name, u.email, u.department_id, d.name as department_name 
                  FROM users u 
                  LEFT JOIN departments d ON u.department_id = d.id 
                  WHERE u.id = ? AND u.role = 'teacher'";
$teacher_stmt = $conn->prepare($teacher_query);
if (!$teacher_stmt) {
    error_log("Teacher validation failed: " . $conn->error);
    $_SESSION['error_msg'] = "System error occurred.";
    header("Location: /login.php");
    exit();
}
$teacher_stmt->bind_param("i", $teacher_id);
$teacher_stmt->execute();
$teacher_result = $teacher_stmt->get_result();

if ($teacher_result->num_rows === 0) {
    error_log("Invalid teacher session: $teacher_id");
    $_SESSION['error_msg'] = "Invalid session.";
    header("Location: /login.php");
    exit();
}
$teacher = $teacher_result->fetch_assoc();
$teacher_stmt->close();

// -----------------------------------------------------------------------
// FIX 1: CSRF token — generate ONCE per session, never overwrite on GET.
//         This prevents the token from rotating between the page load
//         and any subsequent GET API calls (e.g. get_students after submit),
//         which was the direct cause of "Invalid security token".
// -----------------------------------------------------------------------
function generateCsrfToken() {
    if (function_exists('random_bytes')) {
        return bin2hex(random_bytes(32));
    }
    return md5(uniqid(mt_rand(), true));
}

function validateCsrfToken($token) {
    return isset($_SESSION['csrf_token']) && !empty($token) && hash_equals($_SESSION['csrf_token'], $token);
}

// Only generate a new token if one doesn't exist yet for this session.
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = generateCsrfToken();
}
$csrf_token = $_SESSION['csrf_token'];

// Get current date and determine academic year/term
$today = date('Y-m-d');
$current_month = (int)date('m');
$current_year = (int)date('Y');

if ($current_month >= 9) {
    $current_academic_year = "$current_year/" . ($current_year + 1);
} else {
    $current_academic_year = ($current_year - 1) . "/$current_year";
}

// Check which columns exist in student_classes table
$student_classes_columns = [];
$sc_cols_result = $conn->query("SHOW COLUMNS FROM student_classes");
if ($sc_cols_result) {
    while ($col = $sc_cols_result->fetch_assoc()) {
        $student_classes_columns[] = $col['Field'];
    }
    error_log("student_classes columns: " . implode(', ', $student_classes_columns));
}

// Check which columns exist in users table
$users_columns = [];
$users_cols_result = $conn->query("SHOW COLUMNS FROM users");
if ($users_cols_result) {
    while ($col = $users_cols_result->fetch_assoc()) {
        $users_columns[] = $col['Field'];
    }
    error_log("users columns: " . implode(', ', $users_columns));
}

// Build student select query dynamically based on available columns
$student_select = "u.id, u.name, u.email";
$student_order = "u.name ASC";

if (in_array('roll_number', $student_classes_columns)) {
    $student_select .= ", sc.roll_number";
    $student_order = "sc.roll_number ASC, u.name ASC";
}

// Check if users table has a status column
$has_user_status = in_array('status', $users_columns);
$student_where = "u.role = 'student'";
if ($has_user_status) {
    $student_where .= " AND u.status = 'active'";
}

// Get current term from academic_calendar
$current_term_id = null;
$current_term_name = 'N/A';
$term_start = null;
$term_end = null;

$table_check = $conn->query("SHOW TABLES LIKE 'academic_calendar'");
if ($table_check && $table_check->num_rows > 0) {
    $academic_query = "SELECT id, term_name, year, start_date, end_date 
                       FROM academic_calendar 
                       WHERE year = ? AND start_date <= ? AND end_date >= ?
                       ORDER BY start_date ASC LIMIT 1";
    $academic_stmt = $conn->prepare($academic_query);
    if ($academic_stmt) {
        $academic_stmt->bind_param("sss", $current_academic_year, $today, $today);
        $academic_stmt->execute();
        $academic_result = $academic_stmt->get_result();
        
        if ($academic_result->num_rows > 0) {
            $academic = $academic_result->fetch_assoc();
            $current_term_id = $academic['id'];
            $current_term_name = $academic['term_name'];
            $term_start = $academic['start_date'];
            $term_end = $academic['end_date'];
        }
        $academic_stmt->close();
    }
    
    // Fallback: upcoming term
    if (!$current_term_id) {
        $upcoming_query = "SELECT id, term_name, year, start_date, end_date 
                          FROM academic_calendar 
                          WHERE year = ? AND start_date > ?
                          ORDER BY start_date ASC LIMIT 1";
        $upcoming_stmt = $conn->prepare($upcoming_query);
        if ($upcoming_stmt) {
            $upcoming_stmt->bind_param("ss", $current_academic_year, $today);
            $upcoming_stmt->execute();
            $upcoming_result = $upcoming_stmt->get_result();
            
            if ($upcoming_result->num_rows > 0) {
                $academic = $upcoming_result->fetch_assoc();
                $current_term_id = $academic['id'];
                $current_term_name = $academic['term_name'] . ' (Upcoming)';
                $term_start = $academic['start_date'];
                $term_end = $academic['end_date'];
            }
            $upcoming_stmt->close();
        }
    }
    
    // Last fallback: latest term
    if (!$current_term_id) {
        $latest_query = "SELECT id, term_name, year, start_date, end_date 
                       FROM academic_calendar 
                       WHERE year = ?
                       ORDER BY end_date DESC LIMIT 1";
        $latest_stmt = $conn->prepare($latest_query);
        if ($latest_stmt) {
            $latest_stmt->bind_param("s", $current_academic_year);
            $latest_stmt->execute();
            $latest_result = $latest_stmt->get_result();
            
            if ($latest_result->num_rows > 0) {
                $academic = $latest_result->fetch_assoc();
                $current_term_id = $academic['id'];
                $current_term_name = $academic['term_name'] . ' (Previous)';
                $term_start = $academic['start_date'];
                $term_end = $academic['end_date'];
            }
            $latest_stmt->close();
        }
    }
}

// Generate bi-weekly periods from term dates
function generateBiWeeklyPeriods($start_date, $end_date) {
    $periods = [];
    if (!$start_date || !$end_date) return $periods;
    
    try {
        $current = new DateTime($start_date);
        $end = new DateTime($end_date);
        $period_num = 1;
        
        while ($current <= $end && $period_num <= 20) {
            $p_start = clone $current;
            $p_end = clone $current;
            $p_end->modify('+13 days');
            
            if ($p_end > $end) {
                $p_end = clone $end;
            }
            
            $periods[] = [
                'id' => $period_num,
                'start_date' => $p_start->format('Y-m-d'),
                'end_date' => $p_end->format('Y-m-d'),
                'label' => sprintf('Period %d: %s - %s', 
                    $period_num, 
                    $p_start->format('M d'), 
                    $p_end->format('M d, Y')
                )
            ];
            
            $current->modify('+14 days');
            $period_num++;
        }
    } catch (Exception $e) {
        error_log("Error generating periods: " . $e->getMessage());
    }
    
    return $periods;
}

// API Endpoint Handler
if (isset($_GET['action'])) {
    while (ob_get_level()) {
        ob_end_clean();
    }
    
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');
    
    $action = $_GET['action'];
    $response = ['success' => false, 'error' => 'Unknown action'];
    
    try {
        switch ($action) {
            case 'get_initial_data':
                // Get teacher's classes from schedules
                $classes_sql = "SELECT DISTINCT c.id, c.class_name, c.department_id, d.name as department_name
                               FROM class_schedules cs
                               JOIN classes c ON cs.class_id = c.id
                               LEFT JOIN departments d ON c.department_id = d.id
                               WHERE cs.teacher_id = ? AND cs.academic_year = ?
                               ORDER BY d.name, c.class_name";
                $stmt = $conn->prepare($classes_sql);
                if ($stmt) {
                    $stmt->bind_param("is", $teacher_id, $current_academic_year);
                    $stmt->execute();
                    $classes = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                    $stmt->close();
                } else {
                    $classes = [];
                }
                
                if (empty($classes)) {
                    $classes_sql = "SELECT c.id, c.class_name, c.department_id, d.name as department_name
                                   FROM classes c
                                   LEFT JOIN departments d ON c.department_id = d.id
                                   ORDER BY d.name, c.class_name";
                    $result = $conn->query($classes_sql);
                    $classes = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
                }
                
                // Get subjects (excluding KG/Preschool - departments 1,2)
                $subjects_sql = "SELECT id, subject_name, subject_code, department_id
                                FROM subjects
                                WHERE department_id NOT IN (1, 2)
                                AND (status IS NULL OR status = 'Active' OR status = 'active')
                                ORDER BY subject_name";
                $subjects_result = $conn->query($subjects_sql);
                $subjects = $subjects_result ? $subjects_result->fetch_all(MYSQLI_ASSOC) : [];
                
                // Get terms from academic_calendar
                $terms = [];
                $ac_exists = $conn->query("SHOW TABLES LIKE 'academic_calendar'");
                if ($ac_exists && $ac_exists->num_rows > 0) {
                    $terms_sql = "SELECT id, term_name, year, start_date, end_date
                                 FROM academic_calendar
                                 WHERE year = ?
                                 ORDER BY start_date ASC";
                    $stmt = $conn->prepare($terms_sql);
                    if ($stmt) {
                        $stmt->bind_param("s", $current_academic_year);
                        $stmt->execute();
                        $terms = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                        $stmt->close();
                    }
                }
                
                $periods = [];
                if ($term_start && $term_end) {
                    $periods = generateBiWeeklyPeriods($term_start, $term_end);
                }
                
                $response = [
                    'success' => true,
                    'data' => [
                        'teacher' => $teacher,
                        'classes' => $classes,
                        'subjects' => $subjects,
                        'terms' => $terms,
                        'periods' => $periods,
                        'academic_year' => $current_academic_year,
                        'current_term_id' => $current_term_id,
                        'current_term_name' => $current_term_name,
                        // FIX 2: Return the current (stable) CSRF token to JS so it can
                        //         keep itself in sync without needing a full page reload.
                        'csrf_token' => $csrf_token,
                        'has_roll_number' => in_array('roll_number', $student_classes_columns)
                    ]
                ];
                break;
                
            case 'get_periods':
                $term_id = (int)($_GET['term_id'] ?? 0);
                if ($term_id > 0) {
                    $term_sql = "SELECT start_date, end_date FROM academic_calendar WHERE id = ?";
                    $stmt = $conn->prepare($term_sql);
                    if ($stmt) {
                        $stmt->bind_param("i", $term_id);
                        $stmt->execute();
                        $term_data = $stmt->get_result()->fetch_assoc();
                        $stmt->close();
                        
                        if ($term_data) {
                            $periods = generateBiWeeklyPeriods($term_data['start_date'], $term_data['end_date']);
                            $response = ['success' => true, 'periods' => $periods];
                        } else {
                            $response = ['success' => false, 'error' => 'Term not found'];
                        }
                    }
                }
                break;
                
            case 'get_students':
                $class_id = (int)($_GET['class_id'] ?? 0);
                $subject_id = (int)($_GET['subject_id'] ?? 0);
                $term_id_input = (int)($_GET['term_id'] ?? 0);
                $start_date = $_GET['start_date'] ?? '';
                $end_date = $_GET['end_date'] ?? '';
                
                if ($class_id > 0) {
                    $sc_table_check = $conn->query("SHOW TABLES LIKE 'student_classes'");
                    if (!$sc_table_check || $sc_table_check->num_rows === 0) {
                        $response = ['success' => false, 'error' => 'student_classes table does not exist'];
                        break;
                    }
                    
                    $students_sql = "SELECT $student_select
                                    FROM users u
                                    JOIN student_classes sc ON u.id = sc.user_id AND sc.class_id = ?
                                    WHERE $student_where
                                    ORDER BY $student_order";
                    
                    error_log("Students query: $students_sql");
                    
                    $stmt = $conn->prepare($students_sql);
                    if ($stmt) {
                        $stmt->bind_param("i", $class_id);
                        $stmt->execute();
                        $students = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                        $stmt->close();
                    } else {
                        error_log("Students query failed: " . $conn->error);
                        $students = [];
                    }
                    
                    $existing_scores = [];
                    if ($subject_id > 0 && $term_id_input > 0 && $start_date && $end_date) {
                        $scores_sql = "SELECT student_id, class_practices_raw, assignments_raw, cat_raw, 
                                      group_project_raw, comments, class_practices_max, assignments_max,
                                      cat_max, group_project_max, status
                                      FROM academic_progress_entries
                                      WHERE class_id = ? AND subject_id = ? AND term_id = ?
                                      AND period_start_date = ? AND period_end_date = ?
                                      AND entry_period = 'bi-weekly' AND academic_year = ?
                                      AND teacher_id = ?";
                        $stmt = $conn->prepare($scores_sql);
                        if ($stmt) {
                            $stmt->bind_param("iiisssi", $class_id, $subject_id, $term_id_input, 
                                             $start_date, $end_date, $current_academic_year, $teacher_id);
                            $stmt->execute();
                            $scores_result = $stmt->get_result();
                            while ($row = $scores_result->fetch_assoc()) {
                                $existing_scores[$row['student_id']] = $row;
                            }
                            $stmt->close();
                        }
                    }
                    
                    $response = [
                        'success' => true,
                        'students' => $students,
                        'existing_scores' => $existing_scores
                    ];
                } else {
                    $response = ['success' => false, 'error' => 'Invalid class ID'];
                }
                break;
        }
    } catch (Exception $e) {
        error_log("API Error in action '$action': " . $e->getMessage() . "\n" . $e->getTraceAsString());
        $response = ['success' => false, 'error' => 'Server error: ' . $e->getMessage()];
    }
    
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    exit();
}

// Handle POST submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    while (ob_get_level()) {
        ob_end_clean();
    }
    
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    
    $post_token = $_POST['csrf_token'] ?? '';
    if (!validateCsrfToken($post_token)) {
        echo json_encode(['success' => false, 'error' => 'Invalid security token. Please refresh the page.']);
        exit();
    }
    
    $action = $_POST['action'] ?? '';
    
    if ($action === 'submit_scores') {
        $class_id = (int)($_POST['class_id'] ?? 0);
        $subject_id = (int)($_POST['subject_id'] ?? 0);
        $term_id = (int)($_POST['term_id'] ?? 0);
        $period_start = $_POST['period_start_date'] ?? '';
        $period_end = $_POST['period_end_date'] ?? '';
        
        $max_scores = [
            'class_practices' => (float)($_POST['class_practices_max'] ?? 0),
            'assignments' => (float)($_POST['assignments_max'] ?? 0),
            'cat' => (float)($_POST['cat_max'] ?? 0),
            'group_project' => (float)($_POST['group_project_max'] ?? 0)
        ];
        
        $errors = [];
        if ($class_id <= 0) $errors[] = 'Invalid class';
        if ($subject_id <= 0) $errors[] = 'Invalid subject';
        if ($term_id <= 0) $errors[] = 'Invalid term';
        if (empty($period_start) || empty($period_end)) $errors[] = 'Invalid period';
        foreach ($max_scores as $key => $val) {
            if ($val <= 0) $errors[] = ucfirst(str_replace('_', ' ', $key)) . ' max score must be positive';
        }
        
        $students_json = $_POST['students'] ?? '[]';
        $students_data = json_decode($students_json, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            $errors[] = 'Invalid student data format: ' . json_last_error_msg();
            error_log("JSON decode error: " . json_last_error_msg() . " - Data: " . substr($students_json, 0, 500));
        }
        
        if (empty($students_data)) {
            $errors[] = 'No student data provided';
        }
        
        if (!empty($errors)) {
            echo json_encode(['success' => false, 'error' => implode(', ', $errors)]);
            exit();
        }
        
        $table_check = $conn->query("SHOW TABLES LIKE 'academic_progress_entries'");
        if (!$table_check || $table_check->num_rows === 0) {
            echo json_encode(['success' => false, 'error' => 'academic_progress_entries table does not exist']);
            exit();
        }
        
        $conn->begin_transaction();
        try {
            $inserted = 0;
            $updated = 0;
            $errors_encountered = [];
            
            foreach ($students_data as $index => $student) {
                $sid = (int)($student['id'] ?? 0);
                if ($sid <= 0) {
                    $errors_encountered[] = "Skipped student at index $index: invalid ID";
                    continue;
                }
                
                $cp = max(0, min((float)($student['class_practices_raw'] ?? 0), $max_scores['class_practices']));
                $as = max(0, min((float)($student['assignments_raw'] ?? 0), $max_scores['assignments']));
                $ct = max(0, min((float)($student['cat_raw'] ?? 0), $max_scores['cat']));
                $gp = max(0, min((float)($student['group_project_raw'] ?? 0), $max_scores['group_project']));
                $comment = substr(trim($student['comments'] ?? ''), 0, 500);
                
                // Check if entry exists
                $check_sql = "SELECT id FROM academic_progress_entries 
                             WHERE student_id = ? AND class_id = ? AND subject_id = ? 
                             AND term_id = ? AND period_start_date = ? AND period_end_date = ?
                             AND entry_period = 'bi-weekly' AND teacher_id = ?";
                $check_stmt = $conn->prepare($check_sql);
                if (!$check_stmt) {
                    throw new Exception("Prepare check failed: " . $conn->error);
                }
                $check_stmt->bind_param("iiiissi", $sid, $class_id, $subject_id, $term_id, $period_start, $period_end, $teacher_id);
                $check_stmt->execute();
                $check_result = $check_stmt->get_result();
                $existing_row = $check_result->fetch_assoc();
                $check_stmt->close();
                
                if ($existing_row) {
                    $existing_id = (int)$existing_row['id'];

                    // -------------------------------------------------------
                    // FIX 3 (UPDATE): Corrected bind_param type string.
                    // Variables: 8 doubles (scores) + 1 string (comment)
                    //            + 1 int (entry id) = "ddddddddsi" = 10 types.
                    // Also FIX 5: Use the fetched $existing_id directly in the
                    // WHERE clause instead of a self-referencing subquery,
                    // which MariaDB/MySQL does not allow on the same table.
                    // -------------------------------------------------------
                    $update_sql = "UPDATE academic_progress_entries SET
                                  class_practices_raw = ?, class_practices_max = ?,
                                  assignments_raw = ?, assignments_max = ?,
                                  cat_raw = ?, cat_max = ?,
                                  group_project_raw = ?, group_project_max = ?,
                                  scaled_scores = JSON_OBJECT(
                                      'class_practices', IF(? > 0, (? / ?) * 25, 0),
                                      'assignments',     IF(? > 0, (? / ?) * 25, 0),
                                      'cat',             IF(? > 0, (? / ?) * 25, 0),
                                      'group_project',   IF(? > 0, (? / ?) * 25, 0)
                                  ),
                                  total_scaled = 
                                      IF(? > 0, (? / ?) * 25, 0) +
                                      IF(? > 0, (? / ?) * 25, 0) +
                                      IF(? > 0, (? / ?) * 25, 0) +
                                      IF(? > 0, (? / ?) * 25, 0),
                                  comments = ?, status = 'submitted',
                                  updated_at = CURRENT_TIMESTAMP
                                  WHERE id = ?";

                    $stmt = $conn->prepare($update_sql);
                    if (!$stmt) {
                        throw new Exception("Prepare update failed: " . $conn->error);
                    }

                    // Type string breakdown (40 params):
                    // 8d  — cp,cp_max,as,as_max,ct,ct_max,gp,gp_max (SET raw/max)
                    // 3d  — cp_max, cp, cp_max  (scaled_scores class_practices IF)
                    // 3d  — as_max, as, as_max  (scaled_scores assignments IF)
                    // 3d  — ct_max, ct, ct_max  (scaled_scores cat IF)
                    // 3d  — gp_max, gp, gp_max  (scaled_scores group_project IF)
                    // 3d  — cp_max, cp, cp_max  (total_scaled cp)
                    // 3d  — as_max, as, as_max  (total_scaled as)
                    // 3d  — ct_max, ct, ct_max  (total_scaled ct)
                    // 3d  — gp_max, gp, gp_max  (total_scaled gp)
                    // 1s  — comment
                    // 1i  — WHERE id
                    // Total: 32d + 1s + 1i = 34 params
                    $cp_max = $max_scores['class_practices'];
                    $as_max = $max_scores['assignments'];
                    $ct_max = $max_scores['cat'];
                    $gp_max = $max_scores['group_project'];

                    $stmt->bind_param(
                        "ddddddddddddddddddddddddddddddddsi",
                        // SET clause (8)
                        $cp, $cp_max,
                        $as, $as_max,
                        $ct, $ct_max,
                        $gp, $gp_max,
                        // JSON_OBJECT scaled_scores (12: 3 per component × 4)
                        $cp_max, $cp, $cp_max,
                        $as_max, $as, $as_max,
                        $ct_max, $ct, $ct_max,
                        $gp_max, $gp, $gp_max,
                        // total_scaled (12: 3 per component × 4)
                        $cp_max, $cp, $cp_max,
                        $as_max, $as, $as_max,
                        $ct_max, $ct, $ct_max,
                        $gp_max, $gp, $gp_max,
                        // comment + WHERE id
                        $comment,
                        $existing_id
                    );
                    
                    if ($stmt->execute()) {
                        $updated++;
                    } else {
                        $errors_encountered[] = "Update failed for student $sid: " . $stmt->error;
                    }
                    $stmt->close();

                } else {
                    // -----------------------------------------------------------
                    // FIX 4 (INSERT): Corrected bind_param type string.
                    // Params: sid(i), teacher_id(i), class_id(i), subject_id(i),
                    //         term_id(i), academic_year(s), period_start(s),
                    //         period_end(s), cp(d), cp_max(d), as(d), as_max(d),
                    //         ct(d), ct_max(d), gp(d), gp_max(d), comment(s)
                    // = 5i + 3s + 8d + 1s = "iiiiisssdddddddds" (17 chars, 17 vars)
                    // -----------------------------------------------------------
                    $insert_sql = "INSERT INTO academic_progress_entries (
                        student_id, teacher_id, class_id, subject_id, term_id, 
                        academic_year, entry_period, period_start_date, period_end_date,
                        class_practices_raw, class_practices_max,
                        assignments_raw, assignments_max,
                        cat_raw, cat_max,
                        group_project_raw, group_project_max,
                        comments, status
                    ) VALUES (?, ?, ?, ?, ?, ?, 'bi-weekly', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'submitted')";
                    
                    $stmt = $conn->prepare($insert_sql);
                    if (!$stmt) {
                        throw new Exception("Prepare insert failed: " . $conn->error);
                    }
                    
                    // 5 ints + 1 string (academic_year) + 2 strings (dates)
                    // + 8 doubles (scores) + 1 string (comment) = "iiiiisssdddddddds"
                    $stmt->bind_param("iiiiisssdddddddds",
                        $sid, $teacher_id, $class_id, $subject_id, $term_id,
                        $current_academic_year, $period_start, $period_end,
                        $cp, $max_scores['class_practices'],
                        $as, $max_scores['assignments'],
                        $ct, $max_scores['cat'],
                        $gp, $max_scores['group_project'],
                        $comment
                    );
                    
                    if ($stmt->execute()) {
                        $inserted++;
                    } else {
                        $errors_encountered[] = "Insert failed for student $sid: " . $stmt->error;
                        error_log("Insert error for student $sid: " . $stmt->error);
                    }
                    $stmt->close();
                }
            }
            
            if (!empty($errors_encountered)) {
                error_log("Score submission errors: " . implode(' | ', $errors_encountered));
            }
            
            $conn->commit();
            
            logActivity($conn, $teacher_id, "Submitted Academic Scores", 
                       "Class: $class_id, Subject: $subject_id, Period: $period_start to $period_end - $inserted new, $updated updated");
            
            $message = "Scores saved successfully!";
            if ($inserted > 0 && $updated > 0) {
                $message = "Scores saved! ($inserted new entries, $updated updated)";
            } elseif ($inserted > 0) {
                $message = "Scores saved! ($inserted new entries)";
            } elseif ($updated > 0) {
                $message = "Scores updated! ($updated entries)";
            }
            
            if (!empty($errors_encountered)) {
                $message .= " Some entries had issues: " . implode('; ', array_slice($errors_encountered, 0, 3));
            }
            
            echo json_encode([
                'success' => true,
                'message' => $message,
                'inserted' => $inserted,
                'updated' => $updated,
                'errors_count' => count($errors_encountered)
            ]);
            
        } catch (Exception $e) {
            $conn->rollback();
            error_log("Submit error: " . $e->getMessage() . "\n" . $e->getTraceAsString());
            echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
        }
        exit();
    }
    
    echo json_encode(['success' => false, 'error' => 'Invalid action']);
    exit();
}

// Log page access
$ip = filter_var($_SERVER['REMOTE_ADDR'], FILTER_VALIDATE_IP) ?: 'unknown';
logActivity($conn, $teacher_id, "Accessed Academic Scores Page", "IP: $ip");

$has_roll_number = in_array('roll_number', $student_classes_columns);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Academic Scores Management - QueensPa School">
    <meta name="csrf-token" content="<?= htmlspecialchars($csrf_token) ?>">
    <title>Academic Scores | QueensPa School</title>
    
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Orbitron:wght@400;500;700;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.tailwindcss.com"></script>
    
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: {
                        sans: ['Inter', 'ui-sans-serif', 'system-ui'],
                        display: ['Orbitron', 'sans-serif'],
                    },
                    colors: {
                        brand: { 400: '#fb923c', 500: '#f97316', 600: '#ea580c', 700: '#c2410c' }
                    },
                    animation: {
                        'fade-in': 'fadeIn 0.5s ease-out',
                        'fade-in-up': 'fadeInUp 0.6s ease-out',
                        'slide-in': 'slideIn 0.4s ease-out',
                    },
                    keyframes: {
                        fadeIn: { '0%': { opacity: '0' }, '100%': { opacity: '1' } },
                        fadeInUp: { '0%': { opacity: '0', transform: 'translateY(20px)' }, '100%': { opacity: '1', transform: 'translateY(0)' } },
                        slideIn: { '0%': { transform: 'translateX(100%)', opacity: '0' }, '100%': { transform: 'translateX(0)', opacity: '1' } }
                    }
                }
            }
        }
    </script>
    
    <style>
        * { scrollbar-width: thin; scrollbar-color: #c2410c #1f2937; }
        *::-webkit-scrollbar { width: 6px; height: 6px; }
        *::-webkit-scrollbar-track { background: #1f2937; border-radius: 3px; }
        *::-webkit-scrollbar-thumb { background: linear-gradient(180deg, #f97316, #ea580c); border-radius: 3px; }
        
        body { font-family: 'Inter', sans-serif; -webkit-font-smoothing: antialiased; }
        h1, h2, h3, h4 { font-family: 'Orbitron', sans-serif; }
        
        .glass {
            background: rgba(255, 255, 255, 0.03);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 1rem;
        }
        
        .gradient-border { position: relative; }
        .gradient-border::before {
            content: '';
            position: absolute;
            inset: 0;
            border-radius: inherit;
            padding: 1px;
            background: linear-gradient(135deg, #f97316, #3b82f6, #f97316);
            -webkit-mask: linear-gradient(#fff 0 0) content-box, linear-gradient(#fff 0 0);
            -webkit-mask-composite: xor;
            mask-composite: exclude;
            pointer-events: none;
        }
        
        .input-field {
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(255, 255, 255, 0.1);
            color: white;
            border-radius: 0.5rem;
            padding: 0.625rem 0.875rem;
            transition: all 0.3s ease;
            font-size: 0.875rem;
            width: 100%;
        }
        .input-field:focus {
            outline: none;
            border-color: #f97316;
            box-shadow: 0 0 0 3px rgba(249, 115, 22, 0.2);
            background: rgba(255, 255, 255, 0.08);
        }
        
        .score-input {
            width: 100%;
            background: rgba(255, 255, 255, 0.03);
            border: 1px solid rgba(255, 255, 255, 0.08);
            color: #fff;
            border-radius: 0.375rem;
            padding: 0.5rem 0.625rem;
            text-align: center;
            font-size: 0.8125rem;
            font-weight: 500;
            transition: all 0.2s ease;
        }
        .score-input:focus {
            outline: none;
            border-color: #f97316;
            background: rgba(249, 115, 22, 0.1);
            box-shadow: 0 0 0 3px rgba(249, 115, 22, 0.15);
        }
        .score-input.error {
            border-color: #ef4444;
            background: rgba(239, 68, 68, 0.1);
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #f97316, #ea580c);
            color: white;
            border: none;
            border-radius: 0.5rem;
            padding: 0.625rem 1.5rem;
            font-weight: 600;
            font-size: 0.875rem;
            transition: all 0.3s ease;
            cursor: pointer;
        }
        .btn-primary:hover:not(:disabled) { transform: translateY(-2px); box-shadow: 0 10px 25px rgba(249, 115, 22, 0.4); }
        .btn-primary:disabled { opacity: 0.5; cursor: not-allowed; transform: none; }
        
        .btn-secondary {
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(255, 255, 255, 0.15);
            color: white;
            border-radius: 0.5rem;
            padding: 0.5rem 1.25rem;
            font-weight: 500;
            font-size: 0.875rem;
            transition: all 0.3s ease;
            cursor: pointer;
        }
        .btn-secondary:hover { background: rgba(255, 255, 255, 0.1); border-color: rgba(255, 255, 255, 0.25); }
        
        .badge {
            display: inline-flex;
            align-items: center;
            padding: 0.25rem 0.625rem;
            border-radius: 9999px;
            font-size: 0.6875rem;
            font-weight: 700;
            letter-spacing: 0.025em;
        }
        .badge-excellent { background: linear-gradient(135deg, #059669, #10b981); color: white; }
        .badge-good { background: linear-gradient(135deg, #2563eb, #3b82f6); color: white; }
        .badge-average { background: linear-gradient(135deg, #d97706, #f59e0b); color: white; }
        .badge-poor { background: linear-gradient(135deg, #dc2626, #ef4444); color: white; }
        
        .toast-notification {
            position: fixed;
            bottom: 1.5rem;
            right: 1.5rem;
            z-index: 9999;
            animation: slideInRight 0.4s ease-out;
        }
        @keyframes slideInRight {
            from { transform: translateX(120%); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }
        @keyframes slideOutRight {
            from { transform: translateX(0); opacity: 1; }
            to { transform: translateX(120%); opacity: 0; }
        }
        
        select.input-field {
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' fill='%23f97316' viewBox='0 0 16 16'%3E%3Cpath d='M8 11L3 6h10l-5 5z'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 0.75rem center;
            padding-right: 2.5rem;
        }
        select.input-field option { background: #1f2937; color: white; }
        textarea.input-field { resize: vertical; min-height: 2.5rem; }
        .table-sticky-header { position: sticky; top: 0; z-index: 10; }
        
        .submitting {
            pointer-events: none;
            opacity: 0.7;
        }
    </style>
</head>
<body class="min-h-screen bg-gradient-to-br from-gray-950 via-gray-900 to-orange-950">
    
    <div id="loading-overlay" class="fixed inset-0 bg-black bg-opacity-80 z-50 flex items-center justify-center" style="display: none;">
        <div class="text-center">
            <div class="inline-block w-16 h-16 border-4 border-orange-500 border-t-transparent rounded-full animate-spin"></div>
            <p class="text-white mt-4 font-medium">Loading...</p>
        </div>
    </div>
    
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/templates/teacher_header.php'; ?>
    
    <main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        
        <div class="mb-8 animate-fade-in">
            <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
                <div>
                    <h1 class="text-3xl md:text-4xl font-bold text-white mb-2">
                        <i class="fas fa-file-alt text-orange-500 mr-3"></i>
                        Academic Scores
                    </h1>
                    <p class="text-gray-400 text-sm">Record and manage bi-weekly student performance metrics</p>
                </div>
                <div class="flex items-center gap-4 flex-wrap">
                    <div class="glass px-4 py-2 text-sm flex items-center gap-2">
                        <i class="fas fa-calendar-alt text-orange-400"></i>
                        <span class="text-gray-300"><?= htmlspecialchars($current_academic_year) ?></span>
                    </div>
                    <div class="glass px-4 py-2 text-sm flex items-center gap-2">
                        <i class="fas fa-clock text-orange-400"></i>
                        <span class="text-gray-300"><?= htmlspecialchars($current_term_name) ?></span>
                    </div>
                    <div class="glass px-4 py-2 text-sm flex items-center gap-2">
                        <i class="fas fa-user-circle text-orange-400"></i>
                        <span class="text-gray-300"><?= htmlspecialchars($teacher['name'] ?? 'Teacher') ?></span>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="glass p-6 mb-6 animate-fade-in-up gradient-border">
            <h2 class="text-lg font-semibold text-white mb-5 flex items-center">
                <i class="fas fa-sliders-h text-orange-400 mr-2"></i>
                Selection Criteria
            </h2>
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-4">
                <div>
                    <label class="block text-xs font-semibold text-gray-400 uppercase tracking-wider mb-1.5">Class</label>
                    <select id="class-select" class="input-field">
                        <option value="">Choose Class</option>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-semibold text-gray-400 uppercase tracking-wider mb-1.5">Subject</label>
                    <select id="subject-select" class="input-field" disabled>
                        <option value="">Select Class First</option>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-semibold text-gray-400 uppercase tracking-wider mb-1.5">Term</label>
                    <select id="term-select" class="input-field">
                        <option value="">Choose Term</option>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-semibold text-gray-400 uppercase tracking-wider mb-1.5">Period</label>
                    <select id="period-select" class="input-field" disabled>
                        <option value="">Select Term First</option>
                    </select>
                </div>
                <div class="flex items-end">
                    <button id="load-btn" class="btn-primary w-full" disabled>
                        <i class="fas fa-download mr-2"></i>Load Students
                    </button>
                </div>
            </div>
        </div>
        
        <div id="max-panel" class="glass p-6 mb-6 animate-fade-in-up" style="display: none;">
            <div class="flex items-center justify-between mb-4">
                <h2 class="text-lg font-semibold text-white flex items-center">
                    <i class="fas fa-sliders text-orange-400 mr-2"></i>Maximum Scores
                </h2>
                <button id="apply-max-btn" class="btn-secondary text-sm" disabled>
                    <i class="fas fa-fill-drip mr-2"></i>Apply Max to All
                </button>
            </div>
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                <div>
                    <label class="block text-xs font-semibold text-gray-400 uppercase tracking-wider mb-1.5">Class Practices (Max)</label>
                    <input type="number" id="cp-max" class="input-field max-input" placeholder="100" step="0.01" min="1" value="100">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-gray-400 uppercase tracking-wider mb-1.5">Assignments (Max)</label>
                    <input type="number" id="as-max" class="input-field max-input" placeholder="100" step="0.01" min="1" value="100">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-gray-400 uppercase tracking-wider mb-1.5">CAT (Max)</label>
                    <input type="number" id="cat-max" class="input-field max-input" placeholder="100" step="0.01" min="1" value="100">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-gray-400 uppercase tracking-wider mb-1.5">Group/Project (Max)</label>
                    <input type="number" id="gp-max" class="input-field max-input" placeholder="100" step="0.01" min="1" value="100">
                </div>
            </div>
        </div>
        
        <div id="scores-panel" class="glass p-6 animate-fade-in-up" style="display: none;">
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between mb-4 gap-3">
                <h2 class="text-lg font-semibold text-white flex items-center">
                    <i class="fas fa-users text-orange-400 mr-2"></i>Student Scores
                    <span id="count-badge" class="badge badge-excellent ml-3">0 Students</span>
                </h2>
                <div class="flex gap-3">
                    <button id="reset-btn" class="btn-secondary text-sm">
                        <i class="fas fa-undo-alt mr-2"></i>Reset
                    </button>
                    <button id="submit-btn" class="btn-primary" disabled>
                        <i class="fas fa-save mr-2"></i>
                        <span id="submit-text">Submit Scores</span>
                        <span id="spinner" class="inline-block w-4 h-4 border-2 border-white border-t-transparent rounded-full animate-spin ml-2" style="display: none;"></span>
                    </button>
                </div>
            </div>
            
            <div class="overflow-x-auto rounded-xl" style="max-height: 60vh; overflow-y: auto;">
                <table class="w-full text-sm">
                    <thead class="table-sticky-header">
                        <tr class="bg-gradient-to-r from-orange-600 to-orange-700 text-white">
                            <th class="px-3 py-3 text-left text-xs uppercase rounded-tl-lg">#</th>
                            <th class="px-3 py-3 text-left text-xs uppercase">Student</th>
                            <th class="px-3 py-3 text-center text-xs uppercase">Class Pract.</th>
                            <th class="px-3 py-3 text-center text-xs uppercase bg-orange-800/40">/25</th>
                            <th class="px-3 py-3 text-center text-xs uppercase">Assign.</th>
                            <th class="px-3 py-3 text-center text-xs uppercase bg-orange-800/40">/25</th>
                            <th class="px-3 py-3 text-center text-xs uppercase">CAT</th>
                            <th class="px-3 py-3 text-center text-xs uppercase bg-orange-800/40">/25</th>
                            <th class="px-3 py-3 text-center text-xs uppercase">Group Proj.</th>
                            <th class="px-3 py-3 text-center text-xs uppercase bg-orange-800/40">/25</th>
                            <th class="px-3 py-3 text-center text-xs uppercase">Total/100</th>
                            <th class="px-3 py-3 text-left text-xs uppercase rounded-tr-lg">Comments</th>
                        </tr>
                    </thead>
                    <tbody id="scores-tbody">
                        <tr>
                            <td colspan="12" class="px-4 py-16 text-center text-gray-500">
                                <i class="fas fa-arrow-up text-4xl block mb-3 opacity-50"></i>
                                Select criteria and click "Load Students"
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            
            <div id="submit-status" class="mt-4 text-sm" style="display: none;"></div>
        </div>
        
    </main>
    
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/templates/teacher_footer.php'; ?>
    
    <script>
    (function() {
        'use strict';
        
        // FIX 2 (JS side): CSRF is read from the meta tag on load.
        // The PHP now never rotates this token mid-session, so it stays valid.
        // As an extra safeguard, get_initial_data returns the current token and
        // we update CSRF here so any edge-case rotation is self-healing.
        let CSRF = document.querySelector('meta[name="csrf-token"]')?.content || '';
        const HAS_ROLL_NUMBER = <?= $has_roll_number ? 'true' : 'false' ?>;
        let initData = null, students = [], existingScores = {};
        let isSubmitting = false;
        
        const $ = s => document.querySelector(s);
        const $$ = s => document.querySelectorAll(s);
        
        const overlay = $('#loading-overlay');
        const classSel = $('#class-select');
        const subjSel = $('#subject-select');
        const termSel = $('#term-select');
        const periodSel = $('#period-select');
        const loadBtn = $('#load-btn');
        const maxPanel = $('#max-panel');
        const scoresPanel = $('#scores-panel');
        const tbody = $('#scores-tbody');
        const submitBtn = $('#submit-btn');
        const submitText = $('#submit-text');
        const spinner = $('#spinner');
        const applyMax = $('#apply-max-btn');
        const countBadge = $('#count-badge');
        const submitStatus = $('#submit-status');
        
        const maxInputs = {
            cp: $('#cp-max'), as: $('#as-max'), cat: $('#cat-max'), gp: $('#gp-max')
        };
        
        function loading(show) { 
            overlay.style.display = show ? 'flex' : 'none'; 
        }
        
        function toast(msg, type = 'info') {
            const t = document.createElement('div');
            t.className = 'toast-notification';
            const colors = { 
                success: 'from-green-500 to-emerald-600', 
                error: 'from-red-500 to-rose-600', 
                info: 'from-blue-500 to-indigo-600', 
                warning: 'from-yellow-500 to-amber-600' 
            };
            const icons = { 
                success: 'fa-check-circle', 
                error: 'fa-exclamation-circle', 
                info: 'fa-info-circle', 
                warning: 'fa-exclamation-triangle' 
            };
            t.innerHTML = '<div class="bg-gradient-to-r ' + colors[type] + ' text-white px-6 py-3.5 rounded-xl shadow-2xl flex items-center gap-3"><i class="fas ' + icons[type] + ' text-lg"></i><span class="font-medium text-sm">' + msg + '</span></div>';
            document.body.appendChild(t);
            setTimeout(function() { 
                t.style.animation = 'slideOutRight 0.4s ease-out forwards'; 
                setTimeout(function() { t.remove(); }, 400); 
            }, 4000);
        }
        
        function esc(s) { 
            const d = document.createElement('div'); 
            d.textContent = s || ''; 
            return d.innerHTML; 
        }
        
        function checkLoad() { 
            loadBtn.disabled = !(classSel.value && subjSel.value && termSel.value && periodSel.value); 
        }
        
        function scaleBadge(v) { 
            return v >= 20 ? 'badge-excellent' : v >= 15 ? 'badge-good' : v >= 10 ? 'badge-average' : 'badge-poor'; 
        }
        
        function totalBadge(v) { 
            return v >= 80 ? 'badge-excellent' : v >= 65 ? 'badge-good' : v >= 50 ? 'badge-average' : 'badge-poor'; 
        }
        
        function autoComment(v) { 
            return v >= 80 ? 'Excellent!' : v >= 65 ? 'Good work.' : v >= 50 ? 'Satisfactory.' : 'Needs improvement.'; 
        }
        
        async function apiFetch(params) {
            try {
                const response = await fetch('?' + new URLSearchParams(params));
                if (!response.ok) {
                    throw new Error('HTTP ' + response.status + ': ' + response.statusText);
                }
                const text = await response.text();
                try {
                    return JSON.parse(text);
                } catch (e) {
                    console.error('Invalid JSON response:', text.substring(0, 200));
                    throw new Error('Invalid server response');
                }
            } catch (error) {
                console.error('API fetch error:', error);
                throw error;
            }
        }
        
        async function init() {
            loading(true);
            try {
                const r = await apiFetch({ action: 'get_initial_data' });
                if (r.success) {
                    initData = r.data;

                    // FIX 2 (JS): Sync CSRF from server response to stay current.
                    if (initData.csrf_token) {
                        CSRF = initData.csrf_token;
                        const meta = document.querySelector('meta[name="csrf-token"]');
                        if (meta) meta.content = CSRF;
                    }
                    
                    classSel.innerHTML = '<option value="">Choose Class</option>';
                    initData.classes.forEach(function(c) {
                        classSel.innerHTML += '<option value="' + c.id + '">' + esc(c.class_name) + (c.department_name ? ' (' + esc(c.department_name) + ')' : '') + '</option>';
                    });
                    
                    subjSel.innerHTML = '<option value="">Select Class First</option>';
                    initData.subjects.forEach(function(s) {
                        subjSel.innerHTML += '<option value="' + s.id + '">' + esc(s.subject_name) + ' (' + esc(s.subject_code || 'N/A') + ')</option>';
                    });
                    
                    termSel.innerHTML = '<option value="">Choose Term</option>';
                    initData.terms.forEach(function(t) {
                        var sel = t.id === initData.current_term_id ? ' selected' : '';
                        var s = new Date(t.start_date).toLocaleDateString('en-US', {month:'short',day:'numeric'});
                        var e = new Date(t.end_date).toLocaleDateString('en-US', {month:'short',day:'numeric',year:'numeric'});
                        termSel.innerHTML += '<option value="' + t.id + '"' + sel + '>' + esc(t.term_name) + ' (' + s + ' - ' + e + ')</option>';
                    });
                    
                    if (initData.periods && initData.periods.length) {
                        periodSel.innerHTML = '<option value="">Select Period</option>';
                        initData.periods.forEach(function(p) {
                            periodSel.innerHTML += '<option value="' + p.id + '" data-start="' + p.start_date + '" data-end="' + p.end_date + '">' + p.label + '</option>';
                        });
                        periodSel.disabled = false;
                    }
                    
                    classSel.onchange = function() { 
                        subjSel.disabled = !classSel.value; 
                        if(!classSel.value) subjSel.value = ''; 
                        checkLoad(); 
                    };
                    
                    termSel.onchange = async function() {
                        periodSel.innerHTML = '<option value="">Loading...</option>'; 
                        periodSel.disabled = true;
                        if (termSel.value) {
                            try {
                                const rd = await apiFetch({ action: 'get_periods', term_id: termSel.value });
                                if (rd.success && rd.periods.length) {
                                    periodSel.innerHTML = '<option value="">Select Period</option>';
                                    rd.periods.forEach(function(p) {
                                        periodSel.innerHTML += '<option value="' + p.id + '" data-start="' + p.start_date + '" data-end="' + p.end_date + '">' + p.label + '</option>';
                                    });
                                    periodSel.disabled = false;
                                } else {
                                    periodSel.innerHTML = '<option value="">No periods available</option>';
                                }
                            } catch(e) {
                                periodSel.innerHTML = '<option value="">Error loading periods</option>';
                                toast('Error loading periods', 'error');
                            }
                        } else {
                            periodSel.innerHTML = '<option value="">Select Term First</option>';
                        }
                        checkLoad();
                    };
                    
                    periodSel.onchange = checkLoad;
                    subjSel.onchange = checkLoad;
                } else {
                    toast('Error loading initial data: ' + (r.error || 'Unknown error'), 'error');
                }
            } catch(e) { 
                toast('Error loading data: ' + e.message, 'error'); 
                console.error('Init error:', e);
            }
            loading(false);
        }
        
        loadBtn.onclick = async function() {
            if (loadBtn.disabled || isSubmitting) return;
            
            const periodOpt = periodSel.options[periodSel.selectedIndex];
            const sd = periodOpt?.dataset?.start;
            const ed = periodOpt?.dataset?.end;
            if (!sd) { 
                toast('Please select a period', 'warning'); 
                return; 
            }
            
            loading(true);
            tbody.innerHTML = '<tr><td colspan="12" class="px-4 py-16 text-center text-gray-400"><div class="inline-block w-8 h-8 border-2 border-orange-500 border-t-transparent rounded-full animate-spin mb-3"></div><p>Loading students...</p></td></tr>';
            maxPanel.style.display = 'none'; 
            scoresPanel.style.display = 'block';
            submitStatus.style.display = 'none';
            
            try {
                const rd = await apiFetch({ 
                    action: 'get_students', 
                    class_id: classSel.value, 
                    subject_id: subjSel.value, 
                    term_id: termSel.value, 
                    start_date: sd, 
                    end_date: ed 
                });
                
                if (rd.success) {
                    students = rd.students || []; 
                    existingScores = rd.existing_scores || {};
                    
                    if (students.length === 0) {
                        tbody.innerHTML = '<tr><td colspan="12" class="px-4 py-16 text-center text-gray-500"><i class="fas fa-user-slash text-4xl block mb-3 opacity-50"></i>No students found in this class</td></tr>';
                        maxPanel.style.display = 'none';
                        submitBtn.disabled = true;
                        countBadge.textContent = '0 Students';
                        loading(false);
                        return;
                    }
                    
                    const sv = Object.values(existingScores);
                    if (sv.length && sv[0]) {
                        const f = sv[0];
                        maxInputs.cp.value = f.class_practices_max || 100;
                        maxInputs.as.value = f.assignments_max || 100;
                        maxInputs.cat.value = f.cat_max || 100;
                        maxInputs.gp.value = f.group_project_max || 100;
                    } else {
                        maxInputs.cp.value = 100;
                        maxInputs.as.value = 100;
                        maxInputs.cat.value = 100;
                        maxInputs.gp.value = 100;
                    }
                    
                    renderTable();
                    maxPanel.style.display = 'block'; 
                    applyMax.disabled = false;
                    countBadge.textContent = students.length + ' Student' + (students.length !== 1 ? 's' : '');
                    submitBtn.disabled = false;
                    toast('Loaded ' + students.length + ' student(s)', 'success');
                } else {
                    tbody.innerHTML = '<tr><td colspan="12" class="px-4 py-16 text-center text-gray-500">' + esc(rd.error || 'Failed to load students') + '</td></tr>';
                    maxPanel.style.display = 'none';
                    submitBtn.disabled = true;
                    toast(rd.error || 'Error loading students', 'error');
                }
            } catch(e) { 
                tbody.innerHTML = '<tr><td colspan="12" class="px-4 py-16 text-center text-gray-500 text-red-400">Error: ' + esc(e.message) + '</td></tr>';
                toast('Error: ' + e.message, 'error'); 
                console.error('Load students error:', e);
            }
            loading(false);
        };
        
        function renderTable() {
            tbody.innerHTML = '';
            if (!students.length) { 
                tbody.innerHTML = '<tr><td colspan="12" class="px-4 py-16 text-center text-gray-500">No students</td></tr>'; 
                return; 
            }
            
            students.forEach(function(st, i) {
                const sc = existingScores[st.id] || {};
                const cm = parseFloat(maxInputs.cp.value) || 100;
                const am = parseFloat(maxInputs.as.value) || 100;
                const tm = parseFloat(maxInputs.cat.value) || 100;
                const gm = parseFloat(maxInputs.gp.value) || 100;
                
                const cr = parseFloat(sc.class_practices_raw) || 0;
                const ar = parseFloat(sc.assignments_raw) || 0;
                const tr = parseFloat(sc.cat_raw) || 0;
                const gr = parseFloat(sc.group_project_raw) || 0;
                
                const cs = cm > 0 ? (cr/cm)*25 : 0;
                const as2 = am > 0 ? (ar/am)*25 : 0;
                const ts = tm > 0 ? (tr/tm)*25 : 0;
                const gs = gm > 0 ? (gr/gm)*25 : 0;
                const total = cs + as2 + ts + gs;
                const comment = sc.comments || autoComment(total);
                
                let studentInfo = esc(st.name);
                if (HAS_ROLL_NUMBER && st.roll_number) {
                    studentInfo += '<br><span class="text-gray-500 text-xs">#' + esc(st.roll_number) + '</span>';
                }
                
                const row = document.createElement('tr');
                row.className = 'border-b border-gray-800 hover:bg-white/5 transition-colors';
                row.dataset.sid = st.id;
                row.innerHTML = 
                    '<td class="px-3 py-2.5 text-gray-500 text-xs">' + (i+1) + '</td>' +
                    '<td class="px-3 py-2.5 text-white font-medium text-xs">' + studentInfo + '</td>' +
                    '<td class="px-2 py-2.5"><input type="number" class="score-input rs" data-c="cp" value="' + cr + '" step="0.01" min="0" max="' + cm + '"></td>' +
                    '<td class="px-2 py-2.5 text-center"><span class="badge ' + scaleBadge(cs) + '">' + cs.toFixed(1) + '</span></td>' +
                    '<td class="px-2 py-2.5"><input type="number" class="score-input rs" data-c="as" value="' + ar + '" step="0.01" min="0" max="' + am + '"></td>' +
                    '<td class="px-2 py-2.5 text-center"><span class="badge ' + scaleBadge(as2) + '">' + as2.toFixed(1) + '</span></td>' +
                    '<td class="px-2 py-2.5"><input type="number" class="score-input rs" data-c="cat" value="' + tr + '" step="0.01" min="0" max="' + tm + '"></td>' +
                    '<td class="px-2 py-2.5 text-center"><span class="badge ' + scaleBadge(ts) + '">' + ts.toFixed(1) + '</span></td>' +
                    '<td class="px-2 py-2.5"><input type="number" class="score-input rs" data-c="gp" value="' + gr + '" step="0.01" min="0" max="' + gm  + '"></td>' +
                    '<td class="px-2 py-2.5 text-center"><span class="badge ' + scaleBadge(gs) + '">' + gs.toFixed(1) + '</span></td>' +
                    '<td class="px-2 py-2.5 text-center"><span id="total-' + st.id + '" class="badge ' + totalBadge(total) + ' text-sm px-3">' + total.toFixed(1) + '</span></td>' +
                    '<td class="px-2 py-2.5"><textarea class="score-input comment-input" data-c="cmt" rows="1" style="resize:none;overflow:hidden;min-height:2rem;">' + esc(comment) + '</textarea></td>';
                
                tbody.appendChild(row);
            });
            
            // Live score updating
            tbody.querySelectorAll('.rs').forEach(function(inp) {
                inp.addEventListener('input', function() {
                    updateRow(inp.closest('tr'));
                });
            });
            
            // Auto-resize comment textareas
            tbody.querySelectorAll('.comment-input').forEach(function(ta) {
                ta.addEventListener('input', function() {
                    ta.style.height = 'auto';
                    ta.style.height = ta.scrollHeight + 'px';
                });
            });
        }
        
        function updateRow(row) {
            const sid = row.dataset.sid;
            const cm = parseFloat(maxInputs.cp.value) || 100;
            const am = parseFloat(maxInputs.as.value) || 100;
            const tm = parseFloat(maxInputs.cat.value) || 100;
            const gm = parseFloat(maxInputs.gp.value) || 100;
            
            const cpInp  = row.querySelector('[data-c="cp"]');
            const asInp  = row.querySelector('[data-c="as"]');
            const catInp = row.querySelector('[data-c="cat"]');
            const gpInp  = row.querySelector('[data-c="gp"]');
            
            const cr = parseFloat(cpInp?.value)  || 0;
            const ar = parseFloat(asInp?.value)  || 0;
            const tr = parseFloat(catInp?.value) || 0;
            const gr = parseFloat(gpInp?.value)  || 0;
            
            // Clamp and flag errors
            [{ el: cpInp, max: cm }, { el: asInp, max: am },
             { el: catInp, max: tm }, { el: gpInp, max: gm }].forEach(function(o) {
                if (!o.el) return;
                const v = parseFloat(o.el.value);
                o.el.classList.toggle('error', v < 0 || v > o.max);
            });
            
            const cs   = cm > 0 ? (cr / cm) * 25 : 0;
            const as2  = am > 0 ? (ar / am) * 25 : 0;
            const ts   = tm > 0 ? (tr / tm) * 25 : 0;
            const gs   = gm > 0 ? (gr / gm) * 25 : 0;
            const total = cs + as2 + ts + gs;
            
            // Update scaled badges in the same row
            const badges = row.querySelectorAll('td .badge');
            // badges[0] = cp scaled, [1] = as scaled, [2] = cat scaled, [3] = gp scaled
            const scaledVals = [cs, as2, ts, gs];
            let bi = 0;
            badges.forEach(function(b) {
                if (b.id && b.id.startsWith('total-')) return; // skip total cell
                if (bi < 4) {
                    b.className = 'badge ' + scaleBadge(scaledVals[bi]);
                    b.textContent = scaledVals[bi].toFixed(1);
                    bi++;
                }
            });
            
            const totalBadgeEl = row.querySelector('#total-' + sid);
            if (totalBadgeEl) {
                totalBadgeEl.className = 'badge ' + totalBadge(total) + ' text-sm px-3';
                totalBadgeEl.textContent = total.toFixed(1);
            }
            
            // Auto-update comment if it hasn't been manually changed
            const cmtInput = row.querySelector('.comment-input');
            if (cmtInput && !cmtInput.dataset.manuallyEdited) {
                cmtInput.value = autoComment(total);
            }
        }
        
        // Mark comment as manually edited when user types
        tbody.addEventListener('input', function(e) {
            if (e.target.classList.contains('comment-input')) {
                e.target.dataset.manuallyEdited = '1';
            }
        });
        
        // Apply max scores to all inputs and re-render
        applyMax.onclick = function() {
            if (!students.length) return;
            const cm = parseFloat(maxInputs.cp.value) || 100;
            const am = parseFloat(maxInputs.as.value) || 100;
            const tm = parseFloat(maxInputs.cat.value) || 100;
            const gm = parseFloat(maxInputs.gp.value) || 100;
            
            tbody.querySelectorAll('tr[data-sid]').forEach(function(row) {
                row.querySelector('[data-c="cp"]')?.setAttribute('max', cm);
                row.querySelector('[data-c="as"]')?.setAttribute('max', am);
                row.querySelector('[data-c="cat"]')?.setAttribute('max', tm);
                row.querySelector('[data-c="gp"]')?.setAttribute('max', gm);
                updateRow(row);
            });
            toast('Maximum scores applied to all rows', 'info');
        };
        
        // Re-run updateRow for every student row when a max input changes
        $$('.max-input').forEach(function(inp) {
            inp.addEventListener('input', function() {
                tbody.querySelectorAll('tr[data-sid]').forEach(function(row) {
                    updateRow(row);
                });
            });
        });
        
        // Reset all raw scores to 0
        $('#reset-btn').onclick = function() {
            if (!students.length) return;
            if (!confirm('Reset all scores to 0? This does not save to the database.')) return;
            tbody.querySelectorAll('tr[data-sid]').forEach(function(row) {
                row.querySelectorAll('.rs').forEach(function(inp) {
                    inp.value = 0;
                    inp.classList.remove('error');
                });
                const cmtInput = row.querySelector('.comment-input');
                if (cmtInput) {
                    cmtInput.value = '';
                    delete cmtInput.dataset.manuallyEdited;
                }
                updateRow(row);
            });
            toast('All scores reset', 'warning');
        };
        
        // Submit scores
        submitBtn.onclick = async function() {
            if (submitBtn.disabled || isSubmitting) return;
            
            // Validate max scores
            const maxVals = {
                class_practices: parseFloat(maxInputs.cp.value)  || 0,
                assignments:     parseFloat(maxInputs.as.value)  || 0,
                cat:             parseFloat(maxInputs.cat.value) || 0,
                group_project:   parseFloat(maxInputs.gp.value) || 0
            };
            for (const [k, v] of Object.entries(maxVals)) {
                if (v <= 0) {
                    toast('Maximum score for "' + k.replace(/_/g,' ') + '" must be greater than 0', 'error');
                    return;
                }
            }
            
            // Check for validation errors in inputs
            const errorInputs = tbody.querySelectorAll('.score-input.error');
            if (errorInputs.length) {
                toast(errorInputs.length + ' score(s) exceed their maximum value. Please correct them first.', 'error');
                errorInputs[0].focus();
                return;
            }
            
            const periodOpt = periodSel.options[periodSel.selectedIndex];
            const sd = periodOpt?.dataset?.start;
            const ed = periodOpt?.dataset?.end;
            if (!sd || !ed) {
                toast('Please select a valid period', 'warning');
                return;
            }
            
            // Collect student data
            const studentsPayload = [];
            tbody.querySelectorAll('tr[data-sid]').forEach(function(row) {
                const sid = parseInt(row.dataset.sid);
                if (!sid) return;
                studentsPayload.push({
                    id:                  sid,
                    class_practices_raw: parseFloat(row.querySelector('[data-c="cp"]')?.value)  || 0,
                    assignments_raw:     parseFloat(row.querySelector('[data-c="as"]')?.value)  || 0,
                    cat_raw:             parseFloat(row.querySelector('[data-c="cat"]')?.value) || 0,
                    group_project_raw:   parseFloat(row.querySelector('[data-c="gp"]')?.value)  || 0,
                    comments:            (row.querySelector('.comment-input')?.value || '').trim()
                });
            });
            
            if (!studentsPayload.length) {
                toast('No student data to submit', 'warning');
                return;
            }
            
            // UI: submitting state
            isSubmitting = true;
            submitBtn.disabled = true;
            submitText.textContent = 'Saving…';
            spinner.style.display = 'inline-block';
            submitStatus.style.display = 'none';
            document.querySelector('form, .submitting')?.classList.add('submitting');
            
            try {
                const formData = new FormData();
                formData.append('action',            'submit_scores');
                formData.append('csrf_token',        CSRF);
                formData.append('class_id',          classSel.value);
                formData.append('subject_id',        subjSel.value);
                formData.append('term_id',           termSel.value);
                formData.append('period_start_date', sd);
                formData.append('period_end_date',   ed);
                formData.append('class_practices_max', maxVals.class_practices);
                formData.append('assignments_max',     maxVals.assignments);
                formData.append('cat_max',             maxVals.cat);
                formData.append('group_project_max',   maxVals.group_project);
                formData.append('students',            JSON.stringify(studentsPayload));
                
                const response = await fetch(window.location.pathname, {
                    method: 'POST',
                    body: formData
                });
                
                if (!response.ok) {
                    throw new Error('HTTP ' + response.status + ': ' + response.statusText);
                }
                
                const text = await response.text();
                let result;
                try {
                    result = JSON.parse(text);
                } catch(e) {
                    console.error('Invalid JSON from submit:', text.substring(0, 300));
                    throw new Error('Invalid server response');
                }
                
                if (result.success) {
                    toast(result.message || 'Scores saved successfully!', 'success');
                    submitStatus.style.display = 'block';
                    submitStatus.className = 'mt-4 text-sm p-4 rounded-xl bg-green-900/30 border border-green-700/40 text-green-300';
                    submitStatus.innerHTML =
                        '<i class="fas fa-check-circle mr-2"></i>' +
                        esc(result.message || 'Scores saved successfully!') +
                        (result.errors_count > 0
                            ? '<span class="ml-3 text-yellow-400"><i class="fas fa-exclamation-triangle mr-1"></i>' + result.errors_count + ' partial error(s) — check server log</span>'
                            : '');
                    
                    // Reload existing scores silently to reflect DB state
                    try {
                        const rd = await apiFetch({
                            action:     'get_students',
                            class_id:   classSel.value,
                            subject_id: subjSel.value,
                            term_id:    termSel.value,
                            start_date: sd,
                            end_date:   ed
                        });
                        if (rd.success) {
                            existingScores = rd.existing_scores || {};
                        }
                    } catch(e) {
                        // non-critical — scores are already saved
                    }
                } else {
                    // Handle CSRF rotation edge-case: refresh token and tell user
                    if ((result.error || '').toLowerCase().includes('security token')) {
                        toast('Session token expired. Please reload the page and try again.', 'error');
                    } else {
                        toast(result.error || 'Failed to save scores', 'error');
                    }
                    submitStatus.style.display = 'block';
                    submitStatus.className = 'mt-4 text-sm p-4 rounded-xl bg-red-900/30 border border-red-700/40 text-red-300';
                    submitStatus.innerHTML = '<i class="fas fa-exclamation-circle mr-2"></i>' + esc(result.error || 'Submission failed');
                }
                
            } catch(e) {
                toast('Network error: ' + e.message, 'error');
                console.error('Submit error:', e);
                submitStatus.style.display = 'block';
                submitStatus.className = 'mt-4 text-sm p-4 rounded-xl bg-red-900/30 border border-red-700/40 text-red-300';
                submitStatus.innerHTML = '<i class="fas fa-exclamation-circle mr-2"></i>Network error: ' + esc(e.message);
            } finally {
                isSubmitting = false;
                submitBtn.disabled = false;
                submitText.textContent = 'Submit Scores';
                spinner.style.display = 'none';
            }
        };
        
        // Keyboard shortcut: Ctrl+S / Cmd+S to submit
        document.addEventListener('keydown', function(e) {
            if ((e.ctrlKey || e.metaKey) && e.key === 's') {
                e.preventDefault();
                if (!submitBtn.disabled && !isSubmitting && students.length) {
                    submitBtn.click();
                }
            }
        });
        
        // Initialise on DOM ready
        init();
        
    })();
    </script>
</body>
</html>
<?php
// End of input_academic_scores.php
if (isset($conn) && $conn instanceof mysqli) {
    $conn->close();
}
?>
 