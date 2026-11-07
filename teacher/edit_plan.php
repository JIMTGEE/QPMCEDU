<?php
ob_start();
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in and is a teacher
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'teacher') {
    error_log("Unauthorized access attempt: user_id=" . ($_SESSION['user_id'] ?? 'unset'));
    $_SESSION['error_msg'] = "Please log in as a teacher.";
    header("Location: /login.php");
    exit();
}

// Include database connection
try {
    require_once($_SERVER['DOCUMENT_ROOT'] . '/config/db.php');
} catch (Exception $e) {
    error_log("Failed to include db.php: " . $e->getMessage());
    $_SESSION['error_msg'] = "System error: Unable to connect to database.";
    header("Location: /login.php");
    exit();
}

if (!$conn || mysqli_connect_error()) {
    error_log("Database connection failed: " . mysqli_connect_error());
    $_SESSION['error_msg'] = "System error: Database connection failed.";
    header("Location: /login.php");
    exit();
}

$teacher_id = (int)$_SESSION['user_id'];

// Validate teacher exists and fetch department
$teacher_sql = "SELECT id, name, department_id FROM users WHERE id = ? AND role = 'teacher'";
$teacher_stmt = $conn->prepare($teacher_sql);
$teacher_stmt->bind_param("i", $teacher_id);
$teacher_stmt->execute();
$teacher = $teacher_stmt->get_result()->fetch_assoc();
$teacher_stmt->close();

if (!$teacher) {
    error_log("Teacher not found for user_id: $teacher_id");
    $_SESSION['error_msg'] = "Teacher account not found.";
    header("Location: /login.php");
    exit();
}
$teacher_department_id = $teacher['department_id'];

// Get format from URL parameter
$format = isset($_GET['format']) ? trim($_GET['format']) : 'ges';
if (!in_array($format, ['oxford', 'ges'])) {
    $format = 'ges';
}

// -----------------------------------------------------------------------
// FIX 1: Handle AJAX BEFORE plan_id validation so the redirect never fires
// -----------------------------------------------------------------------
if (isset($_GET['action']) && $_GET['action'] === 'get_schedule_details') {
    $schedule_id = isset($_GET['schedule_id']) ? (int)$_GET['schedule_id'] : 0;
    $response = ['schedule' => null, 'error' => '', 'debug' => ''];

    if ($schedule_id <= 0) {
        $response['error'] = 'Invalid schedule ID.';
        $response['debug'] = 'Schedule ID: ' . $schedule_id;
    } else {
        $schedule_sql = "SELECT cs.id, cs.class_id, cs.subject_id, cs.term_id, cs.academic_year, cs.department_id,
                         c.class_name, s.subject_name, d.name AS department_name,
                         CONCAT(cs.day_of_week, ' ', cs.start_time, ' - ', cs.end_time) AS time_slot
                         FROM class_schedules cs
                         JOIN classes c ON cs.class_id = c.id
                         LEFT JOIN subjects s ON cs.subject_id = s.id
                         LEFT JOIN departments d ON cs.department_id = d.id
                         WHERE cs.id = ? AND cs.teacher_id = ?";
        $schedule_stmt = $conn->prepare($schedule_sql);
        $schedule_stmt->bind_param("ii", $schedule_id, $teacher_id);

        if ($schedule_stmt->execute()) {
            $schedule = $schedule_stmt->get_result()->fetch_assoc();
            if ($schedule) {
                // Class name mapping inline
                $class_name_map = [
                    'Esther Foundation' => 'Creche',
                    'Sheba 1'           => 'Nursery 1',
                    'Sheba 2'           => 'Nursery 2',
                    'Yaasant 1'         => 'KG 1',
                    'Yaasant 2'         => 'KG 2',
                    'Cleopatra 1'       => 'Basic 1',
                    'Cleopatra 2'       => 'Basic 2',
                    'Nefertiti 1'       => 'Basic 3',
                    'Nefertiti 2'       => 'Basic 4',
                    'Malika 1'          => 'Basic 5',
                    'Malika 2'          => 'Basic 6',
                    'Makeda 1'          => 'Basic 7',
                    'Makeda 2'          => 'Basic 8',
                    'An Majesty'        => 'Basic 9',
                ];
                $display_name = $class_name_map[$schedule['class_name']] ?? $schedule['class_name'];

                // Get term name
                $term_sql  = "SELECT term_name FROM academic_calendar WHERE id = ?";
                $term_stmt = $conn->prepare($term_sql);
                $term_stmt->bind_param("i", $schedule['term_id']);
                $term_stmt->execute();
                $term_result = $term_stmt->get_result()->fetch_assoc();
                $term_stmt->close();

                $response['schedule'] = [
                    'class_id'        => $schedule['class_id'],
                    'class_name'      => $display_name,
                    'subject_id'      => $schedule['subject_id'] ?? 0,
                    'subject_name'    => $schedule['subject_name']  ?? 'N/A',
                    'term_id'         => $schedule['term_id'],
                    'term_name'       => $term_result['term_name']  ?? 'N/A',
                    'academic_year'   => $schedule['academic_year'],
                    'department_id'   => $schedule['department_id'] ?? $teacher_department_id,
                    'department_name' => $schedule['department_name'] ?? 'N/A',
                    'time_slot'       => $schedule['time_slot'],
                ];
            } else {
                $response['error'] = 'No schedule found for this ID.';
            }
        } else {
            $response['error'] = 'Query execution failed: ' . $schedule_stmt->error;
        }
        $schedule_stmt->close();
    }

    header('Content-Type: application/json');
    echo json_encode($response);
    exit();
}
// -----------------------------------------------------------------------

