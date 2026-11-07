<?php
// /attendance/admin_dashboard.php
// Admin Dashboard - Reads directly from database

// Start session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Include database
require_once __DIR__ . '/../config/db.php';

// Set default user for testing
$_SESSION['user_id'] = 1;
$_SESSION['role'] = 'admin';
$_SESSION['name'] = 'Admin User';

// Get stats
$today = date('Y-m-d');
$stats = [];

// Total scans today
$query = "SELECT COUNT(*) as total FROM access_logs WHERE DATE(scanned_at) = '$today'";
$result = $conn->query($query);
$stats['total'] = $result->fetch_assoc()['total'] ?? 0;

// Allowed
$query = "SELECT COUNT(*) as total FROM access_logs WHERE DATE(scanned_at) = '$today' AND decision = 'allowed'";
$result = $conn->query($query);
$stats['allowed'] = $result->fetch_assoc()['total'] ?? 0;

// Denied
$query = "SELECT COUNT(*) as total FROM access_logs WHERE DATE(scanned_at) = '$today' AND decision = 'denied'";
$result = $conn->query($query);
$stats['denied'] = $result->fetch_assoc()['total'] ?? 0;

// Overridden
$query = "SELECT COUNT(*) as total FROM access_logs WHERE DATE(scanned_at) = '$today' AND decision = 'overridden'";
$result = $conn->query($query);
$stats['overridden'] = $result->fetch_assoc()['total'] ?? 0;

// Get recent logs
$query = "SELECT al.*, u.name as user_name, u.profile_picture as user_photo, ou.name as overrider_name 
          FROM access_logs al 
          LEFT JOIN users u ON al.user_id = u.id 
          LEFT JOIN users ou ON al.overridden_by = ou.id 
          ORDER BY al.id DESC LIMIT 100";
