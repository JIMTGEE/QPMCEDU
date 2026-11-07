<?php
// Start session at the very beginning
session_start();

// Check if there's any output before session_start()
if (headers_sent()) {
    error_log("Headers already sent. Output started somewhere before session_start()", 3, $_SERVER['DOCUMENT_ROOT'] . '/logs/error.log');
}

require_once($_SERVER['DOCUMENT_ROOT'] . '/config/db.php');

// Check database connection
if (!$conn || $conn->connect_error) {
    error_log("Database connection failed: " . ($conn->connect_error ?? 'Connection is null'), 3, $_SERVER['DOCUMENT_ROOT'] . '/logs/error.log');
    die("System error: Database connection failed. Please contact the administrator.");
}

// Include log_activity.php and define fallback
$log_activity_path = $_SERVER['DOCUMENT_ROOT'] . '/functions/log_activity.php';
if (file_exists($log_activity_path)) {
    require_once($log_activity_path);
} else {
    error_log("log_activity.php not found at $log_activity_path", 3, $_SERVER['DOCUMENT_ROOT'] . '/logs/error.log');
    if (!function_exists('log_activity')) {
        function log_activity($user_id, $action, $details, $conn = null) {
            $log_file = $_SERVER['DOCUMENT_ROOT'] . '/logs/activity.log';
            $timestamp = date('Y-m-d H:i:s');
            error_log("[$timestamp] [user_id: $user_id] $action: $details\n", 3, $log_file);
        }
    }
}

// Check if log_activity function exists
if (!function_exists('log_activity')) {
    error_log("log_activity function is undefined after inclusion attempt", 3, $_SERVER['DOCUMENT_ROOT'] . '/logs/error.log');
    function log_activity($user_id, $action, $details, $conn = null) {
        $log_file = $_SERVER['DOCUMENT_ROOT'] . '/logs/activity.log';
        $timestamp = date('Y-m-d H:i:s');
        error_log("[$timestamp] [user_id: $user_id] $action: $details\n", 3, $log_file);
    }
}

// Check admin authentication
if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    $user_id = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : 0;
    log_activity($user_id, 'access_denied', 'Attempted to access bulk_reminder.php without admin role', $conn);
    $_SESSION['error_msg'] = "Please log in as an admin to access this page.";
    header('Location: /login.php');
    exit;
}

// Log page access
$ip_address = $_SERVER['REMOTE_ADDR'];
$user_agent = $_SERVER['HTTP_USER_AGENT'];
log_activity($_SESSION['user_id'], 'page_access', "Admin accessed bulk_reminder.php, IP: $ip_address, User Agent: $user_agent", $conn);

// Generate and store CSRF token
$csrf_token = bin2hex(random_bytes(32));
$csrf_expiry = date('Y-m-d H:i:s', strtotime('+1 hour'));
$csrf_query = "INSERT INTO csrf_tokens (user_id, token, created_at, expires_at) VALUES (?, ?, NOW(), ?)";
if ($csrf_stmt = $conn->prepare($csrf_query)) {
    $csrf_stmt->bind_param("iss", $_SESSION['user_id'], $csrf_token, $csrf_expiry);
    if (!$csrf_stmt->execute()) {
        error_log("Failed to store CSRF token: " . $conn->error, 3, $_SERVER['DOCUMENT_ROOT'] . '/logs/error.log');
    }
    $csrf_stmt->close();
} else {
    error_log("Failed to prepare CSRF token query: " . $conn->error, 3, $_SERVER['DOCUMENT_ROOT'] . '/logs/error.log');
}
$_SESSION['csrf_token'] = $csrf_token;

// Fetch academic years - FIXED: Your table is academic_calendar
$academic_years_result = $conn->query("SELECT DISTINCT year FROM academic_calendar WHERE year REGEXP '^[0-9]{4}/[0-9]{4}$' ORDER BY year DESC");
if (!$academic_years_result) {
    error_log("Error fetching academic years: " . $conn->error, 3, $_SERVER['DOCUMENT_ROOT'] . '/logs/error.log');
    die("System error: Unable to fetch academic years. Please try again later.");
}
$academic_years = [];
while ($row = $academic_years_result->fetch_assoc()) {
    $academic_years[] = $row['year'];
}
$academic_years_count = count($academic_years);
error_log("Fetched $academic_years_count academic years", 3, $_SERVER['DOCUMENT_ROOT'] . '/logs/activity.log');
$academic_years_result->free();

// Fetch departments
$departments_result = $conn->query("SELECT id, name FROM departments ORDER BY name ASC");
if (!$departments_result) {
    error_log("Error fetching departments: " . $conn->error, 3, $_SERVER['DOCUMENT_ROOT'] . '/logs/error.log');
    die("System error: Unable to fetch departments. Please try again later.");
}
$departments = [];
$valid_department_enums = ['Lower Primary', 'Upper Primary', 'Junior High', 'Kindergarten', 'Pre-School'];
while ($row = $departments_result->fetch_assoc()) {
    $dept_name = $row['name'];
    if (!in_array($dept_name, $valid_department_enums)) {
        $dept_name = str_contains($row['name'], 'Lower') ? 'Lower Primary' :
                     (str_contains($row['name'], 'Upper') ? 'Upper Primary' :
                     (str_contains($row['name'], 'Junior') ? 'Junior High' :
                     (str_contains($row['name'], 'Kindergarten') ? 'Kindergarten' : $row['name'])));
    }
    $departments[$row['id']] = ['name' => $row['name'], 'enum_name' => $dept_name];
}
$departments_count = count($departments);
error_log("Fetched $departments_count departments", 3, $_SERVER['DOCUMENT_ROOT'] . '/logs/activity.log');
$departments_result->free();

// Fetch classes
$classes_result = $conn->query("SELECT id, class_name, department_id FROM classes ORDER BY class_name ASC");
if (!$classes_result) {
    error_log("Error fetching classes: " . $conn->error, 3, $_SERVER['DOCUMENT_ROOT'] . '/logs/error.log');
    die("System error: Unable to fetch classes. Please try again later.");
}
$classes = [];
while ($row = $classes_result->fetch_assoc()) {
    $classes[$row['id']] = ['class_name' => $row['class_name'], 'department_id' => $row['department_id']];
}
$classes_count = count($classes);
error_log("Fetched $classes_count classes", 3, $_SERVER['DOCUMENT_ROOT'] . '/logs/activity.log');
$classes_result->free();

// Initialize variables
$selected_year = !empty($academic_years) ? $academic_years[0] : '';
$selected_dept = 0;
$selected_class = 0;
$search_query = '';
$student_debts = [];
$total_students = 0;
$total_debt_amount = 0;
$selected_students = [];

// Handle form submission for sending reminders
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['csrf_token'])) {
    // Validate CSRF token
    $csrf_valid = false;
    $csrf_check_query = "SELECT id FROM csrf_tokens WHERE user_id = ? AND token = ? AND expires_at > NOW()";
    if ($csrf_stmt = $conn->prepare($csrf_check_query)) {
        $csrf_stmt->bind_param("is", $_SESSION['user_id'], $_POST['csrf_token']);
        if ($csrf_stmt->execute()) {
            $csrf_result = $csrf_stmt->get_result();
            $csrf_valid = $csrf_result->num_rows > 0;
            $csrf_result->free();
        }
        $csrf_stmt->close();
    }
    
    if (!$csrf_valid) {
        error_log("Invalid CSRF token for bulk reminder submission", 3, $_SERVER['DOCUMENT_ROOT'] . '/logs/error.log');
        $_SESSION['error_msg'] = "Invalid security token. Please try again.";
        header('Location: bulk_reminder.php');
        exit;
    }
    
    // Check if we're sending reminders
    if (isset($_POST['action']) && $_POST['action'] === 'send_reminders') {
        $academic_year = trim($_POST['academic_year']);
        $dept_id = (int)($_POST['department_id'] ?? 0);
        $class_id = (int)($_POST['class_id'] ?? 0);
        $message_type = $_POST['message_type'] ?? 'custom';
        $custom_message = trim($_POST['custom_message'] ?? '');
        $selected_students = json_decode($_POST['selected_students'] ?? '[]', true) ?? [];
        
        if (empty($selected_students)) {
            $_SESSION['error_msg'] = "Please select at least one student to send reminders to their parents.";
            header('Location: bulk_reminder.php');
            exit;
        }
        
        // Prepare message based on type
        $message_template = "";
        if ($message_type === 'custom') {
            $message_template = $custom_message;
        } else {
            // Pre-defined templates
            $templates = [
                'general' => "Dear Parent, this is a reminder about outstanding fees for {student_name} in {class_name}. Total amount: GH₵{total_debt}. Please make payment at your earliest convenience. Thank you.",
                'urgent' => "URGENT: {student_name} has outstanding fees of GH₵{total_debt} in {class_name} that require immediate attention. Please visit the school office or make payment online to avoid consequences.",
                'partial' => "Reminder: {student_name} has partially paid fees in {class_name}. Remaining balance: GH₵{total_debt}. Please complete the payment. Thank you for your cooperation.",
                'overdue' => "OVERDUE NOTICE: {student_name}'s fees of GH₵{total_debt} in {class_name} are overdue by {days_overdue} days. Please make immediate payment to avoid additional charges.",
                'upcoming' => "Friendly reminder: Upcoming fee payment for {student_name} in {class_name} is due soon. Amount: GH₵{total_debt}. Please prepare for timely payment. Thank you."
            ];
            $message_template = $templates[$message_type] ?? $templates['general'];
        }
        
        // Initialize SMS sending results
        $sent_count = 0;
        $failed_count = 0;
        $failed_details = [];
        
        // Get SMS API configuration
        $sms_api_key = 'YOUR_SMS_API_KEY'; // Replace with actual SMS API key
        $sms_sender_id = 'QPMS'; // Replace with your sender ID
        $sms_endpoint = 'https://api.example.com/sms/send'; // Replace with actual SMS API endpoint
        
        foreach ($selected_students as $student_id) {
            // FIXED: Updated query to match your database structure
            $student_query = "SELECT 
                s.id as student_id,
                s.name as student_name,
                c.class_name,
                c.id as class_id,
                COALESCE(SUM(d.amount_due), 0) as total_debt,
                f.fee_type,
                f.due_date,
                DATEDIFF(NOW(), f.due_date) as days_overdue,
                COALESCE(SUM(p.amount_paid), 0) as amount_paid,
                parent.name as parent_name,
                parent.phone as parent_phone,
                parent.email as parent_email
            FROM users s
            JOIN student_classes sc ON s.id = sc.user_id AND sc.academic_year = ?
            JOIN classes c ON sc.class_id = c.id
            LEFT JOIN debts d ON s.id = d.user_id AND d.academic_year = ?
            LEFT JOIN fees f ON d.fee_id = f.id AND f.academic_year = ?
            LEFT JOIN payments p ON f.id = p.fee_id AND s.id = p.user_id
            LEFT JOIN parent_student_mapping psm ON s.id = psm.student_id
            LEFT JOIN users parent ON psm.parent_id = parent.id
            WHERE s.id = ? AND s.role = 'student' AND s.status = 'active'
            GROUP BY s.id, f.id
            ORDER BY f.due_date DESC";
            
            if ($student_stmt = $conn->prepare($student_query)) {
                $student_stmt->bind_param("sssi", $academic_year, $academic_year, $academic_year, $student_id);
                $student_stmt->execute();
                $student_result = $student_stmt->get_result();
                
                $student_fees = [];
                $total_debt = 0;
                $parent_name = '';
                $parent_phone = '';
                $parent_email = '';
                
                while ($fee_row = $student_result->fetch_assoc()) {
                    if ($fee_row['total_debt'] > 0) {
                        $student_fees[] = $fee_row;
                        $total_debt += $fee_row['total_debt'];
                        $parent_name = $fee_row['parent_name'] ?? '';
                        $parent_phone = $fee_row['parent_phone'] ?? '';
                        $parent_email = $fee_row['parent_email'] ?? '';
                    }
                }
                
                $student_stmt->close();
                
                if (!empty($student_fees) && !empty($parent_phone)) {
                    $student_name = $student_fees[0]['student_name'];
                    $class_name = $student_fees[0]['class_name'];
                    $due_date = $student_fees[0]['due_date'];
                    $days_overdue = $student_fees[0]['days_overdue'] ?? 0;
                    $amount_paid = $student_fees[0]['amount_paid'] ?? 0;
                    
                    // Generate detailed fee breakdown
                    $fee_breakdown = "";
                    foreach ($student_fees as $fee) {
                        $fee_breakdown .= "{$fee['fee_type']}: GH₵" . number_format($fee['total_debt'], 2) . "\n";
                    }
                    
                    // Personalize message
                    $personalized_message = str_replace(
                        ['{student_name}', '{class_name}', '{total_debt}', '{days_overdue}', '{academic_year}', '{fee_breakdown}', '{due_date}'],
                        [
                            $student_name,
                            $class_name,
                            number_format($total_debt, 2),
                            $days_overdue,
                            $academic_year,
                            $fee_breakdown,
                            date('jS F, Y', strtotime($due_date))
                        ],
                        $message_template
                    );
                    
                    // Add payment instructions
                    $personalized_message .= "\n\nPay via Mobile Money: 055-123-4567\nBank: GCB Bank\nAccount: 1234567890\nReference: {$student_name} - {$academic_year}";
                    
                    // Send SMS via API
                    $sms_data = [
                        'api_key' => $sms_api_key,
                        'sender_id' => $sms_sender_id,
                        'recipient' => $parent_phone,
                        'message' => $personalized_message
                    ];
                    
                    // For demonstration, we'll simulate sending
                    // In production, uncomment the cURL code
                    /*
                    $ch = curl_init($sms_endpoint);
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($ch, CURLOPT_POST, true);
                    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($sms_data));
                    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
                    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
                    
                    $sms_response = curl_exec($ch);
                    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    curl_close($ch);
                    
                    if ($http_code == 200) {
                        $sent_count++;
                    } else {
                        $failed_count++;
                        $failed_details[] = "Parent: {$parent_name}, Phone: {$parent_phone}";
                    }
                    */
                    
                    // Simulate successful sending for demo
                    $sent_count++;
                    
                    // Record in notifications table - FIXED: Get parent_id from mapping
                    $notif_query = "INSERT INTO notifications (user_id, title, message, type, category, created_at) 
                                  VALUES (?, 'Fee Payment Reminder', ?, 'info', 'administrative', NOW())";
                    if ($notif_stmt = $conn->prepare($notif_query)) {
                        // Get parent_id from mapping
                        $parent_query = "SELECT parent_id FROM parent_student_mapping WHERE student_id = ?";
                        if ($parent_stmt = $conn->prepare($parent_query)) {
                            $parent_stmt->bind_param("i", $student_id);
                            $parent_stmt->execute();
                            $parent_result = $parent_stmt->get_result();
                            if ($parent_row = $parent_result->fetch_assoc()) {
                                $parent_id = $parent_row['parent_id'];
                                $short_message = "Reminder sent for {$student_name}'s fees: GH₵" . number_format($total_debt, 2);
                                $notif_stmt->bind_param("is", $parent_id, $short_message);
                                $notif_stmt->execute();
                            }
                            $parent_stmt->close();
                        }
                        $notif_stmt->close();
                    }
                    
                    // Log activity
                    log_activity($_SESSION['user_id'], 'sms_sent', 
                        "Sent SMS to parent of student ID: {$student_id}, Parent: {$parent_name}, Phone: {$parent_phone}, Amount: GH₵{$total_debt}", 
                        $conn);
                        
                } else if (empty($parent_phone)) {
                    $failed_count++;
                    $failed_details[] = "Student ID: {$student_id} (No parent contact found)";
                } else {
                    $failed_count++;
                    $failed_details[] = "Student ID: {$student_id} (No outstanding fees)";
                }
            }
        }
        
        // Prepare result message
        $result_message = "Reminders sent successfully to parents of {$sent_count} student(s).";
        if ($failed_count > 0) {
            $result_message .= " Failed to send to parents of {$failed_count} student(s).";
            $_SESSION['warning_details'] = $failed_details;
        }
        
        if ($sent_count > 0) {
            $_SESSION['success_msg'] = $result_message;
        } else {
            $_SESSION['error_msg'] = "Failed to send any reminders. Please check parent contact information and try again.";
        }
        
        log_activity($_SESSION['user_id'], 'bulk_reminders_sent', 
            "Sent reminders for {$sent_count} students, failed: {$failed_count}, academic_year: {$academic_year}", 
            $conn);
        
        header('Location: bulk_reminder.php');
        exit;
    }
}