// Log page access
$ip_address = filter_var($_SERVER['REMOTE_ADDR'], FILTER_VALIDATE_IP) ?: 'unknown';
$user_agent = substr($_SERVER['HTTP_USER_AGENT'] ?? 'unknown', 0, 255);
$log_sql  = "INSERT INTO user_logs (user_id, action, description, ip_address, user_agent, created_at) 
             VALUES (?, 'Accessed Edit Lesson Plan Page', ?, ?, ?, NOW())";
$log_stmt = $conn->prepare($log_sql);
$log_desc = "Teacher accessed edit lesson plan page (format: $format)";
$log_stmt->bind_param("isss", $teacher_id, $log_desc, $ip_address, $user_agent);
if (!$log_stmt->execute()) {
    error_log("Failed to log page access: " . $log_stmt->error);
}
$log_stmt->close();

// Generate CSRF token
$csrf_token = bin2hex(random_bytes(32));
$csrf_sql   = "INSERT INTO csrf_tokens (user_id, token, created_at, expires_at) 
               VALUES (?, ?, NOW(), DATE_ADD(NOW(), INTERVAL 1 HOUR))";
$csrf_stmt  = $conn->prepare($csrf_sql);
$csrf_stmt->bind_param("is", $teacher_id, $csrf_token);
$csrf_stmt->execute();
$csrf_stmt->close();
$conn->query("DELETE FROM csrf_tokens WHERE expires_at < NOW()");

// Get lesson plan ID from URL
$plan_id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$plan_id || $plan_id <= 0) {
    error_log("Invalid lesson plan ID: " . ($plan_id ?? 'unset'));
    $_SESSION['error_msg'] = "Invalid lesson plan ID.";
    header("Location: /teacher/lesson_plan.php");
    exit();
}

// Fetch lesson plan details
$plan_sql  = "SELECT tn.*, c.class_name, s.subject_name, ac.term_name, ac.year AS academic_year
              FROM teacher_notes tn
              JOIN classes c ON tn.class_id = c.id
              LEFT JOIN subjects s ON tn.subject_id = s.id
              JOIN academic_calendar ac ON tn.term_id = ac.id
              WHERE tn.id = ? AND tn.teacher_id = ? AND tn.format = ?";
$plan_stmt = $conn->prepare($plan_sql);
$plan_stmt->bind_param("iis", $plan_id, $teacher_id, $format);
$plan_stmt->execute();
$plan = $plan_stmt->get_result()->fetch_assoc();
$plan_stmt->close();

if (!$plan) {
    error_log("Lesson plan not found for ID: $plan_id, teacher_id: $teacher_id, format: $format");
    $_SESSION['error_msg'] = "Lesson plan not found or you do not have permission to edit it.";
    header("Location: /teacher/lesson_plan.php");
    exit();
}

// Class name mapping
$class_name_mapping = [
    'Esther Foundation' => 'Creche',
    'Sheba 1'           => 'Nursery 1',
    'Sheba 2'           => 'Nursery 2',
    'Yaasant 1'         => 'KG 1',
    'Yaasant 2'         => 'KG 2',
    'Cleopatra 1'       => 'Basic 1',
    'Cleopatra 2'       => 'Basic 2',
    'Nefertiti 1'       => 'Basic 3',
    'Nefertiti 2'       => 'Basic 4',
    'Malika 1'          => 'Basic 5',
    'Malika 2'          => 'Basic 6',
    'Makeda 1'          => 'Basic 7',
    'Makeda 2'          => 'Basic 8',
    'An Majesty'        => 'Basic 9',
];

function getDisplayClassName($class_name) {
    global $class_name_mapping;
    return $class_name_mapping[$class_name] ?? $class_name;
}

// -----------------------------------------------------------------------
// FIX 2: Fetch schedules WITH class_id and subject_id so we can pre-select
// -----------------------------------------------------------------------
if ($format === 'oxford') {
    $academic_year_query = "SELECT `year` FROM academic_calendar WHERE CURDATE() BETWEEN start_date AND end_date ORDER BY created_at DESC LIMIT 1";
    $academic_year_stmt  = $conn->prepare($academic_year_query);
    $academic_year_stmt->execute();
    $academic_year_result = $academic_year_stmt->get_result();
    if ($academic_year_result->num_rows > 0) {
        $row = $academic_year_result->fetch_assoc();
        $current_academic_year = $row['year'];
    } else {
        $current_year = date('Y');
        $current_academic_year = $current_year . '/' . ($current_year + 1);
    }
    $academic_year_stmt->close();

    $schedules_sql  = "SELECT cs.id, cs.class_id, cs.subject_id, c.class_name, s.subject_name, cs.academic_year,
                       CONCAT(cs.day_of_week, ' ', cs.start_time, ' - ', cs.end_time) AS time_slot
                       FROM class_schedules cs
                       JOIN classes c ON cs.class_id = c.id
                       LEFT JOIN subjects s ON cs.subject_id = s.id
                       WHERE cs.teacher_id = ? AND cs.academic_year = ?
                       ORDER BY cs.day_of_week, cs.start_time LIMIT 100";
    $schedules_stmt = $conn->prepare($schedules_sql);
    $schedules_stmt->bind_param("is", $teacher_id, $current_academic_year);
} else {
    $schedules_sql  = "SELECT cs.id, cs.class_id, cs.subject_id, c.class_name, s.subject_name, cs.academic_year,
                       CONCAT(cs.day_of_week, ' ', cs.start_time, ' - ', cs.end_time) AS time_slot
                       FROM class_schedules cs
                       JOIN classes c ON cs.class_id = c.id
                       LEFT JOIN subjects s ON cs.subject_id = s.id
                       WHERE cs.teacher_id = ?
                       ORDER BY cs.day_of_week, cs.start_time LIMIT 100";
    $schedules_stmt = $conn->prepare($schedules_sql);
    $schedules_stmt->bind_param("i", $teacher_id);
}
$schedules_stmt->execute();
$schedules = $schedules_stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
$schedules_stmt->close();

