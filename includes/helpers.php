<?php
// helpers.php
require_once($_SERVER['DOCUMENT_ROOT'] . '/config/db.php');

// Hellio Messaging Credentials
define('HELLIO_CLIENT_ID', '68afb830667be');
define('HELLIO_APPLICATION_SECRET', 'ec61992f5de6194a88e637edd0c42b7c');
define('HELLIO_SENDER_ID', 'QPMS');

// Fetch parent contacts
function getParentContacts($user_id, $conn) {
    $stmt = $conn->prepare("
        SELECT u.email, u.phone
        FROM users u
        INNER JOIN parent_student_mapping psm ON u.id = psm.parent_id
        WHERE psm.student_id = ? AND u.role = 'parent' AND u.status = 'active'
    ");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $contacts = [];
    while ($row = $result->fetch_assoc()) {
        $contacts[] = ['email' => $row['email'], 'phone' => $row['phone']];
    }
    $stmt->close();
    return $contacts;
}

// Fetch student name
function getStudentName($user_id, $conn) {
    $stmt = $conn->prepare("SELECT name FROM users WHERE id = ? AND role = 'student' AND status = 'active'");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $result['name'] ?? 'Unknown';
}

// Send Hellio Messaging reminders (SMS or WhatsApp)
function sendHellioReminder($channel, $recipient, $message, $conn, $admin_id) {
    $currentTime = date('YmdH');
    $hashedAuthKey = sha1(HELLIO_CLIENT_ID . HELLIO_APPLICATION_SECRET . $currentTime);
    $url = $channel === 'sms' ? 'https://api.helliomessaging.com/v2/sms' : 'https://api.helliomessaging.com/v3/whatsapp/send';
    $params = [
        'clientId' => HELLIO_CLIENT_ID,
        'authKey' => $hashedAuthKey,
        'senderId' => HELLIO_SENDER_ID,
        'msisdn' => $channel === 'sms' ? $recipient : null,
        'phoneNumber' => $channel === 'whatsapp' ? $recipient : null,
        'message' => $channel === 'sms' ? $message : null,
        'template' => $channel === 'whatsapp' ? [
            'name' => 'fee_reminder',
            'language' => 'en',
            'components' => [
                [
                    'type' => 'body',
                    'parameters' => [
                        ['type' => 'text', 'text' => $message]
                    ]
                ]
            ]
        ] : null
    ];
    $payload = json_encode(array_filter($params));
    $log_message = "Sending $channel to $recipient: $payload";
    error_log($log_message, 3, $_SERVER['DOCUMENT_ROOT'] . '/logs/debug.log');

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Content-Length: ' . strlen($payload)
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $log_message = "Sent $channel to $recipient: HTTP $httpCode, Response: $response";
    if ($httpCode >= 200 && $httpCode < 300) {
        error_log($log_message, 3, $_SERVER['DOCUMENT_ROOT'] . '/logs/activity.log');
        log_activity($admin_id, "send_$channel", $log_message, $conn);
        return true;
    } else {
        error_log($log_message, 3, $_SERVER['DOCUMENT_ROOT'] . '/logs/error.log');
        log_activity($admin_id, "send_{$channel}_error", $log_message, $conn);
        return false;
    }
}

// Send email via PHPMailer
function sendEmailReminder($email, $subject, $message, $conn, $admin_id) {
    require_once($_SERVER['DOCUMENT_ROOT'] . '/vendor/autoload.php');
    $mail = new PHPMailer\PHPMailer\PHPMailer(true);
    try {
        // Server settings
        $mail->isSMTP();
        $mail->Host = 'smtp.example.com'; // Replace with your SMTP host
        $mail->SMTPAuth = true;
        $mail->Username = 'your_smtp_username'; // Replace with your SMTP username
        $mail->Password = 'your_smtp_password'; // Replace with your SMTP password
        $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = 587; // Replace with your SMTP port

        // Recipients
        $mail->setFrom('no-reply@qpms.com', 'QPMS');
        $mail->addAddress($email);

        // Content
        $mail->isHTML(false);
        $mail->Subject = $subject;
        $mail->Body = $message;

        $mail->send();
        $log_message = "Email sent to $email: $subject";
        error_log($log_message, 3, $_SERVER['DOCUMENT_ROOT'] . '/logs/activity.log');
        log_activity($admin_id, 'send_email', $log_message, $conn);
        return true;
    } catch (Exception $e) {
        $log_message = "Failed to send email to $email: {$mail->ErrorInfo}";
        error_log($log_message, 3, $_SERVER['DOCUMENT_ROOT'] . '/logs/error.log');
        log_activity($admin_id, 'send_email_error', $log_message, $conn);
        return false;
    }
}

// Fetch overdue debts grouped by student
function getOverdueDebts($conn, $academic_year = '', $dept_id = 0, $class_id = 0, $search_query = '') {
    $query = "
        SELECT 
            u.id AS student_id, 
            u.name AS student_name, 
            GROUP_CONCAT(f.fee_type ORDER BY f.fee_type) AS fee_types, 
            GROUP_CONCAT(f.target_type ORDER BY f.fee_type) AS target_types, 
            GROUP_CONCAT(c.class_name ORDER BY f.fee_type) AS class_names, 
            GROUP_CONCAT(f.amount ORDER BY f.fee_type) AS amounts, 
            GROUP_CONCAT(f.due_date ORDER BY f.fee_type) AS due_dates, 
            SUM(d.amount_due) AS total_balance, 
            f.academic_year, 
            f.department, 
            GROUP_CONCAT(d.id ORDER BY f.fee_type) AS debt_ids
        FROM users u
        JOIN debts d ON u.id = d.user_id
        JOIN fees f ON d.fee_id = f.id
        LEFT JOIN classes c ON f.class_id = c.id
        LEFT JOIN student_classes sc ON f.class_id = sc.class_id AND sc.user_id = d.user_id AND sc.academic_year = f.academic_year
        WHERE f.academic_year = ?
        AND u.role = 'student' 
        AND u.status = 'active' 
        AND f.due_date < CURDATE()
    ";
    $params = [$academic_year];
    $types = "s";
    if ($dept_id > 0) {
        $query .= " AND f.department = (SELECT name FROM departments WHERE id = ?)";
        $types .= "i";
        $params[] = $dept_id;
    }
    if ($class_id > 0) {
        $query .= " AND f.class_id = ?";
        $types .= "i";
        $params[] = $class_id;
    }
    if ($search_query) {
        $query .= " AND (u.name LIKE ? OR f.fee_type LIKE ?)";
        $types .= "ss";
        $search_param = "%$search_query%";
        $params[] = $search_param;
        $params[] = $search_param;
    }
    $query .= " GROUP BY u.id, f.academic_year, f.department";
    error_log("Executing overdue debts query: $query with params: " . json_encode($params), 3, $_SERVER['DOCUMENT_ROOT'] . '/logs/debug.log');

    $stmt = $conn->prepare($query);
    if (!$stmt) {
        error_log("Error preparing overdue debts query: " . $conn->error, 3, $_SERVER['DOCUMENT_ROOT'] . '/logs/error.log');
        return [];
    }
    if ($params) {
        $stmt->bind_param($types, ...$params);
    }
    if (!$stmt->execute()) {
        error_log("Query execution failed: " . $stmt->error, 3, $_SERVER['DOCUMENT_ROOT'] . '/logs/error.log');
        $stmt->close();
        return [];
    }
    $result = $stmt->get_result();
    $debts = [];
    while ($row = $result->fetch_assoc()) {
        $debts[] = [
            'student_id' => $row['student_id'],
            'student_name' => $row['student_name'],
            'fee_types' => explode(',', $row['fee_types']),
            'target_types' => explode(',', $row['target_types']),
            'class_names' => explode(',', $row['class_names']),
            'amounts' => explode(',', $row['amounts']),
            'due_dates' => explode(',', $row['due_dates']),
            'total_balance' => $row['total_balance'],
            'academic_year' => $row['academic_year'],
            'department' => $row['department'],
            'debt_ids' => explode(',', $row['debt_ids'])
        ];
    }
    $stmt->close();
    error_log("Fetched " . count($debts) . " overdue debts", 3, $_SERVER['DOCUMENT_ROOT'] . '/logs/debug.log');
    return $debts;
}

// Fetch monthly fee chart data
function getFeeChartData($conn, $academic_year = '') {
    $query = "
        SELECT 
            MONTH(f.due_date) AS month,
            COALESCE(SUM(p.amount_paid), 0) AS paid,
            COALESCE(SUM(d.amount_due), 0) AS unpaid
        FROM fees f
        LEFT JOIN payments p ON f.id = p.fee_id
        LEFT JOIN debts d ON f.id = d.fee_id
        WHERE f.academic_year = ?
        GROUP BY MONTH(f.due_date)
        ORDER BY MONTH(f.due_date)
    ";
    $stmt = $conn->prepare($query);
    if (!$stmt) {
        error_log("Chart data query prepare failed: " . $conn->error, 3, $_SERVER['DOCUMENT_ROOT'] . '/logs/error.log');
        return ['labels' => [], 'paid' => [], 'unpaid' => []];
    }
    $stmt->bind_param("s", $academic_year);
    $stmt->execute();
    $result = $stmt->get_result();
    $labels = [];
    $paid = [];
    $unpaid = [];
    for ($i = 1; $i <= 12; $i++) {
        $labels[] = date('F', mktime(0, 0, 0, $i, 1));
        $paid[] = 0;
        $unpaid[] = 0;
    }
    while ($row = $result->fetch_assoc()) {
        $month = (int)$row['month'] - 1;
        $paid[$month] = (float)$row['paid'];
        $unpaid[$month] = (float)$row['unpaid'];
    }
    $stmt->close();
    error_log("Fetched chart data for academic_year=$academic_year", 3, $_SERVER['DOCUMENT_ROOT'] . '/logs/debug.log');
    return ['labels' => $labels, 'paid' => $paid, 'unpaid' => $unpaid];
}

// Hook for new fee reminder
function sendNewFeeReminder($user_id, $fee_amount, $due_date, $description, $conn, $admin_id) {
    $contacts = getParentContacts($user_id, $conn);
    $student_name = getStudentName($user_id, $conn);
    $message = "Dear Parent, new fee of GHS $fee_amount for $student_name ($description) due on $due_date. Please pay via QPMS portal. Regards, QPMS.";
    $success = false;
    foreach ($contacts as $contact) {
        if ($contact['phone']) {
            if (sendHellioReminder('sms', $contact['phone'], $message, $conn, $admin_id)) {
                $success = true;
            }
            if (sendHellioReminder('whatsapp', $contact['phone'], $message, $conn, $admin_id)) {
                $success = true;
            }
        }
        if ($contact['email']) {
            if (sendEmailReminder($contact['email'], 'New Fee Notification', $message, $conn, $admin_id)) {
                $success = true;
            }
        }
    }
    return $success;
}

// Log activity with user_id validation
function log_activity($user_id, $action, $details, $conn = null) {
    $log_file = $_SERVER['DOCUMENT_ROOT'] . '/logs/activity.log';
    $timestamp = date('Y-m-d H:i:s');
    error_log("[$timestamp] [user_id: " . ($user_id ?? 'NULL') . "] $action: $details\n", 3, $log_file);

    if ($conn && $user_id) {
        $stmt = $conn->prepare("SELECT id FROM users WHERE id = ? AND status = 'active'");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result->num_rows > 0) {
            $stmt = $conn->prepare("INSERT INTO activity_logs (user_id, action, description, created_at) VALUES (?, ?, ?, NOW())");
            $stmt->bind_param("iss", $user_id, $action, $details);
            if (!$stmt->execute()) {
                error_log("[$timestamp] [user_id: $user_id] log_activity_error: Failed to insert log: " . $stmt->error, 3, $log_file);
            }
            $stmt->close();
        } else {
            error_log("[$timestamp] [user_id: $user_id] log_activity_error: Invalid user_id", 3, $log_file);
        }
    }
}
?>