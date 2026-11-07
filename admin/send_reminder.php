```php
<?php
session_start();
define('DEBUG_MODE', true); // Added to fix undefined constant error
require_once($_SERVER['DOCUMENT_ROOT'] . '/config/db.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/helpers.php');

if (!isset($conn) || $conn->connect_error) {
    error_log("Database connection failed: " . ($conn->connect_error ?? 'Connection is null'), 3, $_SERVER['DOCUMENT_ROOT'] . '/logs/error.log');
    http_response_code(500);
    ob_clean();
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Database connection failed']);
    exit;
}

if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    $user_id = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : 0;
    log_activity($user_id, 'access_denied', 'Attempted to access send_reminder.php without admin role', $conn);
    $_SESSION['error_msg'] = "Please log in as an admin to access this page.";
    header('Location: /login.php');
    exit;
}

$ip_address = $_SERVER['REMOTE_ADDR'];
$user_agent = $_SERVER['HTTP_USER_AGENT'];
log_activity($_SESSION['user_id'], 'page_access', "Admin accessed send_reminder.php, IP: $ip_address, User Agent: $user_agent", $conn);

if (!isset($_GET['csrf_token'])) {
    error_log("Missing CSRF token for send_reminder.php", 3, $_SERVER['DOCUMENT_ROOT'] . '/logs/error.log');
    $_SESSION['error_msg'] = "Invalid request. Please try again.";
    header('Location: /admin/bulk_reminder.php');
    exit;
}
$stmt = $conn->prepare("SELECT id FROM csrf_tokens WHERE user_id = ? AND token = ? AND expires_at > NOW()");
if (!$stmt) {
    error_log("Error preparing CSRF validation query: " . $conn->error, 3, $_SERVER['DOCUMENT_ROOT'] . '/logs/error.log');
    $_SESSION['error_msg'] = "System error. Please try again.";
    header('Location: /admin/bulk_reminder.php');
    exit;
}
$stmt->bind_param("is", $_SESSION['user_id'], $_GET['csrf_token']);
$stmt->execute();
$csrf_valid = $stmt->get_result()->num_rows > 0;
$stmt->close();

if (!$csrf_valid) {
    error_log("Invalid CSRF token for send_reminder.php, user_id: {$_SESSION['user_id']}, token: {$_GET['csrf_token']}", 3, $_SERVER['DOCUMENT_ROOT'] . '/logs/error.log');
    $_SESSION['error_msg'] = "Invalid or expired session. Please try again.";
    header('Location: /admin/bulk_reminder.php');
    exit;
}

$debt_ids_str = trim($_GET['debt_ids'] ?? '');
$methods_str = trim($_GET['methods'] ?? '');
$academic_year = trim($_GET['academic_year'] ?? '');
$dept_id = (int)($_GET['department_id'] ?? 0);
$class_id = (int)($_GET['class_id'] ?? 0);
$search_query = trim($_GET['search_query'] ?? '');

if (empty($debt_ids_str) || empty($methods_str) || empty($academic_year)) {
    $_SESSION['error_msg'] = "Missing required parameters.";
    header('Location: /admin/bulk_reminder.php');
    exit;
}

$student_ids = array_map('intval', explode(',', $debt_ids_str));
$methods = array_filter(explode(',', $methods_str), function($method) { // Added validation for methods
    return in_array($method, ['email', 'whatsapp']);
});

$overdue_debts = fetchOverdueDebts($conn, $academic_year, $dept_id, $class_id, $search_query, DEBUG_MODE);

if (empty($overdue_debts)) { // Added check for empty debts
    $_SESSION['error_msg'] = "No debts found for the selected students.";
    header('Location: /admin/bulk_reminder.php');
    exit;
}

$selected_debts = array_filter($overdue_debts, function($debt) use ($student_ids) {
    return in_array($debt['student_id'], $student_ids);
});

if (empty($selected_debts)) { // Added check for empty selected debts
    $_SESSION['error_msg'] = "No debts found for the selected students.";
    header('Location: /admin/bulk_reminder.php');
    exit;
}

$student_debts = [];
foreach ($selected_debts as $debt) {
    $student_id = $debt['student_id'];
    if (!isset($student_debts[$student_id])) {
        $student_debts[$student_id] = [
            'student_name' => $debt['student_name'],
            'fee_details' => [],
            'total_balance' => 0,
            'due_date' => $debt['due_date'],
            'is_overdue' => $debt['due_date'] < date('Y-m-d') // Added for generateReminderMessage
        ];
    }
    $student_debts[$student_id]['fee_details'][] = "- {$debt['fee_type']} ({$debt['target_type']}): GHS " . number_format($debt['amount'], 2) . ", Due: {$debt['due_date']}";
    $student_debts[$student_id]['total_balance'] += $debt['total_balance'];
}

$success_msg = '';
$error_msg = '';
$sent_count = 0;
$errors = [];
$messages = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'confirm_send' && isset($_POST['csrf_token'])) {
    $stmt = $conn->prepare("SELECT id FROM csrf_tokens WHERE user_id = ? AND token = ? AND expires_at > NOW()");
    $stmt->bind_param("is", $_SESSION['user_id'], $_POST['csrf_token']);
    $stmt->execute();
    $csrf_valid_post = $stmt->get_result()->num_rows > 0;
    $stmt->close();

    if (!$csrf_valid_post) {
        $error_msg = "Invalid or expired session. Please try again.";
        log_activity($_SESSION['user_id'], 'csrf_error', "Invalid CSRF token for confirm_send", $conn);
    } else {
        foreach ($student_debts as $student_id => $data) {
            $contacts = getParentContacts($student_id, $conn);
            if (empty($contacts)) {
                $errors[] = "No parent contacts found for student ID $student_id ({$data['student_name']})";
                continue;
            }

            $message = generateReminderMessage($data['student_name'], $data['total_balance'], $data['fee_details'], $data['is_overdue']);

            $sent = false;
            foreach ($contacts as $contact) {
                if (in_array('email', $methods) && $contact['email']) {
                    if (sendEmailReminder($contact['email'], 'Fee Reminder - QPMS', $message, $conn, $_SESSION['user_id'])) {
                        $sent = true;
                    } else {
                        $errors[] = "Failed to send email to {$contact['email']} for student {$data['student_name']}";
                    }
                }
                if (in_array('whatsapp', $methods) && $contact['phone']) {
                    error_log("WhatsApp reminder prepared for {$contact['phone']}: $message", 3, $_SERVER['DOCUMENT_ROOT'] . '/logs/whatsapp.log');
                    log_activity($_SESSION['user_id'], 'whatsapp_sent', "WhatsApp reminder prepared for {$contact['phone']}: " . substr($message, 0, 100) . "...", $conn);
                    $sent = true;
                }
            }
            if ($sent) {
                $sent_count++;
                $messages[$student_id] = $message;
            }
        }
        if ($sent_count > 0) {
            $success_msg = "Reminders processed for $sent_count students via " . implode(', ', $methods) . ".";
            log_activity($_SESSION['user_id'], 'send_reminders', "Processed reminders for $sent_count students via " . implode(', ', $methods) . ", errors: " . count($errors), $conn);
        }
        if (!empty($errors)) {
            $error_msg = "Errors occurred: " . implode('; ', $errors);
        }
    }
    // Added CSRF token regeneration
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    $csrf_expiry = date('Y-m-d H:i:s', strtotime('+1 hour'));
    $stmt = $conn->prepare("INSERT INTO csrf_tokens (user_id, token, created_at, expires_at) VALUES (?, ?, NOW(), ?)");
    if ($stmt) {
        $stmt->bind_param("iss", $_SESSION['user_id'], $_SESSION['csrf_token'], $csrf_expiry);
        $stmt->execute();
        $stmt->close();
    }
}

function generateReminderMessage($student_name, $total_balance, $fee_details, $is_overdue) { // Restored $is_overdue
    $intro = "Dear Parent/Guardian,";
    $status = $is_overdue ? "overdue" : "recently added";
    $body = "\n\nThis is a reminder regarding the school fees for your child, $student_name.";
    $body .= "\nThe total $status amount is GHS " . number_format($total_balance, 2) . ".";
    $body .= "\n\nFee Details:\n" . implode("\n", $fee_details);
    $closing = "\n\nPlease make the payment by the due date to avoid disruptions. Payments can be made via the QPMS portal or by contacting the school administration.";
    $closing .= "\n\nFor inquiries, reach out to Queen's Paradise Montessori School at [contact info].";
    $closing .= "\n\nThank you,\nQueen's Paradise Montessori School (QPMS)";
    return $intro . $body . $closing;
}
?>

<!DOCTYPE html>
<html lang="en" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Send Fee Reminders - QPMS</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css" rel="stylesheet">
    <style>
        .message-box { white-space: pre-wrap; font-family: monospace; }
        .whatsapp-link { margin-top: 10px; }
        .btn-primary { background-color: var(--primary-color, #007bff); border-color: var(--primary-color, #007bff); }
        .btn-success { background-color: var(--success-color, #28a745); border-color: var(--success-color, #28a745); }
        .btn-info { background-color: var(--info-color, #17a2b8); border-color: var(--info-color, #17a2b8); }
    </style>
</head>
<body>
    <?php 
    $header_path = $_SERVER['DOCUMENT_ROOT'] . '/templates/admin_header.php';
    if (!file_exists($header_path)) { // Improved header check
        error_log("Admin header file missing: $header_path", 3, $_SERVER['DOCUMENT_ROOT'] . '/logs/error.log');
        $_SESSION['error_msg'] = "Error: Admin header template is missing.";
        header("Location: /login.php");
        exit;
    }
    include $header_path; 
    ?>
    
    <div class="container mt-4">
        <h1 class="mb-4"><i class="fas fa-bell me-2"></i>Send Fee Reminders</h1>
        
        <?php if ($success_msg): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <?php echo htmlspecialchars($success_msg); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>
        <?php if ($error_msg): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <?php echo htmlspecialchars($error_msg); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <div class="card">
            <div class="card-body">
                <p><strong>Selected students:</strong> <?php echo count($student_debts); ?></p>
                <p><strong>Delivery methods:</strong> <?php echo htmlspecialchars(implode(', ', $methods)); ?></p>
                <p><strong>Academic Year:</strong> <?php echo htmlspecialchars($academic_year); ?></p>
                
                <form method="POST">
                    <input type="hidden" name="action" value="confirm_send">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                    <button type="submit" class="btn btn-primary mb-3">
                        <i class="fas fa-paper-plane me-1"></i>Confirm and Send Reminders
                    </button>
                </form>

                <?php foreach ($student_debts as $student_id => $data): ?>
                    <?php 
                    $contacts = getParentContacts($student_id, $conn);
                    $message = generateReminderMessage($data['student_name'], $data['total_balance'], $data['fee_details'], $data['is_overdue']);
                    ?>
                    <div class="mb-4 border p-3">
                        <h5>Reminder for <?php echo htmlspecialchars($data['student_name']); ?> (ID: <?php echo $student_id; ?>)</h5>
                        <div class="message-box bg-light p-3 mb-3">
                            <?php echo nl2br(htmlspecialchars($message)); ?>
                        </div>
                        <?php if (empty($contacts)): ?>
                            <div class="alert alert-warning">
                                No parent contacts found for <?php echo htmlspecialchars($data['student_name']); ?>. Please update parent information in the system.
                            </div>
                        <?php else: ?>
                            <?php foreach ($contacts as $contact): ?>
                                <div class="mb-2">
                                    <strong>Parent Contact:</strong> 
                                    Email: <?php echo htmlspecialchars($contact['email'] ?? 'N/A'); ?>, 
                                    Phone: <?php echo htmlspecialchars($contact['phone'] ?? 'N/A'); ?>
                                </div>
                                <?php if (in_array('email', $methods) && $contact['email']): ?>
                                    <a href="mailto:<?php echo htmlspecialchars($contact['email']); ?>?subject=Fee Reminder - QPMS&body=<?php echo urlencode($message); ?>" class="btn btn-info btn-sm" target="_blank">
                                        <i class="fas fa-envelope me-1"></i>Send Email
                                    </a>
                                <?php endif; ?>
                                <?php if (in_array('whatsapp', $methods) && $contact['phone']): ?>
                                    <a href="https://wa.me/<?php echo preg_replace('/\D/', '', $contact['phone']); ?>?text=<?php echo urlencode($message); ?>" class="btn btn-success btn-sm whatsapp-link" target="_blank">
                                        <i class="fab fa-whatsapp me-1"></i>Send via WhatsApp
                                    </a>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
                
                <a href="/admin/bulk_reminder.php" class="btn btn-secondary">
                    <i class="fas fa-arrow-left me-1"></i>Back to Bulk Reminders
                </a>
            </div>
        </div>
    </div>

    <?php 
    $footer_path = $_SERVER['DOCUMENT_ROOT'] . '/templates/admin_footer.php';
    if (!file_exists($footer_path)) { // Improved footer check
        error_log("Admin footer file missing: $footer_path", 3, $_SERVER['DOCUMENT_ROOT'] . '/logs/error.log');
        $_SESSION['error_msg'] = "Error: Admin footer template is missing.";
        header("Location: /login.php");
        exit;
    }
    include $footer_path; 
    $conn->close();
    ?>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>
</body>
</html>
```