// FIX 2 cont.: Determine which schedule to pre-select by matching class_id + subject_id
$preselected_schedule_id = 0;
foreach ($schedules as $sched) {
    if ((int)$sched['class_id']   === (int)$plan['class_id'] &&
        (int)$sched['subject_id'] === (int)($plan['subject_id'] ?? 0)) {
        $preselected_schedule_id = (int)$sched['id'];
        break;
    }
}

// Always use the current active term — never let schedule selection override it.
// Primary source: academic_terms (is_active flag).
// Fallback: academic_calendar date range (matches class_schedule__4_.php pattern).
$current_term      = null;
$current_term_stmt = $conn->prepare(
    "SELECT id, term_name FROM academic_terms
     WHERE is_active = 1 AND CURDATE() BETWEEN start_date AND end_date
     LIMIT 1"
);
$current_term_stmt->execute();
$current_term = $current_term_stmt->get_result()->fetch_assoc();
$current_term_stmt->close();

if (!$current_term) {
    // Fallback to academic_calendar date range
    $current_term_stmt = $conn->prepare(
        "SELECT id, term_name FROM academic_calendar
         WHERE CURDATE() BETWEEN start_date AND end_date
         ORDER BY created_at DESC LIMIT 1"
    );
    $current_term_stmt->execute();
    $current_term = $current_term_stmt->get_result()->fetch_assoc();
    $current_term_stmt->close();
}

// Use the current term id; fall back to the plan's stored term_id only if no active term found
$active_term_id   = $current_term ? (int)$current_term['id']        : (int)$plan['term_id'];
$active_term_name = $current_term ? htmlspecialchars($current_term['term_name']) : htmlspecialchars($plan['term_name'] ?? 'Current Term');

