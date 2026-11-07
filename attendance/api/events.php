<?php
// /attendance/api/events.php
// Server-Sent Events (SSE) feed for real-time admin dashboard

require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/helpers.php';

// Only admins can view the events feed
requireRoleAPI(['admin']);

header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');
header('X-Accel-Buffering: no'); // Disable nginx buffering

// Disable PHP time limit
set_time_limit(0);

// Get last event ID from query parameter
$lastId = isset($_GET['last_id']) ? (int)$_GET['last_id'] : 0;
$lastCheck = time();

// Send initial connection event
echo "event: connected\n";
echo "data: " . json_encode(['message' => 'Connected to SSE stream', 'last_id' => $lastId]) . "\n\n";
ob_flush();
flush();

// Keep-alive loop
while (true) {
    // Check for new logs every 2 seconds
    if (time() - $lastCheck >= 2) {
        $lastCheck = time();
        
        try {
            // Get new logs since last_id
            $query = "
                SELECT 
                    al.*,
                    u.name as user_name,
                    u.profile_picture as user_photo,
                    u.role as user_role,
                    ou.name as overrider_name,
                    sc.class_name
                FROM access_logs al
                LEFT JOIN users u ON al.user_id = u.id
                LEFT JOIN users ou ON al.overridden_by = ou.id
                LEFT JOIN student_classes st ON al.user_id = st.user_id
                LEFT JOIN classes sc ON st.class_id = sc.id
                WHERE al.id > ?
                ORDER BY al.id ASC
                LIMIT 20
            ";
            
            $stmt = $conn->prepare($query);
            $stmt->bind_param("i", $lastId);
            $stmt->execute();
            $result = $stmt->get_result();
            
            $newLogs = [];
            while ($row = $result->fetch_assoc()) {
                $newLogs[] = $row;
                if ($row['id'] > $lastId) {
                    $lastId = $row['id'];
                }
            }
            
            if (!empty($newLogs)) {
                // Send new logs as an event
                echo "event: new_logs\n";
                echo "data: " . json_encode([
                    'logs' => $newLogs,
                    'count' => count($newLogs),
                    'last_id' => $lastId,
                    'timestamp' => date('Y-m-d H:i:s')
                ]) . "\n\n";
                ob_flush();
                flush();
            }
            
            // Send keep-alive ping every 30 seconds
            if (time() % 30 === 0) {
                echo "event: ping\n";
                echo "data: " . json_encode(['timestamp' => date('Y-m-d H:i:s')]) . "\n\n";
                ob_flush();
                flush();
            }
            
        } catch (Exception $e) {
            // Silently handle errors to keep the connection alive
            error_log("SSE Error: " . $e->getMessage());
        }
    }
    
    // Sleep to prevent CPU overload
    usleep(500000); // 0.5 seconds
}
?>