// Handle AJAX requests for filtering and fetching students
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && isset($_POST['csrf_token'])) {
    // Validate CSRF token
    $csrf_valid = false;
    $csrf_check_query = "SELECT id FROM csrf_tokens WHERE user_id = ? AND token = ? AND expires_at > NOW()";
    if ($csrf_stmt = $conn->prepare($csrf_check_query)) {
        $csrf_stmt->bind_param("is", $_SESSION['user_id'], $_POST['csrf_token']);
        if ($csrf_stmt->execute()) {
            $csrf_result = $csrf_stmt->get_result();
            $csrf_valid = $csrf_result->num_rows > 0;
            $csrf_result->free();
        }
        $csrf_stmt->close();
    }
    
    if (!$csrf_valid) {
        error_log("Invalid CSRF token for AJAX action {$_POST['action']}", 3, $_SERVER['DOCUMENT_ROOT'] . '/logs/error.log');
        header('Content-Type: application/json');
        http_response_code(403);
        echo json_encode(['error' => 'Invalid CSRF token']);
        exit;
    }
    
    header('Content-Type: application/json');
    
    if ($_POST['action'] === 'filter_classes') {
        $dept_id = (int)($_POST['department_id'] ?? 0);
        $filtered_classes = [];
        if ($dept_id > 0) {
            foreach ($classes as $id => $class) {
                if ($class['department_id'] == $dept_id) {
                    $filtered_classes[] = ['id' => $id, 'class_name' => $class['class_name']];
                }
            }
        } else {
            foreach ($classes as $id => $class) {
                $filtered_classes[] = ['id' => $id, 'class_name' => $class['class_name']];
            }
        }
        error_log("Filtered " . count($filtered_classes) . " classes for dept_id=$dept_id", 3, $_SERVER['DOCUMENT_ROOT'] . '/logs/activity.log');
        echo json_encode(['success' => true, 'classes' => $filtered_classes]);
        exit;
    }
    
    if ($_POST['action'] === 'filter_students') {
        $academic_year = trim($_POST['academic_year'] ?? '');
        $dept_id = (int)($_POST['department_id'] ?? 0);
        $class_id = (int)($_POST['class_id'] ?? 0);
        $search_query = trim($_POST['search_query'] ?? '');
        $filter_type = $_POST['filter_type'] ?? 'all'; // all, unpaid, partial, overdue
        
        if (!$academic_year) {
            echo json_encode(['success' => true, 'student_debts' => [], 'total_students' => 0, 'total_debt_amount' => 0]);
            exit;
        }
        
        // Build query to fetch students with debt details
        $students_query = "SELECT 
            s.id as student_id,
            s.name as student_name,
            c.class_name,
            c.id as class_id,
            COALESCE(SUM(d.amount_due), 0) as total_debt,
            f.fee_type,
            f.due_date,
            DATEDIFF(NOW(), f.due_date) as days_overdue,
            COALESCE(SUM(p.amount_paid), 0) as amount_paid,
            parent.name as parent_name,
            parent.phone as parent_phone,
            parent.email as parent_email
        FROM users s
        JOIN student_classes sc ON s.id = sc.user_id AND sc.academic_year = ?
        JOIN classes c ON sc.class_id = c.id
        LEFT JOIN debts d ON s.id = d.user_id AND d.academic_year = ?
        LEFT JOIN fees f ON d.fee_id = f.id AND f.academic_year = ?
        LEFT JOIN payments p ON f.id = p.fee_id AND s.id = p.user_id
        LEFT JOIN parent_student_mapping psm ON s.id = psm.student_id
        LEFT JOIN users parent ON psm.parent_id = parent.id
        WHERE s.role = 'student' AND s.status = 'active'";
        
        $params = [$academic_year, $academic_year, $academic_year];
        $types = "sss";
        
        if ($dept_id > 0 && isset($departments[$dept_id])) {
            $students_query .= " AND f.department = ?";
            $types .= "s";
            $params[] = $departments[$dept_id]['enum_name'];
        }
        
        if ($class_id > 0) {
            $students_query .= " AND sc.class_id = ?";
            $types .= "i";
            $params[] = $class_id;
        }
        
        if ($search_query) {
            $students_query .= " AND (s.name LIKE ? OR parent.name LIKE ? OR parent.phone LIKE ?)";
            $types .= "sss";
            $search_param = "%$search_query%";
            $params[] = $search_param;
            $params[] = $search_param;
            $params[] = $search_param;
        }
        
        $students_query .= " GROUP BY s.id, f.id HAVING total_debt > 0";
        
        // Apply additional filters
        if ($filter_type === 'unpaid') {
            $students_query .= " AND amount_paid = 0";
        } elseif ($filter_type === 'partial') {
            $students_query .= " AND amount_paid > 0 AND amount_paid < total_debt";
        } elseif ($filter_type === 'overdue') {
            $students_query .= " AND days_overdue > 0";
        }
        
        $students_query .= " ORDER BY days_overdue DESC, total_debt DESC, student_name";
        
        $students_stmt = $conn->prepare($students_query);
        $student_debts = [];
        $total_students = 0;
        $total_debt_amount = 0;
        $unique_students = [];
        
        if ($students_stmt) {
            if ($params) {
                $students_stmt->bind_param($types, ...$params);
            }
            $students_stmt->execute();
            $students_result = $students_stmt->get_result();
            
            while ($row = $students_result->fetch_assoc()) {
                $student_id = $row['student_id'];
                
                if (!isset($unique_students[$student_id])) {
                    $unique_students[$student_id] = [
                        'student_id' => $student_id,
                        'student_name' => $row['student_name'],
                        'class_name' => $row['class_name'],
                        'class_id' => $row['class_id'],
                        'parent_name' => $row['parent_name'],
                        'parent_phone' => $row['parent_phone'],
                        'parent_email' => $row['parent_email'],
                        'total_debt' => 0,
                        'fee_count' => 0,
                        'fees' => [],
                        'days_overdue' => $row['days_overdue'],
                        'amount_paid' => $row['amount_paid'],
                        'payment_status' => $row['amount_paid'] == 0 ? 'unpaid' : ($row['amount_paid'] < $row['total_debt'] ? 'partial' : 'paid')
                    ];
                    $total_students++;
                }
                
                $unique_students[$student_id]['total_debt'] += $row['total_debt'];
                $unique_students[$student_id]['fee_count']++;
                $unique_students[$student_id]['fees'][] = [
                    'fee_type' => $row['fee_type'],
                    'amount' => $row['total_debt'],
                    'due_date' => $row['due_date']
                ];
                $total_debt_amount += $row['total_debt'];
            }
            
            // Convert unique students array to list
            foreach ($unique_students as $student) {
                $student_debts[] = $student;
            }
            
            $students_stmt->close();
        } else {
            error_log("Students query prepare failed: " . $conn->error, 3, $_SERVER['DOCUMENT_ROOT'] . '/logs/error.log');
        }
        
        error_log("Filtered $total_students students with total debt: $total_debt_amount for academic_year=$academic_year", 3, $_SERVER['DOCUMENT_ROOT'] . '/logs/activity.log');
        
        echo json_encode([
            'success' => true,
            'student_debts' => $student_debts,
            'total_students' => $total_students,
            'total_debt_amount' => $total_debt_amount
        ]);
        exit;
    }
    
    if ($_POST['action'] === 'log_error') {
        $error_message = trim($_POST['message'] ?? 'Unknown client error');
        log_activity($_SESSION['user_id'], 'client_error', "Client-side error: $error_message", $conn);
        echo json_encode(['success' => true]);
        exit;
    }
}