$logs = $conn->query($query)->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - Gate Access</title>
    <link rel="icon" href="/favicon.ico" type="image/x-icon">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: #f0f2f5; min-height: 100vh; }
        .header { background: linear-gradient(135deg, #1a237e, #0d47a1); color: white; padding: 15px 20px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.3); }
        .header-left { display: flex; align-items: center; gap: 15px; }
        .header-left img { height: 40px; border-radius: 50%; }
        .header-left h1 { font-size: 20px; font-weight: 600; }
        .header-left .subtitle { font-size: 12px; opacity: 0.8; }
        .header-right { display: flex; align-items: center; gap: 15px; }
        .btn-back { background: rgba(255,255,255,0.2); color: white; border: 1px solid rgba(255,255,255,0.3); padding: 6px 15px; border-radius: 20px; cursor: pointer; font-size: 13px; text-decoration: none; transition: all 0.3s; }
        .btn-back:hover { background: rgba(255,255,255,0.3); }
        .stats-bar { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 15px; padding: 20px; max-width: 1400px; margin: 0 auto; }
        .stat-card { background: white; border-radius: 10px; padding: 15px 20px; box-shadow: 0 2px 8px rgba(0,0,0,0.08); text-align: center; }
        .stat-card .number { font-size: 28px; font-weight: 700; color: #1a237e; }
        .stat-card .number.denied { color: #c62828; }
        .stat-card .number.overridden { color: #e65100; }
        .stat-card .number.allowed { color: #2e7d32; }
        .stat-card .label { font-size: 13px; color: #666; margin-top: 4px; }
        .main-container { max-width: 1400px; margin: 0 auto; padding: 0 20px 20px; }
        .table-container { background: white; border-radius: 10px; overflow: hidden; box-shadow: 0 2px 8px rgba(0,0,0,0.08); }
        .table-container .table-header { padding: 12px 20px; background: #f8f9fa; border-bottom: 2px solid #e0e0e0; display: flex; justify-content: space-between; align-items: center; font-weight: 600; color: #333; }
        .table-scroll { max-height: 600px; overflow-y: auto; }
        table { width: 100%; border-collapse: collapse; font-size: 14px; }
        thead { position: sticky; top: 0; background: #f8f9fa; z-index: 10; }
        th { padding: 10px 15px; text-align: left; border-bottom: 2px solid #e0e0e0; font-weight: 600; color: #555; background: #f8f9fa; }
        td { padding: 10px 15px; border-bottom: 1px solid #eee; vertical-align: middle; }
        tr:hover { background: #f8f9fa; }
        tr.denied-row { background: #ffebee; animation: flash-red 2s infinite; }
        tr.denied-row:hover { background: #ffcdd2; }
        @keyframes flash-red { 0%, 100% { background: #ffebee; } 50% { background: #ffcdd2; } }
        tr.overridden-row { background: #fff3e0; }
        .badge-status { padding: 4px 12px; border-radius: 12px; font-size: 11px; font-weight: 600; display: inline-block; }
        .badge-status.allowed { background: #e8f5e9; color: #2e7d32; }
        .badge-status.denied { background: #ffebee; color: #c62828; }
        .badge-status.overridden { background: #fff3e0; color: #e65100; }
        .badge-status.exit { background: #e3f2fd; color: #0d47a1; }
        .student-cell { display: flex; align-items: center; gap: 10px; }
        .student-cell img { width: 30px; height: 30px; border-radius: 50%; object-fit: cover; }
        .empty-state { text-align: center; padding: 60px 20px; color: #999; }
        .empty-state .icon { font-size: 48px; margin-bottom: 10px; }
        .refresh-btn { background: #e67e22; color: white; border: none; padding: 6px 15px; border-radius: 20px; cursor: pointer; font-size: 13px; transition: all 0.3s; }
        .refresh-btn:hover { background: #d35400; }
        @media (max-width: 768px) { .stats-bar { grid-template-columns: repeat(2, 1fr); } table { font-size: 12px; } th, td { padding: 6px 8px; } .student-cell img { width: 24px; height: 24px; } .header-left h1 { font-size: 16px; } }
        @media (max-width: 480px) { .stats-bar { grid-template-columns: 1fr 1fr; gap: 8px; padding: 10px; } .stat-card { padding: 10px; } .stat-card .number { font-size: 20px; } }
        .auto-refresh { font-size: 12px; color: #999; display: flex; align-items: center; gap: 10px; }
        .auto-refresh .dot { width: 8px; height: 8px; border-radius: 50%; display: inline-block; }
        .auto-refresh .dot.active { background: #4caf50; animation: pulse 1.5s infinite; }
        @keyframes pulse { 0%, 100% { opacity: 1; } 50% { opacity: 0.3; } }
    </style>
</head>
<body>

    <header class="header">
        <div class="header-left">
            <img src="/assets/images/seal.png" alt="Logo" onerror="this.src='/assets/default_avatar.png'">
            <div>
                <h1>📊 Gate Access Dashboard</h1>
                <div class="subtitle">Real-time monitoring • Admin Control</div>
            </div>
        </div>
        <div class="header-right">
            <a href="/attendance/index.php" class="btn-back">🔙 Scanner</a>
            <button class="btn-back" onclick="location.reload()">🔄 Refresh</button>
        </div>
    </header>

    <div class="stats-bar">
        <div class="stat-card">
            <div class="number" id="statTotal"><?php echo $stats['total']; ?></div>
            <div class="label">Total Scans Today</div>
        </div>
        <div class="stat-card">
            <div class="number allowed" id="statAllowed"><?php echo $stats['allowed']; ?></div>
            <div class="label">✅ Allowed</div>
        </div>
        <div class="stat-card">
            <div class="number denied" id="statDenied"><?php echo $stats['denied']; ?></div>
            <div class="label">❌ Denied</div>
        </div>
        <div class="stat-card">
            <div class="number overridden" id="statOverridden"><?php echo $stats['overridden']; ?></div>
            <div class="label">⚠️ Overridden</div>
        </div>
    </div>

    <div class="main-container">
        <div class="table-container">
            <div class="table-header">
                <span>📋 Activity Log</span>
                <div class="auto-refresh">
                    <span class="dot active" id="refreshDot"></span>
                    <span id="refreshStatus">Auto-refresh: 10s</span>
                    <span style="color:#999;margin-left:10px;" id="logCount"><?php echo count($logs); ?> entries</span>
                </div>
            </div>
            <div class="table-scroll" id="tableScroll">
                <table>
                    <thead>
                        <tr>
                            <th>Student</th>
                            <th>Type</th>
                            <th>Decision</th>
                            <th>Time</th>
                            <th>Reason</th>
                        </tr>
                    </thead>
                    <tbody id="logsBody">
                        <?php if (empty($logs)): ?>
                        <tr>
                            <td colspan="5">
                                <div class="empty-state">
                                    <div class="icon">📭</div>
                                    <p>No logs found</p>
                                </div>
                            </td>
                        </tr>
                        <?php else: ?>
                        <?php foreach ($logs as $log): ?>
                        <tr class="<?php echo $log['decision'] === 'denied' ? 'denied-row' : ($log['decision'] === 'overridden' ? 'overridden-row' : ''); ?>">
                            <td>
                                <div class="student-cell">
                                    <img src="<?php echo $log['user_photo'] ?? '/assets/default_avatar.png'; ?>" alt="<?php echo htmlspecialchars($log['user_name'] ?? 'Unknown'); ?>" onerror="this.src='/assets/default_avatar.png'">
                                    <div>
                                        <div><strong><?php echo htmlspecialchars($log['user_name'] ?? 'Unknown'); ?></strong></div>
                                        <div style="font-size:11px;color:#999;">ID: <?php echo $log['user_id']; ?></div>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <span class="badge-status <?php echo $log['scan_type'] === 'entry' ? 'allowed' : 'exit'; ?>">
                                    <?php echo $log['scan_type'] === 'entry' ? '🚪 In' : '🚶 Out'; ?>
                                </span>
                            </td>
                            <td>
                                <span class="badge-status <?php echo $log['decision']; ?>">
                                    <?php echo $log['decision'] === 'allowed' ? '✅ Allowed' : ($log['decision'] === 'denied' ? '❌ Denied' : '⚠️ Overridden'); ?>
                                </span>
                            </td>
                            <td><?php echo date('H:i:s', strtotime($log['scanned_at'])); ?></td>
                            <td style="font-size:12px;color:#666;max-width:200px;word-wrap:break-word;">
                                <?php echo htmlspecialchars($log['auto_reason'] ?? $log['override_reason'] ?? '-'); ?>
                                <?php if ($log['overridden_by']): ?>
                                <br><small>by <?php echo htmlspecialchars($log['overrider_name'] ?? 'Admin'); ?></small>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <script>
        // ============================================
        // AUTO-REFRESH
        // ============================================
        let refreshInterval = 10; // seconds
        let refreshTimer = null;
        let countdown = refreshInterval;
        
        function startAutoRefresh() {
            if (refreshTimer) clearInterval(refreshTimer);
            
            refreshTimer = setInterval(function() {
                countdown--;
                document.getElementById('refreshStatus').textContent = 'Auto-refresh: ' + countdown + 's';
                
                if (countdown <= 0) {
                    countdown = refreshInterval;
                    refreshData();
                }
            }, 1000);
        }
        
        function refreshData() {
            document.getElementById('refreshDot').className = 'dot';
            document.getElementById('refreshStatus').textContent = 'Refreshing...';
            
            fetch(window.location.href)
                .then(response => response.text())
                .then(html => {
                    // Parse the new HTML to get updated data
                    const parser = new DOMParser();
                    const doc = parser.parseFromString(html, 'text/html');
                    
                    // Update stats
                    const newTotal = doc.querySelector('#statTotal')?.textContent || '0';
                    const newAllowed = doc.querySelector('#statAllowed')?.textContent || '0';
                    const newDenied = doc.querySelector('#statDenied')?.textContent || '0';
                    const newOverridden = doc.querySelector('#statOverridden')?.textContent || '0';
                    
                    document.getElementById('statTotal').textContent = newTotal;
                    document.getElementById('statAllowed').textContent = newAllowed;
                    document.getElementById('statDenied').textContent = newDenied;
                    document.getElementById('statOverridden').textContent = newOverridden;
                    
                    // Update logs
                    const newLogs = doc.querySelector('#logsBody')?.innerHTML || '';
                    document.getElementById('logsBody').innerHTML = newLogs;
                    
                    // Update count
                    const newCount = doc.querySelector('#logCount')?.textContent || '0 entries';
                    document.getElementById('logCount').textContent = newCount;
                    
                    document.getElementById('refreshDot').className = 'dot active';
                    countdown = refreshInterval;
                    document.getElementById('refreshStatus').textContent = 'Auto-refresh: ' + countdown + 's';
                })
                .catch(error => {
                    console.error('Refresh error:', error);
                    document.getElementById('refreshDot').className = 'dot active';
                    document.getElementById('refreshStatus').textContent = 'Refresh failed - retrying...';
                    countdown = refreshInterval;
                });
        }
        
        // ============================================
        // SCROLL TO BOTTOM
        // ============================================
        function scrollToBottom() {
            const scroll = document.getElementById('tableScroll');
            scroll.scrollTop = scroll.scrollHeight;
        }
        
        // Auto-scroll if near bottom
        document.getElementById('tableScroll').addEventListener('scroll', function() {
            const isNearBottom = this.scrollHeight - this.scrollTop - this.clientHeight < 100;
            if (isNearBottom) {
                // Will auto-scroll on next update
            }
        });
        
        // ============================================
        // INIT
        // ============================================
        document.addEventListener('DOMContentLoaded', function() {
            console.log('📊 Admin Dashboard ready');
            startAutoRefresh();
            setTimeout(scrollToBottom, 500);
        });
    </script>
</body>
</html>