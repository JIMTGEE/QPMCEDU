<?php
session_start();

// Redirect if not admin
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    header("Location: ../login.php");
    exit();
}

// Include database connection and header
require_once($_SERVER['DOCUMENT_ROOT'] . '/config/db.php');
include $_SERVER['DOCUMENT_ROOT'] . '/templates/admin_header.php';
require_once($_SERVER['DOCUMENT_ROOT'] . '/functions/log_activity.php');

// Fetch logs
$sql = "SELECT logs.*, users.name, users.email 
        FROM user_logs AS logs 
        LEFT JOIN users ON logs.user_id = users.id 
        ORDER BY logs.created_at DESC"; // Use the correct column name
$result = $conn->query($sql);
?>

<div class="container mt-5">
    <h1 class="mb-4 text-center">📜 User Activity Logs</h1>
    <p class="text-center">Track login attempts, actions, IP addresses, and more.</p>

    <table class="table table-bordered table-hover">
        <thead class="thead-dark">
            <tr>
                <th>#</th>
                <th>User</th>
                <th>Email</th>
                <th>Action</th>
                <th>IP Address</th>
                <th>Timestamp</th>
            </tr>
        </thead>
        <tbody>
            <?php if ($result && $result->num_rows > 0): ?>
                <?php $count = 1; while ($log = $result->fetch_assoc()): ?>
                    <tr>
                        <td><?php echo $count++; ?></td>
                        <td><?php echo htmlspecialchars($log['name'] ?? 'Unknown'); ?></td>
                        <td><?php echo htmlspecialchars($log['email'] ?? 'N/A'); ?></td>
                        <td><?php echo htmlspecialchars($log['action']); ?></td>
                        <td><?php echo htmlspecialchars($log['ip_address']); ?></td>
                        <td><?php echo date('Y-m-d H:i:s', strtotime($log['created_at'])); ?></td>
                    </tr>
                <?php endwhile; ?>
            <?php else: ?>
                <tr>
                    <td colspan="6" class="text-center">No logs found.</td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<?php
include $_SERVER['DOCUMENT_ROOT'] . '/templates/admin_footer.php';
?>