// Initial data fetch
if ($selected_year) {
    $students_query = "SELECT 
        s.id as student_id,
        s.name as student_name,
        c.class_name,
        c.id as class_id,
        COALESCE(SUM(d.amount_due), 0) as total_debt,
        f.fee_type,
        f.due_date,
        DATEDIFF(NOW(), f.due_date) as days_overdue,
        COALESCE(SUM(p.amount_paid), 0) as amount_paid,
        parent.name as parent_name,
        parent.phone as parent_phone,
        parent.email as parent_email
    FROM users s
    JOIN student_classes sc ON s.id = sc.user_id AND sc.academic_year = ?
    JOIN classes c ON sc.class_id = c.id
    LEFT JOIN debts d ON s.id = d.user_id AND d.academic_year = ?
    LEFT JOIN fees f ON d.fee_id = f.id AND f.academic_year = ?
    LEFT JOIN payments p ON f.id = p.fee_id AND s.id = p.user_id
    LEFT JOIN parent_student_mapping psm ON s.id = psm.student_id
    LEFT JOIN users parent ON psm.parent_id = parent.id
    WHERE s.role = 'student' AND s.status = 'active'
    GROUP BY s.id, f.id
    HAVING total_debt > 0
    ORDER BY days_overdue DESC, total_debt DESC, student_name";
    
    if ($students_stmt = $conn->prepare($students_query)) {
        $students_stmt->bind_param("sss", $selected_year, $selected_year, $selected_year);
        $students_stmt->execute();
        $students_result = $students_stmt->get_result();
        
        $unique_students = [];
        while ($row = $students_result->fetch_assoc()) {
            $student_id = $row['student_id'];
            
            if (!isset($unique_students[$student_id])) {
                $unique_students[$student_id] = [
                    'student_id' => $student_id,
                    'student_name' => $row['student_name'],
                    'class_name' => $row['class_name'],
                    'class_id' => $row['class_id'],
                    'parent_name' => $row['parent_name'],
                    'parent_phone' => $row['parent_phone'],
                    'parent_email' => $row['parent_email'],
                    'total_debt' => 0,
                    'fee_count' => 0,
                    'fees' => [],
                    'days_overdue' => $row['days_overdue'],
                    'amount_paid' => $row['amount_paid'],
                    'payment_status' => $row['amount_paid'] == 0 ? 'unpaid' : ($row['amount_paid'] < $row['total_debt'] ? 'partial' : 'paid')
                ];
                $total_students++;
            }
            
            $unique_students[$student_id]['total_debt'] += $row['total_debt'];
            $unique_students[$student_id]['fee_count']++;
            $unique_students[$student_id]['fees'][] = [
                'fee_type' => $row['fee_type'],
                'amount' => $row['total_debt'],
                'due_date' => $row['due_date']
            ];
            $total_debt_amount += $row['total_debt'];
        }
        
        // Convert unique students array to list
        foreach ($unique_students as $student) {
            $student_debts[] = $student;
        }
        
        $students_stmt->close();
    } else {
        error_log("Initial students query prepare failed: " . $conn->error, 3, $_SERVER['DOCUMENT_ROOT'] . '/logs/error.log');
    }
    
    log_activity($_SESSION['user_id'], 'viewed_bulk_reminder', 
        "Initial load for academic_year=$selected_year, Total Students: $total_students, Total Debt: ₵$total_debt_amount", 
        $conn);
}
?>