// Handle form submission
$errors      = [];
$success_msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Verify CSRF token
    $posted_csrf = filter_input(INPUT_POST, 'csrf_token', FILTER_UNSAFE_RAW) ?? '';
    if (!preg_match('/^[a-f0-9]{64}$/', $posted_csrf)) {
        $errors[] = "Invalid CSRF token. Please refresh the page and try again.";
    } else {
        $csrf_check_sql  = "SELECT id FROM csrf_tokens WHERE user_id = ? AND token = ? AND expires_at > NOW()";
        $csrf_check_stmt = $conn->prepare($csrf_check_sql);
        $csrf_check_stmt->bind_param("is", $teacher_id, $posted_csrf);
        $csrf_check_stmt->execute();
        if ($csrf_check_stmt->get_result()->num_rows === 0) {
            $errors[] = "Your session has expired. Please refresh the page and try again.";
        }
        $csrf_check_stmt->close();
    }

    if (empty($errors)) {
        $action = $_POST['action'] ?? '';

        if ($action === 'submit') {
            // Common fields
            $week        = filter_input(INPUT_POST, 'week',        FILTER_VALIDATE_INT);
            $schedule_id = filter_input(INPUT_POST, 'schedule_id', FILTER_VALIDATE_INT);
            $class_id    = filter_input(INPUT_POST, 'class_id',    FILTER_VALIDATE_INT);
            $term_id     = filter_input(INPUT_POST, 'term_id',     FILTER_VALIDATE_INT);
            $subject_id  = filter_input(INPUT_POST, 'subject_id',  FILTER_VALIDATE_INT) ?? 0;

            // Sanitize text inputs
            $objectives   = trim(strip_tags($_POST['objectives']   ?? ''));
            $reference    = trim(strip_tags($_POST['reference']    ?? ''));
            $resources    = trim(strip_tags($_POST['resources']    ?? ''));
            $keywords     = trim(strip_tags($_POST['keywords']     ?? ''));
            $starter      = trim(strip_tags($_POST['starter']      ?? ''));
            $presentation = trim(strip_tags($_POST['presentation'] ?? '', '<p><br><strong><em><ul><ol><li>'));
            $summary      = trim(strip_tags($_POST['summary']      ?? ''));

            // Oxford-specific
            $unit  = trim(strip_tags($_POST['unit']  ?? ''));
            $topic = trim(strip_tags($_POST['topic'] ?? ''));

            // GES-specific
            $strand           = trim(strip_tags($_POST['strand']           ?? ''));
            $sub_strand       = trim(strip_tags($_POST['sub_strand']       ?? ''));
            $content_standard = trim(strip_tags($_POST['content_standard'] ?? ''));
            $indicator        = trim(strip_tags($_POST['indicator']        ?? ''));

            error_log("Edit form submission: plan_id=$plan_id, format=$format, schedule_id=$schedule_id, class_id=$class_id, term_id=$term_id");

            // Common validations
            if (!$week || $week < 1 || $week > 16)  { $errors[] = "Invalid week number (1-16 required)."; }
            if (!$schedule_id)                        { $errors[] = "Invalid schedule selected."; }
            if (!$class_id || $class_id <= 0)         { $errors[] = "Invalid class selected."; }
            if (!$term_id  || $term_id  <= 0)         { $errors[] = "Please select a term."; }
            if (empty($objectives))                   { $errors[] = "Objectives are required."; }
            if (empty($reference))                    { $errors[] = "Reference is required."; }
            if (empty($resources))                    { $errors[] = "Resources are required."; }
            if (empty($keywords))                     { $errors[] = "Keywords are required."; }
            if (empty($starter))                      { $errors[] = "Starter activity is required."; }
            if (empty($presentation))                 { $errors[] = "Main presentation is required."; }
            if (empty($summary))                      { $errors[] = "Summary is required."; }

            // Format-specific validations
            if ($format === 'oxford') {
                if (empty($unit))  { $errors[] = "Unit is required for Oxford lesson plans."; }
                if (empty($topic)) { $errors[] = "Topic is required for Oxford lesson plans."; }
                if (strlen($presentation) > 10000) { $errors[] = "Presentation cannot exceed 10000 characters."; }
            } else {
                if (empty($strand))           { $errors[] = "Strand is required for GES lesson plans."; }
                if (empty($sub_strand))       { $errors[] = "Sub-strand is required for GES lesson plans."; }
                if (empty($content_standard)) { $errors[] = "Content standard is required for GES lesson plans."; }
                if (empty($indicator))        { $errors[] = "Indicator is required for GES lesson plans."; }
                if (strlen($presentation) > 5000) { $errors[] = "Presentation cannot exceed 5000 characters for GES format."; }
            }

            if (empty($errors)) {
                // Verify schedule belongs to teacher
                $schedule_check_sql  = "SELECT id FROM class_schedules WHERE id = ? AND teacher_id = ?";
                $schedule_check_stmt = $conn->prepare($schedule_check_sql);
                $schedule_check_stmt->bind_param("ii", $schedule_id, $teacher_id);
                $schedule_check_stmt->execute();
                $schedule_valid = $schedule_check_stmt->get_result()->num_rows > 0;
                $schedule_check_stmt->close();

                if (!$schedule_valid) { $errors[] = "Selected schedule is not assigned to you."; }
            }

            if (empty($errors)) {
                $conn->begin_transaction();
                try {
                    if ($format === 'oxford') {
                        $update_sql  = "UPDATE teacher_notes SET 
                            week = ?, class_id = ?, term_id = ?, subject_id = ?, 
                            unit = ?, topic = ?, objectives = ?, reference = ?, 
                            resources = ?, keywords = ?, starter = ?, presentation = ?, 
                            summary = ?, strand = ?, sub_strand = ?, content_standard = ?,
                            indicator = ?
                            WHERE id = ? AND teacher_id = ? AND format = 'oxford'";
                        $update_stmt = $conn->prepare($update_sql);
                        $update_stmt->bind_param(
                            "iiiisssssssssssssii",
                            $week, $class_id, $term_id, $subject_id,
                            $unit, $topic, $objectives, $reference,
                            $resources, $keywords, $starter, $presentation,
                            $summary, $strand, $sub_strand, $content_standard,
                            $indicator, $plan_id, $teacher_id
                        );
                    } else {
                        // GES UPDATE
                        $update_sql  = "UPDATE teacher_notes SET 
                            week = ?, class_id = ?, term_id = ?, subject_id = ?, 
                            strand = ?, sub_strand = ?, content_standard = ?, indicator = ?,
                            objectives = ?, reference = ?, resources = ?, keywords = ?,
                            starter = ?, presentation = ?, summary = ?
                            WHERE id = ? AND teacher_id = ? AND format = 'ges'";
                        $update_stmt = $conn->prepare($update_sql);
                        $update_stmt->bind_param(
                            "iiiisssssssssssii",
                            $week, $class_id, $term_id, $subject_id,
                            $strand, $sub_strand, $content_standard, $indicator,
                            $objectives, $reference, $resources, $keywords,
                            $starter, $presentation, $summary, $plan_id, $teacher_id
                        );
                    }

                    if ($update_stmt->execute()) {
                        $success_msg = ucfirst($format) . " lesson plan updated successfully!";
                        $log_desc    = "Teacher updated " . strtoupper($format) . " lesson plan ID: $plan_id";
                        $log_stmt    = $conn->prepare("INSERT INTO user_logs (user_id, action, description, ip_address, user_agent, created_at) VALUES (?, 'Updated Lesson Plan', ?, ?, ?, NOW())");
                        if ($log_stmt) {
                            $log_stmt->bind_param("isss", $teacher_id, $log_desc, $ip_address, $user_agent);
                            $log_stmt->execute();
                            $log_stmt->close();
                        }
                        $conn->commit();
                        $_SESSION['success_msg'] = $success_msg;
                        header("Location: /teacher/weekly_plan_{$format}.php");
                        exit();
                    } else {
                        throw new Exception("Failed to update lesson plan: " . $update_stmt->error);
                    }
                    $update_stmt->close();
                } catch (Exception $e) {
                    $conn->rollback();
                    $errors[] = "Error updating lesson plan: " . $e->getMessage();
                    error_log("Lesson plan update exception: " . $e->getMessage());
                }
            }

        } elseif ($action === 'cancel') {
            header("Location: /teacher/weekly_plan_{$format}.php");
            exit();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Edit <?php echo strtoupper($format); ?> Lesson Plan | QueensPa School">
    <title>Edit <?php echo strtoupper($format); ?> Lesson Plan | QueensPa School</title>
    <?php if ($format === 'oxford'): ?>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <?php else: ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <?php endif; ?>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        :root {
            --primary: <?php echo ($format === 'oxford') ? '#ff4500' : '#28a745'; ?>;
            --primary-hover: <?php echo ($format === 'oxford') ? '#e03e00' : '#218838'; ?>;
            --secondary: #1e293b;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #f0f4f8 0%, #e2e8f0 100%);
            min-height: 100vh;
            color: #1f2937;
            line-height: 1.6;
        }

        .card {
            background: white;
            border-radius: 16px;
            box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1), 0 2px 4px -1px rgba(0,0,0,0.06);
            transition: all 0.3s ease;
            border: 1px solid #e5e7eb;
        }

        .card-header {
            background: linear-gradient(135deg, var(--primary) 0%, <?php echo ($format === 'oxford') ? '#ff6b3d' : '#34ce57'; ?> 100%);
            color: white;
            padding: 1.5rem;
            border-radius: 16px 16px 0 0;
            font-weight: 700;
            font-size: 1.25rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.625rem 1.25rem;
            border-radius: 10px;
            font-weight: 600;
            font-size: 0.875rem;
            transition: all 0.3s ease;
            border: none;
            cursor: pointer;
            text-decoration: none;
        }

        .btn-primary { background: linear-gradient(135deg, var(--primary), <?php echo ($format === 'oxford') ? '#ff6b3d' : '#34ce57'; ?>); color: white; }
        .btn-primary:hover { box-shadow: 0 10px 20px -5px rgba(<?php echo ($format === 'oxford') ? '255,69,0' : '40,167,69'; ?>,0.4); transform: translateY(-2px); }
        .btn-secondary { background: #6b7280; color: white; }
        .btn-secondary:hover { background: #4b5563; transform: translateY(-2px); }

        .form-control, .form-select {
            width: 100%;
            padding: 0.75rem 1rem;
            border: 2px solid #d1d5db;
            border-radius: 10px;
            font-size: 0.95rem;
            transition: all 0.3s ease;
            background: white;
        }

        .form-control:focus, .form-select:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(<?php echo ($format === 'oxford') ? '255,69,0' : '40,167,69'; ?>,0.1);
        }

        textarea.form-control { resize: vertical; min-height: 100px; }

        .alert {
            padding: 1rem 1.25rem;
            border-radius: 12px;
            margin-bottom: 1rem;
            display: flex;
            align-items: flex-start;
            gap: 0.75rem;
        }

        .alert-success { background: #ecfdf5; border: 1px solid #6ee7b7; color: #065f46; }
        .alert-error   { background: #fef2f2; border: 1px solid #fca5a5; color: #991b1b; }
        .alert-info    { background: #eff6ff; border: 1px solid #93c5fd; color: #1e40af; }
        .alert-warning { background: #fffbeb; border: 1px solid #fcd34d; color: #92400e; }
        .alert-danger  { background: #fef2f2; border: 1px solid #fca5a5; color: #991b1b; }

        .grid-2 {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 1rem;
        }

        .required::after { content: ' *'; color: var(--danger); }

        .loading-overlay {
            display: none;
            position: fixed; top: 0; left: 0;
            width: 100%; height: 100%;
            background: rgba(0,0,0,0.7);
            z-index: 9999;
            justify-content: center; align-items: center;
            flex-direction: column; gap: 1rem;
        }

        .loading-spinner {
            width: 60px; height: 60px;
            border: 4px solid rgba(255,255,255,0.3);
            border-top-color: var(--primary);
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }

        @keyframes spin { to { transform: rotate(360deg); } }

        <?php if ($format === 'oxford'): ?>
        .oxford-badge {
            background: linear-gradient(135deg, #1e3a8a, #3b82f6);
            color: white;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            display: inline-flex; align-items: center; gap: 0.25rem;
        }
        <?php else: ?>
        .ges-badge {
            background: linear-gradient(135deg, #0d9488, #14b8a6);
            color: white;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            display: inline-flex; align-items: center; gap: 0.25rem;
        }
        <?php endif; ?>

        @media (max-width: 768px) {
            .container { padding: 1rem; }
            .grid-2 { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
    <div id="loading-overlay" class="loading-overlay">
        <div class="loading-spinner"></div>
        <p class="text-white font-semibold text-lg">Saving...</p>
    </div>

    <?php include $_SERVER['DOCUMENT_ROOT'] . '/templates/teacher_header.php'; ?>

    <div class="container mx-auto px-4 py-8">
        <?php if (!empty($errors)): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-circle text-xl"></i>
                <div><?php foreach ($errors as $error): ?><p><?php echo htmlspecialchars($error); ?></p><?php endforeach; ?></div>
            </div>
        <?php endif; ?>

        <?php if ($success_msg): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle text-xl"></i>
                <p><?php echo htmlspecialchars($success_msg); ?></p>
            </div>
        <?php endif; ?>

        <div class="card">
            <div class="card-header">
                <i class="fas fa-edit"></i>
                Edit <?php echo strtoupper($format); ?> Lesson Plan
                <?php if ($format === 'oxford'): ?>
                    <span class="oxford-badge"><i class="fas fa-graduation-cap"></i> Oxford Curriculum</span>
                <?php else: ?>
                    <span class="ges-badge"><i class="fas fa-school"></i> GES Curriculum</span>
                <?php endif; ?>
            </div>
            <div class="p-6">
                <form id="lessonPlanForm" method="POST" class="space-y-6">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                    <input type="hidden" name="action" value="submit">
                    <input type="hidden" id="class_id"   name="class_id"   value="<?php echo (int)$plan['class_id']; ?>">
                    <input type="hidden" id="subject_id" name="subject_id" value="<?php echo (int)($plan['subject_id'] ?? 0); ?>">
                    <input type="hidden" id="term_id" name="term_id" value="<?php echo $active_term_id; ?>">

                    <!-- Basic Information -->
                    <div class="bg-gray-50 p-6 rounded-xl">
                        <h3 class="text-lg font-bold mb-4"><i class="fas fa-info-circle text-blue-500"></i> Basic Information</h3>
                        <div class="grid-2">
                            <div>
                                <label for="schedule_id" class="block text-sm font-medium text-gray-700 required">Schedule</label>
                                <select id="schedule_id" name="schedule_id" class="form-control" required>
                                    <option value="">Select Schedule</option>
                                    <?php foreach ($schedules as $sched): ?>
                                        <?php
                                            // FIX 2: mark the option that matches the plan's class + subject
                                            $selected = ((int)$sched['id'] === $preselected_schedule_id) ? 'selected' : '';
                                        ?>
                                        <option value="<?php echo (int)$sched['id']; ?>" <?php echo $selected; ?>>
                                            <?php echo htmlspecialchars(
                                                getDisplayClassName($sched['class_name']) . ' - ' .
                                                ($sched['subject_name'] ?? 'N/A') . ' (' . $sched['time_slot'] . ')'
                                            ); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div>
                                <label for="week" class="block text-sm font-medium text-gray-700 required">Week</label>
                                <select id="week" name="week" class="form-control" required>
                                    <option value="">Select Week</option>
                                    <?php for ($i = 1; $i <= 16; $i++): ?>
                                        <option value="<?php echo $i; ?>" <?php echo ($plan['week'] == $i) ? 'selected' : ''; ?>>Week <?php echo $i; ?></option>
                                    <?php endfor; ?>
                                </select>
                            </div>
                        </div>
                        <div class="mt-4">
                            <label class="block text-sm font-medium text-gray-700">Term</label>
                            <input type="text" class="form-control bg-gray-100" value="<?php echo $active_term_name; ?>" disabled readonly>
                            <small class="text-gray-500 text-sm">Automatically set to the current active term</small>
                        </div>
                        <div id="schedule-container" class="mt-4"></div>
                    </div>

                    <!-- Curriculum Details -->
                    <?php if ($format === 'oxford'): ?>
                    <div class="bg-gray-50 p-6 rounded-xl">
                        <h3 class="text-lg font-bold mb-4"><i class="fas fa-book text-purple-500"></i> Oxford Curriculum Details</h3>
                        <div class="grid-2">
                            <div>
                                <label for="unit" class="block text-sm font-medium text-gray-700 required">Unit</label>
                                <input type="text" id="unit" name="unit" class="form-control"
                                    value="<?php echo htmlspecialchars($plan['unit'] ?? ''); ?>"
                                    placeholder="e.g., Unit 5: Living Things" required>
                            </div>
                            <div>
                                <label for="topic" class="block text-sm font-medium text-gray-700 required">Topic</label>
                                <input type="text" id="topic" name="topic" class="form-control"
                                    value="<?php echo htmlspecialchars($plan['topic'] ?? ''); ?>"
                                    placeholder="e.g., Classification of Animals" required>
                            </div>
                        </div>
                    </div>
                    <?php else: ?>
                    <div class="bg-gray-50 p-6 rounded-xl">
                        <h3 class="text-lg font-bold mb-4"><i class="fas fa-graduation-cap"></i> GES Curriculum Details</h3>
                        <div class="grid-2">
                            <div>
                                <label for="strand" class="block text-sm font-medium text-gray-700 required">Strand</label>
                                <input type="text" id="strand" name="strand" class="form-control"
                                    value="<?php echo htmlspecialchars($plan['strand'] ?? ''); ?>"
                                    placeholder="Enter strand" required>
                            </div>
                            <div>
                                <label for="sub_strand" class="block text-sm font-medium text-gray-700 required">Sub-Strand</label>
                                <input type="text" id="sub_strand" name="sub_strand" class="form-control"
                                    value="<?php echo htmlspecialchars($plan['sub_strand'] ?? ''); ?>"
                                    placeholder="Enter sub-strand" required>
                            </div>
                            <div>
                                <label for="content_standard" class="block text-sm font-medium text-gray-700 required">Content Standard</label>
                                <input type="text" id="content_standard" name="content_standard" class="form-control"
                                    value="<?php echo htmlspecialchars($plan['content_standard'] ?? ''); ?>"
                                    placeholder="Enter content standard" required>
                            </div>
                            <div>
                                <label for="indicator" class="block text-sm font-medium text-gray-700 required">Indicator</label>
                                <input type="text" id="indicator" name="indicator" class="form-control"
                                    value="<?php echo htmlspecialchars($plan['indicator'] ?? ''); ?>"
                                    placeholder="Enter indicator" required>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Lesson Details -->
                    <div class="bg-gray-50 p-6 rounded-xl">
                        <h3 class="text-lg font-bold mb-4"><i class="fas fa-pencil-alt text-green-500"></i> Lesson Details</h3>
                        <div class="space-y-4">
                            <div>
                                <label for="objectives" class="block text-sm font-medium text-gray-700 required">Objectives</label>
                                <textarea id="objectives" name="objectives" class="form-control" rows="4" required
                                    placeholder="Enter lesson objectives"><?php echo htmlspecialchars($plan['objectives'] ?? ''); ?></textarea>
                            </div>
                            <div>
                                <label for="reference" class="block text-sm font-medium text-gray-700 required">Reference</label>
                                <textarea id="reference" name="reference" class="form-control" rows="2" required
                                    placeholder="Enter teaching references"><?php echo htmlspecialchars($plan['reference'] ?? ''); ?></textarea>
                            </div>
                            <div>
                                <label for="resources" class="block text-sm font-medium text-gray-700 required">Resources</label>
                                <textarea id="resources" name="resources" class="form-control" rows="2" required
                                    placeholder="Enter teaching and learning resources"><?php echo htmlspecialchars($plan['resources'] ?? ''); ?></textarea>
                            </div>
                            <div>
                                <label for="keywords" class="block text-sm font-medium text-gray-700 required">Keywords</label>
                                <input type="text" id="keywords" name="keywords" class="form-control" required
                                    value="<?php echo htmlspecialchars($plan['keywords'] ?? ''); ?>"
                                    placeholder="e.g., critical thinking, collaboration, problem-solving">
                            </div>
                        </div>
                    </div>

                    <!-- Lesson Delivery -->
                    <div class="bg-gray-50 p-6 rounded-xl">
                        <h3 class="text-lg font-bold mb-4"><i class="fas fa-chalkboard-teacher text-red-500"></i> Lesson Delivery</h3>
                        <div class="space-y-4">
                            <div>
                                <label for="starter" class="block text-sm font-medium text-gray-700 required">Starter Activity</label>
                                <textarea id="starter" name="starter" class="form-control" rows="3" required
                                    placeholder="Enter lesson starter activity"><?php echo htmlspecialchars($plan['starter'] ?? ''); ?></textarea>
                                <small class="text-gray-500 text-sm">Engaging activity to capture students' attention and activate prior knowledge.</small>
                            </div>
                            <div>
                                <label for="presentation" class="block text-sm font-medium text-gray-700 required">Main Presentation</label>
                                <textarea id="presentation" name="presentation" class="form-control" rows="6" required
                                    maxlength="<?php echo ($format === 'oxford') ? '10000' : '5000'; ?>"
                                    placeholder="Enter the main lesson content"><?php echo htmlspecialchars($plan['presentation'] ?? ''); ?></textarea>
                                <p id="presentation-counter" class="text-sm text-gray-500 text-right">0/<?php echo ($format === 'oxford') ? '10000' : '5000'; ?></p>
                            </div>
                            <div>
                                <label for="summary" class="block text-sm font-medium text-gray-700 required">Plenary / Summary</label>
                                <textarea id="summary" name="summary" class="form-control" rows="3" required
                                    placeholder="Enter lesson summary and conclusion"><?php echo htmlspecialchars($plan['summary'] ?? ''); ?></textarea>
                                <small class="text-gray-500 text-sm">Recap key points, assess understanding, and provide feedback.</small>
                            </div>
                        </div>
                    </div>

                    <!-- Form Actions -->
                    <div class="flex gap-3 pt-4 border-t border-gray-200">
                        <button type="submit" class="btn btn-primary" id="submit-btn">
                            <i class="fas fa-save"></i> Update Lesson Plan
                        </button>
                        <button type="submit" name="action" value="cancel" class="btn btn-secondary">
                            <i class="fas fa-times"></i> Cancel
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <?php include $_SERVER['DOCUMENT_ROOT'] . '/templates/teacher_footer.php'; ?>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <?php if ($format !== 'oxford'): ?>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <?php endif; ?>
    <script>
        // -----------------------------------------------------------------------
        // FIX 4: Build base AJAX URL preserving id and format so PHP validation
        //        never fires on AJAX calls regardless of execution order.
        // -----------------------------------------------------------------------
        const AJAX_BASE = window.location.pathname
                        + '?id=<?php echo (int)$plan_id; ?>'
                        + '&format=<?php echo htmlspecialchars($format); ?>'
                        + '&action=get_schedule_details&schedule_id=';

        function showLoading() {
            document.getElementById('loading-overlay').style.display = 'flex';
        }

        function hideLoading() {
            document.getElementById('loading-overlay').style.display = 'none';
        }

        function escapeHtml(text) {
            if (!text) return '';
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        $(document).ready(function () {

            // Character counter
            const presentationTextarea = document.getElementById('presentation');
            const counter   = document.getElementById('presentation-counter');
            const maxLength = <?php echo ($format === 'oxford') ? 10000 : 5000; ?>;

            function updateCounter() {
                counter.textContent = presentationTextarea.value.length + '/' + maxLength;
            }
            presentationTextarea.addEventListener('input', updateCounter);
            updateCounter();

            // Schedule change handler — identical logic to weekly_plan pages
            // Term is always the current active term fetched server-side; schedule
            // selection only updates class_id / subject_id / info panel.
            function loadScheduleDetails(id) {
                if (!id) {
                    $('#schedule-container').empty();
                    return;
                }

                showLoading();
                $.get(AJAX_BASE + id, function (r) {
                    hideLoading();
                    if (r.error) {
                        $('#schedule-container').html(
                            '<div class="alert alert-warning"><i class="fas fa-exclamation-triangle"></i> ' +
                            escapeHtml(r.error) + '</div>'
                        );
                    } else if (r.schedule) {
                        // Only update class and subject — NEVER touch term_id
                        $('#class_id').val(r.schedule.class_id);
                        $('#subject_id').val(r.schedule.subject_id || '');

                        $('#schedule-container').html(
                            '<div class="bg-green-50 p-4 rounded-xl border border-green-200">' +
                            '<h4 class="font-semibold text-green-700"><i class="fas fa-check-circle"></i> Selected Schedule Details</h4>' +
                            '<p class="text-sm mt-2"><strong>Class:</strong> '    + escapeHtml(r.schedule.class_name)      + '</p>' +
                            '<p class="text-sm"><strong>Subject:</strong> '       + escapeHtml(r.schedule.subject_name)    + '</p>' +
                            '<p class="text-sm"><strong>Department:</strong> '    + escapeHtml(r.schedule.department_name) + '</p>' +
                            '<p class="text-sm"><strong>Time:</strong> '          + escapeHtml(r.schedule.time_slot)       + '</p>' +
                            '<p class="text-sm"><strong>Academic Year:</strong> ' + escapeHtml(r.schedule.academic_year)   + '</p>' +
                            '</div>'
                        );
                    }
                }, 'json').fail(function (xhr) {
                    hideLoading();
                    $('#schedule-container').html(
                        '<div class="alert alert-danger"><i class="fas fa-times-circle"></i> ' +
                        'Error fetching schedule details (HTTP ' + xhr.status + '). Please try again.</div>'
                    );
                });
            }

            // User manually changes the schedule dropdown
            $('#schedule_id').on('change', function () {
                loadScheduleDetails($(this).val());
            });

            // On page load: silently load details for the pre-selected schedule
            const initialId = $('#schedule_id').val();
            if (initialId) {
                loadScheduleDetails(initialId);
            }

            // Form submission validation
            $('#lessonPlanForm').on('submit', function (e) {
                const action = $(e.originalEvent && e.originalEvent.submitter || this).attr('value') ||
                               $('button[type=submit]:focus, input[type=submit]:focus', this).val();

                // Allow Cancel to pass through without validation
                if (action === 'cancel') { return true; }

                const week = $('#week').val();
                if (!week || week < 1 || week > 16) {
                    alert('Please select a valid week (1-16).');
                    e.preventDefault(); return false;
                }

                if (!$('#schedule_id').val()) {
                    alert('Please select a schedule.');
                    e.preventDefault(); return false;
                }

                const required = ['objectives', 'reference', 'resources', 'keywords', 'starter', 'presentation', 'summary'];
                for (const field of required) {
                    if (!$('#' + field).val().trim()) {
                        alert('All required fields must be filled.');
                        e.preventDefault(); return false;
                    }
                }

                <?php if ($format === 'oxford'): ?>
                if (!$('#unit').val().trim() || !$('#topic').val().trim()) {
                    alert('Unit and Topic are required for Oxford lesson plans.');
                    e.preventDefault(); return false;
                }
                <?php else: ?>
                if (!$('#strand').val().trim() || !$('#sub_strand').val().trim() ||
                    !$('#content_standard').val().trim() || !$('#indicator').val().trim()) {
                    alert('Strand, Sub-strand, Content Standard, and Indicator are required for GES lesson plans.');
                    e.preventDefault(); return false;
                }
                if ($('#presentation').val().trim().length > 5000) {
                    alert('Presentation cannot exceed 5000 characters for GES format.');
                    e.preventDefault(); return false;
                }
                <?php endif; ?>

                if (!confirm('Are you sure you want to update this lesson plan?')) {
                    e.preventDefault(); return false;
                }

                showLoading();
                return true;
            });
        });
    </script>
</body>
</html>
<?php
mysqli_close($conn);
ob_end_flush();
?>