<!DOCTYPE html>
<html lang="en" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, shrink-to-fit=no">
    <meta name="description" content="Bulk SMS Reminder System for parent fee payments at Queen's Paradise Montessori School.">
    <meta name="keywords" content="Queen's Paradise Montessori School, SMS reminders, bulk messaging, fee payments, parent notifications">
    <meta name="author" content="Queen's Paradise Montessori School">
    <meta name="robots" content="noindex, nofollow">
    <meta property="og:title" content="Bulk SMS Reminder System - Queen's Paradise Montessori School">
    <meta property="og:description" content="Send bulk SMS reminders to parents about outstanding fees and payments.">
    <meta property="og:type" content="website">
    <meta property="og:url" content="https://qpmsedu.com/admin/bulk_reminder.php">
    <meta name="twitter:card" content="summary">
    <meta name="twitter:title" content="Bulk SMS Reminder System - Queen's Paradise Montessori School">
    <meta name="twitter:description" content="Send bulk SMS reminders to parents about outstanding fees and payments.">
    <title>Bulk SMS Reminder System - Queen's Paradise Montessori School</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css" rel="stylesheet">
    <style>
        :root {
            --primary-color: #ff6200;
            --secondary-color: #ff8c00;
            --accent-color: #ffd700;
            --success-color: #28a745;
            --warning-color: #ffc107;
            --info-color: #17a2b8;
            --danger-color: #dc3545;
            --light-bg: #f8f9fa;
            --border-radius: 12px;
            --box-shadow: 0 5px 15px rgba(255, 98, 0, 0.08);
            --transition: all 0.2s ease;
            --gradient-primary: linear-gradient(135deg, #ff6200 0%, #ff8c00 100%);
            --gradient-warning: linear-gradient(135deg, #ffc107 0%, #ffd700 100%);
            --gradient-danger: linear-gradient(135deg, #dc3545 0%, #ff6b6b 100%);
            --gradient-success: linear-gradient(135deg, #28a745 0%, #4cd964 100%);
        }

        body {
            background: linear-gradient(135deg, #fff8f2 0%, #fffdf5 100%);
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            color: #333;
            min-height: 100vh;
            background-attachment: fixed;
            font-size: 14px;
        }

        .dashboard-container {
            max-width: 100%;
            padding: 20px;
        }

        .page-header {
            background: white;
            border-radius: var(--border-radius);
            padding: 1.2rem 1.8rem;
            margin-bottom: 1.5rem;
            box-shadow: var(--box-shadow);
            border-left: 6px solid var(--primary-color);
            animation: slideInDown 0.6s ease;
            background: linear-gradient(to right, white, #fffbf5);
            position: relative;
            overflow: hidden;
        }

        .page-header::before {
            content: '';
            position: absolute;
            top: 0;
            right: 0;
            width: 120px;
            height: 120px;
            background: linear-gradient(135deg, rgba(255, 98, 0, 0.05) 0%, rgba(255, 140, 0, 0.05) 100%);
            border-radius: 50%;
            transform: translate(40%, -40%);
        }

        .page-header h1 {
            color: #ff6200;
            font-weight: 700;
            margin: 0;
            font-size: 1.6rem;
            position: relative;
        }

        .page-header .subtitle {
            color: #ff8c00;
            font-size: 0.85rem;
            margin: 0.3rem 0 0 0;
            font-weight: 500;
            position: relative;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 1.2rem;
            margin-bottom: 1.8rem;
        }

        .stat-card {
            background: white;
            border-radius: var(--border-radius);
            padding: 1.2rem;
            box-shadow: var(--box-shadow);
            transition: var(--transition);
            border: none;
            position: relative;
            overflow: hidden;
            cursor: pointer;
        }

        .stat-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 8px 20px rgba(255, 98, 0, 0.12);
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 3px;
        }

        .stat-card.students-count::before {
            background: var(--gradient-primary);
        }

        .stat-card.total-debt::before {
            background: var(--gradient-danger);
        }

        .stat-card.avg-debt::before {
            background: var(--gradient-warning);
        }

        .stat-card.overdue-count::before {
            background: var(--gradient-success);
        }

        .stat-card .stat-icon {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 0.8rem;
            font-size: 1.3rem;
            color: white;
            box-shadow: 0 3px 8px rgba(0, 0, 0, 0.1);
        }

        .stat-card.students-count .stat-icon {
            background: var(--gradient-primary);
        }

        .stat-card.total-debt .stat-icon {
            background: var(--gradient-danger);
        }

        .stat-card.avg-debt .stat-icon {
            background: var(--gradient-warning);
        }

        .stat-card.overdue-count .stat-icon {
            background: var(--gradient-success);
        }

        .stat-card .stat-value {
            font-size: 1.8rem;
            font-weight: 800;
            color: #ff6200;
            margin: 0.4rem 0;
            line-height: 1;
        }

        .stat-card.total-debt .stat-value {
            color: var(--danger-color);
        }

        .stat-card.avg-debt .stat-value {
            color: #e0a800;
        }

        .stat-card.overdue-count .stat-value {
            color: var(--success-color);
        }

        .stat-card .stat-label {
            color: #ff8c00;
            font-size: 0.75rem;
            margin: 0;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .stat-card.total-debt .stat-label {
            color: var(--danger-color);
        }

        .stat-card.avg-debt .stat-label {
            color: #e0a800;
        }

        .stat-card.overdue-count .stat-label {
            color: var(--success-color);
        }

        .stat-card .stat-description {
            font-size: 0.7rem;
            color: #6c757d;
            margin-top: 0.3rem;
            line-height: 1.3;
        }

        .filter-card {
            background: white;
            border-radius: var(--border-radius);
            padding: 1.2rem 1.5rem;
            margin-bottom: 1.8rem;
            box-shadow: var(--box-shadow);
            border: none;
            background: linear-gradient(to bottom, white, #fffbf5);
            position: relative;
            overflow: hidden;
        }

        .filter-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 5px;
            height: 100%;
            background: var(--gradient-primary);
        }

        .filter-card .card-title {
            color: #ff6200;
            font-weight: 700;
            font-size: 1.1rem;
            margin-bottom: 1.2rem;
            display: flex;
            align-items: center;
            gap: 0.6rem;
            padding-left: 0.8rem;
        }

        .filter-card .card-title i {
            background: var(--gradient-primary);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .filter-card .form-label {
            font-weight: 600;
            color: #ff6200;
            font-size: 0.75rem;
            margin-bottom: 0.3rem;
            display: flex;
            align-items: center;
            gap: 0.4rem;
        }

        .filter-card .form-label i {
            color: #ff8c00;
            font-size: 0.75rem;
        }

        .filter-card .form-control,
        .filter-card .form-select,
        .filter-card .form-textarea {
            border: 1.5px solid #ffe8d6;
            border-radius: 8px;
            padding: 0.5rem 0.8rem;
            font-size: 0.85rem;
            transition: var(--transition);
            background-color: white;
        }

        .filter-card .form-control:focus,
        .filter-card .form-select:focus,
        .filter-card .form-textarea:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 0.2rem rgba(255, 98, 0, 0.1);
        }

        .filter-card .form-textarea {
            min-height: 100px;
            resize: vertical;
            line-height: 1.4;
        }

        .btn-custom {
            background: var(--gradient-primary);
            border: none;
            border-radius: 8px;
            color: white;
            padding: 0.5rem 1.2rem;
            font-weight: 600;
            font-size: 0.8rem;
            transition: var(--transition);
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            text-decoration: none;
            box-shadow: 0 3px 8px rgba(255, 98, 0, 0.15);
            position: relative;
            overflow: hidden;
        }

        .btn-custom::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.2), transparent);
            transition: 0.5s;
        }

        .btn-custom:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 15px rgba(255, 98, 0, 0.25);
            color: white;
        }

        .btn-custom:hover::before {
            left: 100%;
        }

        .btn-custom.btn-outline {
            background: transparent;
            border: 1.5px solid var(--primary-color);
            color: var(--primary-color);
            box-shadow: none;
        }

        .btn-custom.btn-outline:hover {
            background: var(--gradient-primary);
            color: white;
            box-shadow: 0 6px 15px rgba(255, 98, 0, 0.25);
        }

        .btn-custom.btn-success {
            background: var(--gradient-success);
            box-shadow: 0 3px 8px rgba(40, 167, 69, 0.15);
        }

        .btn-custom.btn-success:hover {
            box-shadow: 0 6px 15px rgba(40, 167, 69, 0.25);
        }

        .btn-custom.btn-warning {
            background: var(--gradient-warning);
            box-shadow: 0 3px 8px rgba(255, 193, 7, 0.15);
            color: #333;
        }

        .btn-custom.btn-warning:hover {
            box-shadow: 0 6px 15px rgba(255, 193, 7, 0.25);
        }

        .btn-custom.btn-danger {
            background: var(--gradient-danger);
            box-shadow: 0 3px 8px rgba(220, 53, 69, 0.15);
        }

        .btn-custom.btn-danger:hover {
            box-shadow: 0 6px 15px rgba(220, 53, 69, 0.25);
        }

        .btn-custom.btn-sm {
            padding: 0.3rem 0.8rem;
            font-size: 0.7rem;
        }

        .section-title {
            color: #ff6200;
            font-weight: 700;
            font-size: 1.2rem;
            margin: 1.5rem 0 1rem 0;
            padding-bottom: 0.6rem;
            border-bottom: 2px solid #ffe8d6;
            position: relative;
            display: flex;
            align-items: center;
            gap: 0.6rem;
        }

        .section-title::after {
            content: '';
            flex: 1;
            height: 2px;
            background: linear-gradient(to right, #ffe8d6, transparent);
            margin-left: 0.8rem;
        }

        .section-title i {
            background: var(--gradient-primary);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            font-size: 1.2rem;
        }

        .table-container {
            background: white;
            border-radius: var(--border-radius);
            overflow: hidden;
            box-shadow: var(--box-shadow);
            margin-bottom: 1.8rem;
            border: none;
            position: relative;
        }

        .table-container::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 5px;
            height: 100%;
            background: var(--gradient-primary);
        }

        .table {
            margin: 0;
            border-collapse: separate;
            border-spacing: 0;
            font-size: 0.8rem;
        }

        .table thead th {
            background: var(--gradient-primary);
            color: white;
            border: none;
            padding: 0.8rem 1rem;
            font-weight: 600;
            font-size: 0.7rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            position: relative;
            white-space: nowrap;
        }

        .table thead th:first-child {
            border-top-left-radius: 6px;
        }

        .table thead th:last-child {
            border-top-right-radius: 6px;
        }

        .table tbody tr {
            transition: var(--transition);
            border-bottom: 1px solid #ffe8d6;
        }

        .table tbody tr:last-child {
            border-bottom: none;
        }

        .table tbody tr:hover {
            background-color: rgba(255, 248, 242, 0.5);
        }

        .table tbody td {
            padding: 0.7rem 1rem;
            vertical-align: middle;
            border: none;
        }

        .badge-status {
            padding: 0.25rem 0.7rem;
            border-radius: 30px;
            font-size: 0.7rem;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 0.3rem;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.05);
        }

        .badge-unpaid {
            background: var(--gradient-danger);
            color: white;
        }

        .badge-partial {
            background: var(--gradient-warning);
            color: #333;
        }

        .badge-overdue {
            background: var(--gradient-primary);
            color: white;
        }

        .loading-spinner {
            display: none;
            margin-left: 0.5rem;
        }

        .no-data {
            text-align: center;
            padding: 2rem;
            color: #ff8c00;
            background: rgba(255, 248, 242, 0.5);
        }

        .no-data i {
            font-size: 2.5rem;
            margin-bottom: 1rem;
            color: #ffe8d6;
            opacity: 0.7;
        }

        .no-data p {
            font-size: 0.9rem;
            margin-bottom: 0.3rem;
        }

        .no-data small {
            font-size: 0.75rem;
            color: #ff8c00;
        }

        .alert-custom {
            border-radius: var(--border-radius);
            border: none;
            box-shadow: var(--box-shadow);
            padding: 0.8rem 1.2rem;
            margin-bottom: 1rem;
            position: relative;
            overflow: hidden;
            font-size: 0.85rem;
        }

        .alert-custom::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 5px;
            height: 100%;
        }

        .alert-success {
            background: linear-gradient(to right, rgba(40, 167, 69, 0.1), rgba(40, 167, 69, 0.05));
            border-left: 5px solid var(--success-color);
            color: var(--success-color);
        }

        .alert-danger {
            background: linear-gradient(to right, rgba(220, 53, 69, 0.1), rgba(220, 53, 69, 0.05));
            border-left: 5px solid var(--danger-color);
            color: var(--danger-color);
        }

        .alert-warning {
            background: linear-gradient(to right, rgba(255, 193, 7, 0.1), rgba(255, 193, 7, 0.05));
            border-left: 5px solid var(--warning-color);
            color: #e0a800;
        }

        .alert-info {
            background: linear-gradient(to right, rgba(23, 162, 184, 0.1), rgba(23, 162, 184, 0.05));
            border-left: 5px solid var(--info-color);
            color: var(--info-color);
        }

        .alert-custom i {
            margin-right: 0.5rem;
            font-size: 0.9rem;
        }

        @keyframes slideInDown {
            from {
                opacity: 0;
                transform: translateY(-20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
            }
            to {
                opacity: 1;
            }
        }

        @media (max-width: 768px) {
            .dashboard-container {
                padding: 12px;
            }

            .page-header {
                padding: 1rem;
            }

            .page-header h1 {
                font-size: 1.3rem;
            }

            .stats-grid {
                grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
                gap: 0.8rem;
            }

            .stat-card {
                padding: 1rem;
            }

            .table-container {
                overflow-x: auto;
                border-radius: 10px;
            }

            .table {
                font-size: 0.75rem;
                min-width: 700px;
            }

            .table thead th,
            .table tbody td {
                padding: 0.6rem;
            }
        }

        @media (max-width: 480px) {
            .stats-grid {
                grid-template-columns: 1fr 1fr;
                gap: 0.6rem;
            }
            
            .stat-card .stat-value {
                font-size: 1.4rem;
            }
        }

        ::-webkit-scrollbar {
            width: 8px;
            height: 8px;
        }

        ::-webkit-scrollbar-track {
            background: #fff5f0;
            border-radius: 4px;
        }

        ::-webkit-scrollbar-thumb {
            background: var(--gradient-primary);
            border-radius: 4px;
        }

        ::-webkit-scrollbar-thumb:hover {
            background: linear-gradient(to bottom, #e55a00, #e08300);
        }

        .form-check-input:checked {
            background-color: var(--primary-color);
            border-color: var(--primary-color);
        }

        .form-check-input:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 0.2rem rgba(255, 98, 0, 0.2);
        }

        .template-card {
            border: 1.5px solid #ffe8d6;
            border-radius: 10px;
            padding: 0.8rem;
            cursor: pointer;
            transition: var(--transition);
            background: white;
            height: 100%;
            position: relative;
            overflow: hidden;
        }

        .template-card:hover {
            border-color: var(--primary-color);
            background-color: #fff9f0;
            transform: translateY(-3px);
            box-shadow: 0 5px 12px rgba(255, 98, 0, 0.08);
        }

        .template-card.selected {
            border-color: var(--primary-color);
            background-color: rgba(255, 98, 0, 0.05);
            box-shadow: 0 3px 8px rgba(255, 98, 0, 0.1);
        }

        .template-card .template-title {
            font-weight: 700;
            color: #ff6200;
            margin-bottom: 0.4rem;
            font-size: 0.85rem;
            display: flex;
            align-items: center;
            gap: 0.3rem;
        }

        .template-card .template-title i {
            font-size: 0.8rem;
        }

        .template-card .template-desc {
            font-size: 0.7rem;
            color: #666;
            margin-bottom: 0.5rem;
            line-height: 1.3;
        }

        .template-card .template-preview {
            font-size: 0.65rem;
            color: #888;
            background: #f8f9fa;
            padding: 0.5rem;
            border-radius: 6px;
            border-left: 3px solid #dee2e6;
            font-style: italic;
            line-height: 1.3;
        }

        .selection-actions {
            display: flex;
            gap: 0.6rem;
            margin-bottom: 1rem;
            flex-wrap: wrap;
            align-items: center;
        }

        .selected-count {
            background: var(--gradient-primary);
            color: white;
            padding: 0.25rem 0.8rem;
            border-radius: 30px;
            font-size: 0.75rem;
            font-weight: 600;
            box-shadow: 0 2px 6px rgba(255, 98, 0, 0.15);
            display: inline-flex;
            align-items: center;
            gap: 0.3rem;
        }

        .sms-preview {
            background: linear-gradient(to right, rgba(23, 162, 184, 0.05), rgba(23, 162, 184, 0.02));
            border-radius: 10px;
            padding: 1rem;
            margin-top: 1rem;
            border-left: 5px solid var(--info-color);
            box-shadow: 0 3px 8px rgba(0, 0, 0, 0.05);
        }

        .sms-preview .preview-title {
            color: var(--info-color);
            font-weight: 700;
            margin-bottom: 0.6rem;
            font-size: 0.85rem;
            display: flex;
            align-items: center;
            gap: 0.3rem;
        }

        .sms-preview .preview-content {
            background: white;
            padding: 0.8rem;
            border-radius: 6px;
            border: 1px solid #dee2e6;
            font-family: 'Courier New', monospace;
            white-space: pre-wrap;
            word-break: break-word;
            line-height: 1.4;
            max-height: 200px;
            overflow-y: auto;
            font-size: 0.75rem;
        }

        .placeholder-hint {
            font-size: 0.7rem;
            color: #6c757d;
            margin-top: 0.3rem;
            padding: 0.3rem;
            background: #f8f9fa;
            border-radius: 5px;
            border-left: 3px solid #dee2e6;
        }

        .placeholder-hint code {
            background: #e9ecef;
            padding: 0.1rem 0.3rem;
            border-radius: 3px;
            font-family: 'Courier New', monospace;
            color: #e83e8c;
            font-weight: 600;
            margin: 0 0.1rem;
            font-size: 0.65rem;
        }

        .student-details {
            background: #f8f9fa;
            border-radius: 6px;
            padding: 0.6rem;
            margin-top: 0.3rem;
            border-left: 3px solid var(--primary-color);
        }

        .student-details .detail-item {
            display: flex;
            justify-content: space-between;
            padding: 0.2rem 0;
            border-bottom: 1px dashed #dee2e6;
            font-size: 0.7rem;
        }

        .student-details .detail-item:last-child {
            border-bottom: none;
        }

        .student-details .detail-label {
            font-weight: 600;
            color: #666;
        }

        .student-details .detail-value {
            color: #333;
            font-weight: 500;
        }

        .fee-breakdown {
            background: white;
            border-radius: 6px;
            padding: 0.6rem;
            margin-top: 0.3rem;
            border: 1px solid #dee2e6;
            max-height: 150px;
            overflow-y: auto;
        }

        .fee-item {
            display: flex;
            justify-content: space-between;
            padding: 0.3rem;
            border-bottom: 1px solid #f8f9fa;
            font-size: 0.7rem;
        }

        .fee-item:last-child {
            border-bottom: none;
        }

        .fee-item .fee-type {
            font-weight: 500;
            color: #333;
        }

        .fee-item .fee-amount {
            font-weight: 600;
            color: var(--danger-color);
        }

        .animate__delay-1s {
            animation-delay: 1s;
        }

        .animate__delay-2s {
            animation-delay: 2s;
        }

        .loading-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(255, 255, 255, 0.9);
            display: none;
            justify-content: center;
            align-items: center;
            z-index: 9999;
            flex-direction: column;
        }

        .loading-overlay .spinner-border {
            width: 2.5rem;
            height: 2.5rem;
            color: var(--primary-color);
        }

        .loading-overlay .loading-text {
            margin-top: 1rem;
            font-size: 0.9rem;
            color: var(--primary-color);
            font-weight: 600;
        }

        .avatar-placeholder {
            width: 32px;
            height: 32px;
            background: var(--gradient-primary);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: bold;
            font-size: 0.8rem;
        }
    </style>
</head>
<body>
    <!-- Loading Overlay -->
    <div class="loading-overlay" id="loadingOverlay">
        <div class="spinner-border" role="status">
            <span class="visually-hidden">Loading...</span>
        </div>
        <div class="loading-text" id="loadingText">Processing...</div>
    </div>

    <?php 
    $header_path = $_SERVER['DOCUMENT_ROOT'] . '/templates/admin_header.php';
    if (!file_exists($header_path)) {
        error_log("Admin header file missing: $header_path", 3, $_SERVER['DOCUMENT_ROOT'] . '/logs/error.log');
        log_activity($_SESSION['user_id'], 'error', "Failed to load admin header template at $header_path", $conn);
        $_SESSION['error_msg'] = "Error: Admin header template is missing. Please contact the administrator.";
        header("Location: /login.php");
        exit();
    }
    include $header_path; 
    ?>

    <div class="dashboard-container">
        <!-- Page Header -->
        <div class="page-header animate__animated animate__fadeIn">
            <h1><i class="fas fa-comment-sms" style="color: #ff6200;"></i> Bulk SMS Reminder System</h1>
            <p class="subtitle">Send personalized SMS reminders to parents about their children's outstanding fees</p>
        </div>

        <!-- Alerts -->
        <?php if (isset($_SESSION['success_msg'])): ?>
            <div class="alert alert-success alert-custom alert-dismissible fade show animate__animated animate__fadeInDown" role="alert">
                <i class="fas fa-check-circle me-2"></i>
                <?php echo htmlspecialchars($_SESSION['success_msg']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                <?php unset($_SESSION['success_msg']); ?>
            </div>
        <?php endif; ?>
        
        <?php if (isset($_SESSION['error_msg'])): ?>
            <div class="alert alert-danger alert-custom alert-dismissible fade show animate__animated animate__fadeInDown" role="alert">
                <i class="fas fa-exclamation-circle me-2"></i>
                <?php echo htmlspecialchars($_SESSION['error_msg']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                <?php unset($_SESSION['error_msg']); ?>
            </div>
        <?php endif; ?>
        
        <?php if (isset($_SESSION['warning_details']) && is_array($_SESSION['warning_details'])): ?>
            <div class="alert alert-warning alert-custom alert-dismissible fade show animate__animated animate__fadeInDown" role="alert">
                <i class="fas fa-exclamation-triangle me-2"></i>
                <strong>Some reminders failed to send:</strong>
                <ul class="mb-0 mt-2">
                    <?php foreach ($_SESSION['warning_details'] as $detail): ?>
                        <li><?php echo htmlspecialchars($detail); ?></li>
                    <?php endforeach; ?>
                </ul>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                <?php unset($_SESSION['warning_details']); ?>
            </div>
        <?php endif; ?>

        <?php if (empty($academic_years)): ?>
            <div class="alert alert-warning alert-custom animate__animated animate__fadeIn">
                <i class="fas fa-exclamation-triangle me-2"></i>
                No academic years found in the database. Please add academic years in the academic calendar.
            </div>
        <?php endif; ?>

        <!-- Stats Cards -->
        <div class="stats-grid">
            <div class="stat-card students-count animate__animated animate__fadeInLeft">
                <div class="stat-icon">
                    <i class="fas fa-user-graduate"></i>
                </div>
                <div class="stat-value" id="students_count"><?php echo $total_students; ?></div>
                <div class="stat-label">Students with Outstanding Fees</div>
                <div class="stat-description">Students who owe fees for selected academic year</div>
            </div>

            <div class="stat-card total-debt animate__animated animate__fadeInUp animate__delay-1s">
                <div class="stat-icon">
                    <i class="fas fa-money-bill-wave"></i>
                </div>
                <div class="stat-value" id="total_debt">₵<?php echo number_format($total_debt_amount, 2); ?></div>
                <div class="stat-label">Total Outstanding Amount</div>
                <div class="stat-description">Sum of all outstanding student fees</div>
            </div>

            <div class="stat-card avg-debt animate__animated animate__fadeInRight animate__delay-2s">
                <div class="stat-icon">
                    <i class="fas fa-calculator"></i>
                </div>
                <div class="stat-value" id="avg_debt">₵<?php echo $total_students > 0 ? number_format($total_debt_amount / $total_students, 2) : '0.00'; ?></div>
                <div class="stat-label">Average Debt per Student</div>
                <div class="stat-description">Average outstanding amount per student</div>
            </div>
            
            <div class="stat-card overdue-count animate__animated animate__fadeInLeft animate__delay-1s">
                <div class="stat-icon">
                    <i class="fas fa-clock"></i>
                </div>
                <div class="stat-value" id="overdue_count">0</div>
                <div class="stat-label">Overdue Payments</div>
                <div class="stat-description">Payments past due date</div>
            </div>
        </div>

        <!-- Filter Card -->
        <div class="filter-card animate__animated animate__fadeIn">
            <div class="card-title">
                <i class="fas fa-filter"></i> Filter Students with Outstanding Fees
            </div>
            <div class="row g-2">
                <div class="col-md-3">
                    <label for="academic_year" class="form-label">
                        <i class="fas fa-calendar-alt"></i> Academic Year
                    </label>
                    <select name="academic_year" id="academic_year" class="form-select" required>
                        <option value="">Select Academic Year</option>
                        <?php foreach ($academic_years as $year): ?>
                            <option value="<?php echo htmlspecialchars($year); ?>" <?php echo $selected_year === $year ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($year); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label for="department_id" class="form-label">
                        <i class="fas fa-building"></i> Department
                    </label>
                    <select name="department_id" id="department_id" class="form-select">
                        <option value="">All Departments</option>
                        <?php foreach ($departments as $id => $dept): ?>
                            <option value="<?php echo htmlspecialchars($id); ?>">
                                <?php echo htmlspecialchars($dept['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label for="class_id" class="form-label">
                        <i class="fas fa-chalkboard-teacher"></i> Class
                    </label>
                    <select name="class_id" id="class_id" class="form-select">
                        <option value="">All Classes</option>
                        <?php foreach ($classes as $id => $class): ?>
                            <option value="<?php echo htmlspecialchars($id); ?>" data-dept="<?php echo htmlspecialchars($class['department_id']); ?>">
                                <?php echo htmlspecialchars($class['class_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label for="filter_type" class="form-label">
                        <i class="fas fa-filter"></i> Filter by Status
                    </label>
                    <select name="filter_type" id="filter_type" class="form-select">
                        <option value="all">All Students with Debts</option>
                        <option value="unpaid">Unpaid Only</option>
                        <option value="partial">Partial Payments Only</option>
                        <option value="overdue">Overdue Only</option>
                    </select>
                </div>
            </div>
            <div class="row mt-3">
                <div class="col-md-12">
                    <div class="d-flex justify-content-end gap-2">
                        <button type="button" class="btn-custom" id="filter_btn">
                            <i class="fas fa-filter me-1"></i>Apply Filters
                            <span class="loading-spinner"><i class="fas fa-spinner fa-spin"></i></span>
                        </button>
                        <button type="button" class="btn-custom btn-outline" id="select_all_btn">
                            <i class="fas fa-check-square me-1"></i>Select All
                        </button>
                        <button type="button" class="btn-custom btn-outline" id="deselect_all_btn">
                            <i class="fas fa-times-circle me-1"></i>Deselect All
                        </button>
                        <button type="button" class="btn-custom btn-warning" id="quick_preview_btn">
                            <i class="fas fa-eye me-1"></i>Quick Preview
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Students List -->
        <div class="d-flex justify-content-between align-items-center mb-2">
            <h3 class="section-title">
                <i class="fas fa-user-graduate"></i>Students with Outstanding Fees
            </h3>
            <div class="d-flex align-items-center gap-2">
                <span class="selected-count" id="selected_count">
                    <i class="fas fa-users"></i>
                    <span id="selected_count_text">0 selected</span>
                </span>
                <button type="button" class="btn-custom btn-sm" id="view_details_btn" style="padding: 0.3rem 0.8rem;" disabled>
                    <i class="fas fa-info-circle me-1"></i>View Details
                </button>
            </div>
        </div>
        
        <div class="table-container animate__animated animate__fadeIn">
            <div class="table-responsive">
                <table class="table" id="students_table">
                    <thead>
                        <tr>
                            <th width="50">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="select_all_checkbox">
                                </div>
                            </th>
                            <th>Student Name</th>
                            <th>Class</th>
                            <th>Parent Details</th>
                            <th>Total Debt (GH₵)</th>
                            <th>Fee Breakdown</th>
                            <th>Status</th>
                            <th>Due Date</th>
                            <th>Days Overdue</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($student_debts)): ?>
                            <tr>
                                <td colspan="9" class="no-data">
                                    <i class="fas fa-user-graduate"></i>
                                    <p>No students with outstanding fees found for the selected filters.</p>
                                    <small>Please select an academic year and apply filters to see student data.</small>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($student_debts as $student): 
                                $status_class = '';
                                $status_icon = '';
                                if ($student['payment_status'] === 'unpaid') {
                                    $status_class = 'badge-unpaid';
                                    $status_icon = 'fa-times-circle';
                                } elseif ($student['payment_status'] === 'partial') {
                                    $status_class = 'badge-partial';
                                    $status_icon = 'fa-clock';
                                } else {
                                    $status_class = 'badge-overdue';
                                    $status_icon = 'fa-exclamation-circle';
                                }
                                
                                $days_overdue = $student['days_overdue'] ?? 0;
                                $is_overdue = $days_overdue > 0;
                                $latest_due_date = !empty($student['fees']) ? max(array_column($student['fees'], 'due_date')) : '';
                            ?>
                                <tr>
                                    <td>
                                        <div class="form-check">
                                            <input class="form-check-input student-checkbox" type="checkbox" value="<?php echo $student['student_id']; ?>">
                                        </div>
                                    </td>
                                    <td>
                                        <div class="d-flex align-items-center gap-2">
                                            <div class="avatar-placeholder">
                                                <?php echo strtoupper(substr($student['student_name'], 0, 1)); ?>
                                            </div>
                                            <div>
                                                <strong><?php echo htmlspecialchars($student['student_name']); ?></strong>
                                                <div class="text-muted" style="font-size: 0.7rem;">
                                                    <?php echo $student['fee_count']; ?> fee<?php echo $student['fee_count'] > 1 ? 's' : ''; ?>
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="badge" style="background: #ffe8d6; color: #ff6200; font-weight: 600; font-size: 0.7rem;">
                                            <?php echo htmlspecialchars($student['class_name']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if (!empty($student['parent_name'])): ?>
                                            <div class="student-details">
                                                <div class="detail-item">
                                                    <span class="detail-label">Parent:</span>
                                                    <span class="detail-value"><?php echo htmlspecialchars($student['parent_name']); ?></span>
                                                </div>
                                                <?php if (!empty($student['parent_phone'])): ?>
                                                <div class="detail-item">
                                                    <span class="detail-label">Phone:</span>
                                                    <span class="detail-value"><?php echo htmlspecialchars($student['parent_phone']); ?></span>
                                                </div>
                                                <?php endif; ?>
                                                <?php if (!empty($student['parent_email'])): ?>
                                                <div class="detail-item">
                                                    <span class="detail-label">Email:</span>
                                                    <span class="detail-value"><?php echo htmlspecialchars($student['parent_email']); ?></span>
                                                </div>
                                                <?php endif; ?>
                                            </div>
                                        <?php else: ?>
                                            <span class="text-danger" style="font-size: 0.7rem;">No parent assigned</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="fw-bold" style="color: #dc3545; font-size: 0.9rem;">
                                        GH₵ <?php echo number_format($student['total_debt'], 2); ?>
                                    </td>
                                    <td>
                                        <?php if (!empty($student['fees'])): ?>
                                            <div class="fee-breakdown">
                                                <?php foreach ($student['fees'] as $fee): ?>
                                                    <div class="fee-item">
                                                        <span class="fee-type"><?php echo htmlspecialchars($fee['fee_type']); ?></span>
                                                        <span class="fee-amount">GH₵ <?php echo number_format($fee['amount'], 2); ?></span>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php else: ?>
                                            <span class="text-muted" style="font-size: 0.7rem;">No fee details</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="badge-status <?php echo $status_class; ?>">
                                            <i class="fas <?php echo $status_icon; ?>"></i>
                                            <?php echo ucfirst($student['payment_status']); ?>
                                        </span>
                                    </td>
                                    <td style="font-size: 0.7rem;">
                                        <?php if (!empty($latest_due_date)): ?>
                                            <?php echo date('j M, Y', strtotime($latest_due_date)); ?>
                                        <?php else: ?>
                                            <span class="text-muted">N/A</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($is_overdue): ?>
                                            <span class="badge-status badge-unpaid" style="font-size: 0.65rem; padding: 0.2rem 0.5rem;">
                                                <i class="fas fa-exclamation-triangle"></i>
                                                <?php echo $days_overdue; ?> days
                                            </span>
                                        <?php else: ?>
                                            <span class="badge" style="background: #d4edda; color: #155724; font-size: 0.65rem; padding: 0.2rem 0.5rem;">
                                                <i class="fas fa-check-circle"></i>
                                                On time
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Message Composition -->
        <h3 class="section-title animate__animated animate__fadeIn">
            <i class="fas fa-comment-alt"></i>Compose Personalized SMS Message
        </h3>
        
        <div class="filter-card animate__animated animate__fadeIn">
            <form method="POST" id="reminder_form">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                <input type="hidden" name="action" value="send_reminders">
                <input type="hidden" name="academic_year" id="form_academic_year" value="<?php echo htmlspecialchars($selected_year); ?>">
                <input type="hidden" name="department_id" id="form_department_id" value="">
                <input type="hidden" name="class_id" id="form_class_id" value="">
                <input type="hidden" name="selected_students" id="selected_students_input" value="[]">
                
                <div class="row g-2">
                    <div class="col-md-12">
                        <label class="form-label">
                            <i class="fas fa-envelope"></i> Message Type & Template
                        </label>
                        <div class="row g-2" id="message_templates">
                            <div class="col-md-4 col-sm-6">
                                <div class="template-card" data-type="general">
                                    <div class="template-title">
                                        <i class="fas fa-bell"></i> General Reminder
                                    </div>
                                    <div class="template-desc">Standard friendly reminder with fee details</div>
                                    <div class="template-preview">Dear Parent, this is a reminder about outstanding fees for {student_name}...</div>
                                </div>
                            </div>
                            <div class="col-md-4 col-sm-6">
                                <div class="template-card" data-type="urgent">
                                    <div class="template-title">
                                        <i class="fas fa-exclamation-triangle"></i> Urgent Notice
                                    </div>
                                    <div class="template-desc">For immediate attention required cases</div>
                                    <div class="template-preview">URGENT: {student_name} has outstanding fees that require immediate attention...</div>
                                </div>
                            </div>
                            <div class="col-md-4 col-sm-6">
                                <div class="template-card" data-type="partial">
                                    <div class="template-title">
                                        <i class="fas fa-percentage"></i> Partial Payment
                                    </div>
                                    <div class="template-desc">For partially paid fees with remaining balance</div>
                                    <div class="template-preview">Reminder: {student_name} has partially paid fees. Remaining balance: GH₵{total_debt}...</div>
                                </div>
                            </div>
                            <div class="col-md-4 col-sm-6">
                                <div class="template-card" data-type="overdue">
                                    <div class="template-title">
                                        <i class="fas fa-clock"></i> Overdue Notice
                                    </div>
                                    <div class="template-desc">For payments past due date with penalty warning</div>
                                    <div class="template-preview">OVERDUE NOTICE: {student_name}'s fees of GH₵{total_debt} are overdue by {days_overdue} days...</div>
                                </div>
                            </div>
                            <div class="col-md-4 col-sm-6">
                                <div class="template-card" data-type="upcoming">
                                    <div class="template-title">
                                        <i class="fas fa-calendar-check"></i> Upcoming Payment
                                    </div>
                                    <div class="template-desc">For upcoming due dates as advance notice</div>
                                    <div class="template-preview">Friendly reminder: Upcoming fee payment for {student_name} is due on {due_date}...</div>
                                </div>
                            </div>
                            <div class="col-md-4 col-sm-6">
                                <div class="template-card" data-type="custom">
                                    <div class="template-title">
                                        <i class="fas fa-edit"></i> Custom Message
                                    </div>
                                    <div class="template-desc">Write your own personalized message</div>
                                    <div class="template-preview">Click to write custom personalized message...</div>
                                </div>
                            </div>
                        </div>
                        <input type="hidden" name="message_type" id="message_type" value="general">
                    </div>
                    
                    <div class="col-md-12 mt-3" id="custom_message_container" style="display: none;">
                        <label for="custom_message" class="form-label">
                            <i class="fas fa-edit"></i> Custom Message Content
                        </label>
                        <textarea name="custom_message" id="custom_message" class="form-control form-textarea" 
                                  placeholder="Write your custom message here. You can use placeholders to personalize each message:" 
                                  rows="4"></textarea>
                        <div class="placeholder-hint mt-2">
                            <strong>Available placeholders:</strong><br>
                            <code>{student_name}</code> - Student's full name<br>
                            <code>{class_name}</code> - Student's class<br>
                            <code>{total_debt}</code> - Total outstanding amount<br>
                            <code>{days_overdue}</code> - Number of days overdue<br>
                            <code>{academic_year}</code> - Current academic year<br>
                            <code>{due_date}</code> - Next due date<br>
                            <code>{parent_name}</code> - Parent's name<br>
                            <code>{fee_breakdown}</code> - List of fees and amounts
                        </div>
                    </div>
                    
                    <div class="col-md-12 mt-3" id="sms_preview" style="display: none;">
                        <div class="sms-preview">
                            <div class="preview-title">
                                <i class="fas fa-eye"></i> Message Preview (Sample)
                            </div>
                            <div class="preview-content" id="message_preview"></div>
                            <div class="d-flex justify-content-between align-items-center mt-2">
                                <small class="text-muted" style="font-size: 0.65rem;">
                                    Character count: <span id="char_count">0</span> (SMS limit: 160 characters per message)
                                </small>
                                <small class="text-muted" style="font-size: 0.65rem;">
                                    Estimated messages: <span id="estimated_messages">0</span>
                                </small>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="row mt-4">
                    <div class="col-md-12">
                        <div class="d-flex justify-content-between align-items-center p-2" style="background: linear-gradient(to right, rgba(255, 98, 0, 0.05), rgba(255, 140, 0, 0.05)); border-radius: 10px;">
                            <div>
                                <h6 class="mb-1" style="color: #ff6200; font-size: 0.85rem;">
                                    <i class="fas fa-paper-plane me-2"></i>Sending Summary
                                </h6>
                                <div class="d-flex gap-3">
                                    <div>
                                        <small class="text-muted" style="font-size: 0.7rem;">Selected Students:</small>
                                        <div class="fw-bold" style="font-size: 0.85rem;" id="form_selected_count">0</div>
                                    </div>
                                    <div>
                                        <small class="text-muted" style="font-size: 0.7rem;">Total Amount:</small>
                                        <div class="fw-bold text-danger" style="font-size: 0.85rem;" id="form_total_debt">GH₵ 0.00</div>
                                    </div>
                                    <div>
                                        <small class="text-muted" style="font-size: 0.7rem;">Estimated Cost:</small>
                                        <div class="fw-bold text-warning" style="font-size: 0.85rem;" id="sms_cost">0 SMS units</div>
                                    </div>
                                </div>
                            </div>
                            <div class="d-flex gap-2">
                                <button type="button" class="btn-custom btn-warning btn-sm" id="preview_btn">
                                    <i class="fas fa-eye me-1"></i>Preview All Messages
                                </button>
                                <button type="submit" class="btn-custom btn-success btn-sm" id="send_btn" disabled>
                                    <i class="fas fa-paper-plane me-1"></i>Send Reminders
                                    <span class="loading-spinner"><i class="fas fa-spinner fa-spin"></i></span>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- Confirmation Modal -->
    <div class="modal fade" id="confirmModal" tabindex="-1" aria-labelledby="confirmModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content">
                <div class="modal-header" style="background: var(--gradient-primary); color: white; padding: 0.8rem 1rem;">
                    <h5 class="modal-title" id="confirmModalLabel" style="font-size: 1rem;">
                        <i class="fas fa-paper-plane me-2"></i>Confirm Sending Reminders
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body" style="padding: 1rem;">
                    <div class="alert alert-info" style="padding: 0.6rem; font-size: 0.85rem;">
                        <i class="fas fa-info-circle me-2"></i>
                        You are about to send SMS reminders to parents of <strong id="modal_student_count">0</strong> student(s).
                    </div>
                    
                    <div class="row mt-3">
                        <div class="col-md-6">
                            <div class="card border-0 shadow-sm">
                                <div class="card-body" style="padding: 0.8rem;">
                                    <h6 class="card-title text-primary" style="font-size: 0.85rem;">
                                        <i class="fas fa-info-circle me-2"></i>Sending Details
                                    </h6>
                                    <div class="mt-2">
                                        <div class="d-flex justify-content-between mb-1" style="font-size: 0.75rem;">
                                            <span class="text-muted">Academic Year:</span>
                                            <span class="fw-bold" id="modal_academic_year"></span>
                                        </div>
                                        <div class="d-flex justify-content-between mb-1" style="font-size: 0.75rem;">
                                            <span class="text-muted">Total Amount:</span>
                                            <span class="fw-bold text-danger" id="modal_total_debt">GH₵ 0.00</span>
                                        </div>
                                        <div class="d-flex justify-content-between mb-1" style="font-size: 0.75rem;">
                                            <span class="text-muted">Estimated Cost:</span>
                                            <span class="fw-bold text-warning" id="modal_sms_cost">0 SMS units</span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="card border-0 shadow-sm">
                                <div class="card-body" style="padding: 0.8rem;">
                                    <h6 class="card-title text-primary" style="font-size: 0.85rem;">
                                        <i class="fas fa-message me-2"></i>Message Preview
                                    </h6>
                                    <div class="mt-2 p-2 bg-light rounded" id="modal_message_preview" style="max-height: 150px; overflow-y: auto; font-size: 0.75rem;"></div>
                                    <small class="text-muted mt-1 d-block" style="font-size: 0.65rem;">This is a sample preview. Each parent will receive a personalized message.</small>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-check mt-3">
                        <input class="form-check-input" type="checkbox" id="confirm_send">
                        <label class="form-check-label" for="confirm_send" style="font-size: 0.8rem;">
                            I confirm that I want to send these personalized reminders to parents
                        </label>
                    </div>
                </div>
                <div class="modal-footer" style="padding: 0.8rem;">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-success btn-sm" id="confirm_send_btn" disabled>
                        <i class="fas fa-paper-plane me-1"></i>Send Now
                    </button>
                </div>
            </div>
        </div>
    </div>

    <?php 
    $footer_path = $_SERVER['DOCUMENT_ROOT'] . '/templates/admin_footer.php';
    if (!file_exists($footer_path)) {
        error_log("Admin footer file missing: $footer_path", 3, $_SERVER['DOCUMENT_ROOT'] . '/logs/error.log');
        log_activity($_SESSION['user_id'], 'error', "Failed to load admin footer template at $footer_path", $conn);
        $_SESSION['error_msg'] = "Error: Admin footer template is missing. Please contact the administrator.";
        header("Location: /login.php");
        exit();
    }
    include $footer_path;
    $conn->close();
    ?>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>
    <script>
        $(document).ready(function() {
            try {
                // Initialize variables
                let selectedStudents = new Set();
                let studentDebts = <?php echo json_encode($student_debts); ?>;
                let currentAcademicYear = $('#academic_year').val();
                let currentMessageType = 'general';
                let currentCustomMessage = '';
                let totalSelectedDebt = 0;
                
                // Initialize tooltips
                const tooltipTriggerList = [].slice.call(document.querySelectorAll('[title]'));
                tooltipTriggerList.forEach(function(tooltipTriggerEl) {
                    new bootstrap.Tooltip(tooltipTriggerEl);
                });
                
                // Show loading overlay
                function showLoading(message = 'Processing...') {
                    $('#loadingText').text(message);
                    $('#loadingOverlay').fadeIn();
                }
                
                // Hide loading overlay
                function hideLoading() {
                    $('#loadingOverlay').fadeOut();
                }
                
                // Filter classes via AJAX
                function filterClasses() {
                    const deptId = $('#department_id').val();
                    const academicYear = $('#academic_year').val();
                    if (!academicYear) {
                        $('#class_id').html('<option value="">All Classes</option>');
                        return;
                    }
                    $.ajax({
                        url: '<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>',
                        type: 'POST',
                        dataType: 'json',
                        data: {
                            action: 'filter_classes',
                            department_id: deptId,
                            csrf_token: '<?php echo htmlspecialchars($csrf_token); ?>'
                        },
                        success: function(response) {
                            if (response.error) {
                                console.error('Class filter error:', response.error);
                                showAlert('Error filtering classes: ' + response.error, 'danger');
                                logClientError('Class filter AJAX error: ' + response.error);
                                return;
                            }
                            let options = '<option value="">All Classes</option>';
                            response.classes.forEach(cls => {
                                options += `<option value="${cls.id}">${cls.class_name}</option>`;
                            });
                            $('#class_id').html(options);
                            filterStudents();
                        },
                        error: function(xhr, status, error) {
                            console.error('Class filter AJAX error:', status, error);
                            showAlert('Failed to load classes. Please try again.', 'danger');
                            logClientError('Class filter AJAX failed: ' + status + ' - ' + error);
                        }
                    });
                }
                
                // Filter students via AJAX
                function filterStudents() {
                    const academicYear = $('#academic_year').val();
                    const deptId = $('#department_id').val();
                    const classId = $('#class_id').val();
                    const filterType = $('#filter_type').val();
                    
                    if (!academicYear) {
                        $('#students_table tbody').html(`
                            <tr>
                                <td colspan="9" class="no-data">
                                    <i class="fas fa-user-graduate"></i>
                                    <p>Please select an academic year to view students.</p>
                                </td>
                            </tr>
                        `);
                        updateStats(0, 0, 0, 0);
                        updateSelectedCount();
                        return;
                    }
                    
                    showLoading('Loading student data...');
                    
                    $.ajax({
                        url: '<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>',
                        type: 'POST',
                        dataType: 'json',
                        data: {
                            action: 'filter_students',
                            academic_year: academicYear,
                            department_id: deptId,
                            class_id: classId,
                            filter_type: filterType,
                            csrf_token: '<?php echo htmlspecialchars($csrf_token); ?>'
                        },
                        success: function(response) {
                            hideLoading();
                            
                            if (response.error) {
                                console.error('Students filter error:', response.error);
                                showAlert('Error filtering students: ' + response.error, 'danger');
                                logClientError('Students filter AJAX error: ' + response.error);
                                return;
                            }
                            
                            // Store student debts data
                            studentDebts = response.student_debts;
                            currentAcademicYear = academicYear;
                            
                            // Update form hidden fields
                            $('#form_academic_year').val(academicYear);
                            $('#form_department_id').val(deptId);
                            $('#form_class_id').val(classId);
                            
                            // Update students table
                            let studentsTbody = '';
                            if (response.student_debts.length === 0) {
                                studentsTbody = `
                                    <tr>
                                        <td colspan="9" class="no-data">
                                            <i class="fas fa-user-graduate"></i>
                                            <p>No students with outstanding fees found for the selected filters.</p>
                                        </td>
                                    </tr>`;
                            } else {
                                response.student_debts.forEach(student => {
                                    const status = student.payment_status || 'unpaid';
                                    const statusClass = status === 'unpaid' ? 'badge-unpaid' : 
                                                      (status === 'partial' ? 'badge-partial' : 'badge-overdue');
                                    const statusIcon = status === 'unpaid' ? 'fa-times-circle' : 
                                                     (status === 'partial' ? 'fa-clock' : 'fa-exclamation-circle');
                                    const daysOverdue = student.days_overdue || 0;
                                    const isOverdue = daysOverdue > 0;
                                    const latestDueDate = student.fees && student.fees.length > 0 ? 
                                        new Date(Math.max(...student.fees.map(f => new Date(f.due_date)))) : null;
                                    const dueDateDisplay = latestDueDate ? 
                                        latestDueDate.toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' }) : 
                                        'N/A';
                                    
                                    // Generate fee breakdown HTML
                                    let feeBreakdown = '';
                                    if (student.fees && student.fees.length > 0) {
                                        feeBreakdown = '<div class="fee-breakdown">';
                                        student.fees.forEach(fee => {
                                            feeBreakdown += `
                                                <div class="fee-item">
                                                    <span class="fee-type">${fee.fee_type}</span>
                                                    <span class="fee-amount">GH₵ ${parseFloat(fee.amount).toFixed(2)}</span>
                                                </div>`;
                                        });
                                        feeBreakdown += '</div>';
                                    } else {
                                        feeBreakdown = '<span class="text-muted">No fee details</span>';
                                    }
                                    
                                    // Generate parent details HTML
                                    let parentDetails = '';
                                    if (student.parent_name) {
                                        parentDetails = `
                                            <div class="student-details">
                                                <div class="detail-item">
                                                    <span class="detail-label">Parent:</span>
                                                    <span class="detail-value">${student.parent_name}</span>
                                                </div>
                                                ${student.parent_phone ? `
                                                <div class="detail-item">
                                                    <span class="detail-label">Phone:</span>
                                                    <span class="detail-value">${student.parent_phone}</span>
                                                </div>` : ''}
                                                ${student.parent_email ? `
                                                <div class="detail-item">
                                                    <span class="detail-label">Email:</span>
                                                    <span class="detail-value">${student.parent_email}</span>
                                                </div>` : ''}
                                            </div>`;
                                    } else {
                                        parentDetails = '<span class="text-danger">No parent assigned</span>';
                                    }
                                    
                                    const firstLetter = student.student_name ? student.student_name.charAt(0).toUpperCase() : 'S';
                                    
                                    studentsTbody += `
                                        <tr>
                                            <td>
                                                <div class="form-check">
                                                    <input class="form-check-input student-checkbox" type="checkbox" value="${student.student_id}">
                                                </div>
                                            </td>
                                            <td>
                                                <div class="d-flex align-items-center gap-2">
                                                    <div class="avatar-placeholder" style="width: 32px; height: 32px; background: linear-gradient(135deg, #ff6200 0%, #ff8c00 100%); border-radius: 50%; display: flex; align-items: center; justify-content: center; color: white; font-weight: bold; font-size: 0.75rem;">
                                                        ${firstLetter}
                                                    </div>
                                                    <div>
                                                        <strong style="font-size: 0.8rem;">${student.student_name}</strong>
                                                        <div class="text-muted" style="font-size: 0.65rem;">
                                                            ${student.fee_count || 0} fee${student.fee_count > 1 ? 's' : ''}
                                                        </div>
                                                    </div>
                                                </div>
                                            </td>
                                            <td>
                                                <span class="badge" style="background: #ffe8d6; color: #ff6200; font-weight: 600; font-size: 0.7rem;">
                                                    ${student.class_name}
                                                </span>
                                            </td>
                                            <td>${parentDetails}</td>
                                            <td class="fw-bold" style="color: #dc3545; font-size: 0.85rem;">
                                                GH₵ ${parseFloat(student.total_debt).toFixed(2)}
                                            </td>
                                            <td>${feeBreakdown}</td>
                                            <td>
                                                <span class="badge-status ${statusClass}" style="font-size: 0.65rem;">
                                                    <i class="fas ${statusIcon}"></i>
                                                    ${status.charAt(0).toUpperCase() + status.slice(1)}
                                                </span>
                                            </td>
                                            <td style="font-size: 0.7rem;">${dueDateDisplay}</td>
                                            <td>
                                                ${isOverdue ? 
                                                    `<span class="badge-status badge-unpaid" style="font-size: 0.65rem; padding: 0.2rem 0.5rem;">
                                                        <i class="fas fa-exclamation-triangle"></i>
                                                        ${daysOverdue} days
                                                    </span>` : 
                                                    `<span class="badge" style="background: #d4edda; color: #155724; font-size: 0.65rem; padding: 0.2rem 0.5rem;">
                                                        <i class="fas fa-check-circle"></i>
                                                        On time
                                                    </span>`}
                                            </td>
                                        </tr>`;
                                });
                            }
                            $('#students_table tbody').html(studentsTbody);
                            
                            // Clear selection since data changed
                            selectedStudents.clear();
                            totalSelectedDebt = 0;
                            updateSelectedCount();
                            
                            // Update stats
                            updateStats(
                                response.total_students || 0,
                                response.total_debt_amount || 0,
                                response.total_students > 0 ? (response.total_debt_amount / response.total_students) : 0,
                                response.student_debts.filter(s => (s.days_overdue || 0) > 0).length
                            );
                            
                            // Update SMS cost estimate
                            updateSmsCost();
                        },
                        error: function(xhr, status, error) {
                            hideLoading();
                            console.error('Students filter AJAX error:', status, error);
                            showAlert('Failed to load students. Please try again.', 'danger');
                            logClientError('Students filter AJAX failed: ' + status + ' - ' + error);
                        }
                    });
                }
                
                // Update statistics display
                function updateStats(studentsCount, totalDebt, avgDebt, overdueCount) {
                    $('#students_count').text(studentsCount);
                    $('#total_debt').text('₵' + parseFloat(totalDebt).toFixed(2));
                    $('#avg_debt').text('₵' + parseFloat(avgDebt).toFixed(2));
                    $('#overdue_count').text(overdueCount);
                }
                
                // Update selected count display
                function updateSelectedCount() {
                    const count = selectedStudents.size;
                    $('#selected_count_text').text(count + ' selected');
                    $('#selected_count').toggleClass('selected-count', count > 0);
                    $('#form_selected_count').text(count);
                    
                    // Update selected students input
                    $('#selected_students_input').val(JSON.stringify(Array.from(selectedStudents)));
                    
                    // Enable/disable buttons
                    $('#send_btn').prop('disabled', count === 0);
                    $('#view_details_btn').prop('disabled', count === 0);
                    
                    // Update total debt for selected students
                    $('#form_total_debt').text('GH₵ ' + parseFloat(totalSelectedDebt).toFixed(2));
                }
                
                // Update SMS cost estimate
                function updateSmsCost() {
                    const count = selectedStudents.size;
                    // Estimate 1 SMS unit per parent (assuming 160 chars per SMS)
                    $('#sms_cost').text(count + ' SMS units');
                    $('#modal_sms_cost').text(count + ' SMS units');
                }
                
                // Show alert
                function showAlert(message, type) {
                    const alertHtml = `
                        <div class="alert alert-${type} alert-custom alert-dismissible fade show" role="alert">
                            <i class="fas fa-${type === 'danger' ? 'exclamation-circle' : 'check-circle'} me-2"></i>
                            ${message}
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        </div>
                    `;
                    $('.page-header').after(alertHtml);
                    
                    // Auto remove after 5 seconds
                    setTimeout(() => {
                        $('.alert').alert('close');
                    }, 5000);
                }
                
                // Log client-side errors to server
                function logClientError(message) {
                    $.ajax({
                        url: '<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>',
                        type: 'POST',
                        data: {
                            action: 'log_error',
                            message: message,
                            csrf_token: '<?php echo htmlspecialchars($csrf_token); ?>'
                        }
                    });
                }
                
                // Get message template
                function getMessageTemplate(type, customMessage = '') {
                    const templates = {
                        'general': "Dear Parent, this is a reminder about outstanding fees for {student_name} in {class_name}. Total amount: GH₵{total_debt}. Please make payment at your earliest convenience. Thank you.",
                        'urgent': "URGENT: {student_name} has outstanding fees of GH₵{total_debt} in {class_name} that require immediate attention. Please visit the school office or make payment online to avoid consequences.",
                        'partial': "Reminder: {student_name} has partially paid fees in {class_name}. Remaining balance: GH₵{total_debt}. Please complete the payment. Thank you for your cooperation.",
                        'overdue': "OVERDUE NOTICE: {student_name}'s fees of GH₵{total_debt} in {class_name} are overdue by {days_overdue} days. Please make immediate payment to avoid additional charges.",
                        'upcoming': "Friendly reminder: Upcoming fee payment for {student_name} in {class_name} is due soon. Amount: GH₵{total_debt}. Please prepare for timely payment. Thank you.",
                        'custom': customMessage
                    };
                    return templates[type] || templates['general'];
                }
                
                // Generate personalized message preview
                function generateMessagePreview(studentId = null) {
                    const messageType = $('#message_type').val();
                    let message = getMessageTemplate(messageType, $('#custom_message').val());
                    
                    if (studentId) {
                        // Generate preview for specific student
                        const student = studentDebts.find(s => s.student_id == studentId);
                        if (student) {
                            message = personalizeMessage(message, student);
                        }
                    } else if (selectedStudents.size > 0) {
                        // Generate preview for first selected student
                        const firstStudentId = Array.from(selectedStudents)[0];
                        const firstStudent = studentDebts.find(s => s.student_id == firstStudentId);
                        if (firstStudent) {
                            message = personalizeMessage(message, firstStudent);
                        }
                    }
                    
                    return message;
                }
                
                // Personalize message for a student
                function personalizeMessage(message, student) {
                    let personalized = message;
                    const daysOverdue = student.days_overdue || 0;
                    const latestDueDate = student.fees && student.fees.length > 0 ? 
                        new Date(Math.max(...student.fees.map(f => new Date(f.due_date)))) : null;
                    const dueDateDisplay = latestDueDate ? 
                        latestDueDate.toLocaleDateString('en-GB', { day: 'numeric', month: 'long', year: 'numeric' }) : 'N/A';
                    
                    // Generate fee breakdown text
                    let feeBreakdown = '';
                    if (student.fees && student.fees.length > 0) {
                        student.fees.forEach(fee => {
                            feeBreakdown += `${fee.fee_type}: GH₵${parseFloat(fee.amount).toFixed(2)}\n`;
                        });
                    }
                    
                    const placeholders = {
                        '{student_name}': student.student_name || 'your child',
                        '{class_name}': student.class_name || '',
                        '{total_debt}': parseFloat(student.total_debt).toFixed(2),
                        '{days_overdue}': daysOverdue,
                        '{academic_year}': currentAcademicYear,
                        '{due_date}': dueDateDisplay,
                        '{parent_name}': student.parent_name || 'Parent',
                        '{fee_breakdown}': feeBreakdown
                    };
                    
                    Object.keys(placeholders).forEach(placeholder => {
                        personalized = personalized.replace(new RegExp(placeholder, 'g'), placeholders[placeholder]);
                    });
                    
                    return personalized;
                }
                
                // Update message preview
                function updateMessagePreview() {
                    const preview = generateMessagePreview();
                    $('#message_preview').text(preview);
                    $('#char_count').text(preview.length);
                    
                    // Calculate estimated number of messages (based on 160 char limit)
                    const estimatedMessages = Math.ceil(preview.length / 160);
                    $('#estimated_messages').text(estimatedMessages);
                    
                    // Show/hide preview based on length
                    if (preview.length > 0) {
                        $('#sms_preview').show();
                    } else {
                        $('#sms_preview').hide();
                    }
                }
                
                // Event Listeners
                
                // Filter button
                $('#filter_btn').click(function() {
                    filterStudents();
                });
                
                // Department change
                $('#department_id').change(filterClasses);
                
                // Academic year change
                $('#academic_year').change(function() {
                    filterClasses();
                    filterStudents();
                });
                
                // Class change
                $('#class_id').change(filterStudents);
                
                // Filter type change
                $('#filter_type').change(filterStudents);
                
                // Message template selection
                $('.template-card').click(function() {
                    $('.template-card').removeClass('selected');
                    $(this).addClass('selected');
                    
                    const type = $(this).data('type');
                    $('#message_type').val(type);
                    
                    // Show/hide custom message field
                    if (type === 'custom') {
                        $('#custom_message_container').show();
                        if (!$('#custom_message').val()) {
                            $('#custom_message').val(getMessageTemplate('general'));
                        }
                    } else {
                        $('#custom_message_container').hide();
                        $('#custom_message').val('');
                    }
                    
                    updateMessagePreview();
                });
                
                // Custom message input
                $('#custom_message').on('input', updateMessagePreview);
                
                // Select all checkbox
                $('#select_all_checkbox').change(function() {
                    const isChecked = $(this).prop('checked');
                    $('.student-checkbox').prop('checked', isChecked);
                    
                    if (isChecked) {
                        // Add all visible student IDs to selection
                        selectedStudents.clear();
                        totalSelectedDebt = 0;
                        $('.student-checkbox').each(function() {
                            const studentId = $(this).val();
                            selectedStudents.add(studentId);
                            // Find student and add their debt
                            const student = studentDebts.find(s => s.student_id == studentId);
                            if (student) {
                                totalSelectedDebt += parseFloat(student.total_debt);
                            }
                        });
                    } else {
                        // Clear all selections
                        selectedStudents.clear();
                        totalSelectedDebt = 0;
                    }
                    
                    updateSelectedCount();
                    updateSmsCost();
                });
                
                // Individual student checkbox
                $(document).on('change', '.student-checkbox', function() {
                    const studentId = $(this).val();
                    const student = studentDebts.find(s => s.student_id == studentId);
                    
                    if ($(this).prop('checked')) {
                        selectedStudents.add(studentId);
                        if (student) {
                            totalSelectedDebt += parseFloat(student.total_debt);
                        }
                    } else {
                        selectedStudents.delete(studentId);
                        if (student) {
                            totalSelectedDebt -= parseFloat(student.total_debt);
                        }
                        $('#select_all_checkbox').prop('checked', false);
                    }
                    
                    updateSelectedCount();
                    updateSmsCost();
                });
                
                // Select all button
                $('#select_all_btn').click(function() {
                    $('.student-checkbox').prop('checked', true);
                    $('#select_all_checkbox').prop('checked', true);
                    selectedStudents.clear();
                    totalSelectedDebt = 0;
                    $('.student-checkbox').each(function() {
                        const studentId = $(this).val();
                        selectedStudents.add(studentId);
                        const student = studentDebts.find(s => s.student_id == studentId);
                        if (student) {
                            totalSelectedDebt += parseFloat(student.total_debt);
                        }
                    });
                    updateSelectedCount();
                    updateSmsCost();
                });
                
                // Deselect all button
                $('#deselect_all_btn').click(function() {
                    $('.student-checkbox').prop('checked', false);
                    $('#select_all_checkbox').prop('checked', false);
                    selectedStudents.clear();
                    totalSelectedDebt = 0;
                    updateSelectedCount();
                    updateSmsCost();
                });
                
                // Quick preview button
                $('#quick_preview_btn').click(function() {
                    const count = selectedStudents.size;
                    if (count === 0) {
                        showAlert('Please select at least one student to preview messages.', 'warning');
                        return;
                    }
                    
                    const firstStudentId = Array.from(selectedStudents)[0];
                    const firstStudent = studentDebts.find(s => s.student_id == firstStudentId);
                    
                    if (firstStudent) {
                        const preview = personalizeMessage(
                            getMessageTemplate($('#message_type').val(), $('#custom_message').val()),
                            firstStudent
                        );
                        
                        $('#modal_message_preview').text(preview);
                        $('#modal_student_count').text(count);
                        $('#modal_academic_year').text(currentAcademicYear);
                        $('#modal_total_debt').text('GH₵ ' + parseFloat(totalSelectedDebt).toFixed(2));
                        
                        const modal = new bootstrap.Modal(document.getElementById('confirmModal'));
                        modal.show();
                    }
                });
                
                // View details button
                $('#view_details_btn').click(function() {
                    if (selectedStudents.size === 0) {
                        showAlert('Please select students to view details.', 'warning');
                        return;
                    }
                    
                    let detailsHtml = '<h6 style="font-size: 0.9rem;">Selected Students Details:</h6>';
                    let totalDebt = 0;
                    
                    Array.from(selectedStudents).forEach(studentId => {
                        const student = studentDebts.find(s => s.student_id == studentId);
                        if (student) {
                            totalDebt += parseFloat(student.total_debt);
                            detailsHtml += `
                                <div class="card mb-2">
                                    <div class="card-body" style="padding: 0.8rem;">
                                        <h6 class="card-title" style="font-size: 0.85rem;">${student.student_name} - ${student.class_name}</h6>
                                        <p class="mb-1" style="font-size: 0.75rem;"><strong>Parent:</strong> ${student.parent_name || 'Not assigned'}</p>
                                        <p class="mb-1" style="font-size: 0.75rem;"><strong>Phone:</strong> ${student.parent_phone || 'Not available'}</p>
                                        <p class="mb-1" style="font-size: 0.75rem;"><strong>Total Debt:</strong> GH₵ ${parseFloat(student.total_debt).toFixed(2)}</p>
                                        <p class="mb-0" style="font-size: 0.75rem;"><strong>Status:</strong> ${student.payment_status}</p>
                                    </div>
                                </div>
                            `;
                        }
                    });
                    
                    detailsHtml += `<div class="alert alert-info mt-3" style="padding: 0.6rem; font-size: 0.75rem;">
                        <strong>Total Selected Debt:</strong> GH₵ ${parseFloat(totalDebt).toFixed(2)}<br>
                        <strong>Number of Students:</strong> ${selectedStudents.size}
                    </div>`;
                    
                    // Create a modal to show details
                    const detailsModal = `
                        <div class="modal fade" id="detailsModal" tabindex="-1" aria-hidden="true">
                            <div class="modal-dialog modal-lg">
                                <div class="modal-content">
                                    <div class="modal-header" style="background: var(--gradient-primary); color: white; padding: 0.8rem 1rem;">
                                        <h5 class="modal-title" style="font-size: 1rem;">Selected Students Details</h5>
                                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                    </div>
                                    <div class="modal-body" style="padding: 1rem;">
                                        ${detailsHtml}
                                    </div>
                                    <div class="modal-footer" style="padding: 0.8rem;">
                                        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Close</button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    `;
                    
                    // Remove existing modal if any
                    $('#detailsModal').remove();
                    // Append new modal to body
                    $('body').append(detailsModal);
                    // Show modal
                    new bootstrap.Modal(document.getElementById('detailsModal')).show();
                });
                
                // Preview button
                $('#preview_btn').click(function() {
                    const count = selectedStudents.size;
                    if (count === 0) {
                        showAlert('Please select at least one student to preview the message.', 'warning');
                        return;
                    }
                    
                    $('#modal_student_count').text(count);
                    $('#modal_academic_year').text(currentAcademicYear);
                    $('#modal_total_debt').text('GH₵ ' + parseFloat(totalSelectedDebt).toFixed(2));
                    
                    // Generate preview using first selected student as example
                    const firstStudentId = Array.from(selectedStudents)[0];
                    const firstStudent = studentDebts.find(s => s.student_id == firstStudentId);
                    
                    let previewMessage = '';
                    if (firstStudent) {
                        previewMessage = personalizeMessage(
                            getMessageTemplate($('#message_type').val(), $('#custom_message').val()),
                            firstStudent
                        );
                    } else {
                        previewMessage = generateMessagePreview();
                    }
                    
                    $('#modal_message_preview').text(previewMessage);
                    
                    const modal = new bootstrap.Modal(document.getElementById('confirmModal'));
                    modal.show();
                });
                
                // Confirm send checkbox
                $('#confirm_send').change(function() {
                    $('#confirm_send_btn').prop('disabled', !$(this).prop('checked'));
                });
                
                // Confirm send button
                $('#confirm_send_btn').click(function() {
                    $('#send_btn .loading-spinner').show();
                    $('#send_btn').prop('disabled', true);
                    showLoading('Sending reminders to parents...');
                    
                    // Submit the form
                    $('#reminder_form').submit();
                });
                
                // Form submission
                $('#reminder_form').submit(function(e) {
                    if (selectedStudents.size === 0) {
                        e.preventDefault();
                        showAlert('Please select at least one student to send reminders to their parents.', 'warning');
                        return;
                    }
                    
                    const message = $('#custom_message').val() || getMessageTemplate($('#message_type').val());
                    if (!message.trim()) {
                        e.preventDefault();
                        showAlert('Please enter a message to send.', 'warning');
                        return;
                    }
                    
                    // Update selected students input with current selection
                    $('#selected_students_input').val(JSON.stringify(Array.from(selectedStudents)));
                    
                    // Show loading
                    showLoading('Preparing to send reminders...');
                });
                
                // Initialize with data
                <?php if ($selected_year): ?>
                    // Select general template by default
                    $('.template-card[data-type="general"]').addClass('selected');
                    updateMessagePreview();
                    
                    // Trigger initial filter
                    filterStudents();
                <?php endif; ?>
                
                // Calculate overdue count from initial data
                const initialOverdueCount = studentDebts.filter(student => (student.days_overdue || 0) > 0).length;
                $('#overdue_count').text(initialOverdueCount);
                
            } catch (e) {
                console.error('JavaScript initialization error:', e);
                logClientError('JavaScript initialization error: ' + e.message);
                hideLoading();
            }
        });
    </script>
</body>
</html>