<?php
// ============================================================
// chat_debug.php — TEMPORARY diagnostic tool
// DELETE THIS FILE after use!
// ============================================================
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) session_start();
?>
<!DOCTYPE html>
<html>
<head>
<title>Chat Debug</title>
<style>
body { font-family: monospace; background: #1a1a1a; color: #f0f0f0; padding: 24px; }
h2  { color: #F97316; border-bottom: 1px solid #333; padding-bottom: 8px; }
pre { background: #2a2a2a; padding: 16px; border-radius: 8px; overflow-x: auto; border-left: 3px solid #F97316; }
.ok  { color: #22C55E; }
.err { color: #EF4444; }
.warn { color: #FBBF24; }
</style>
</head>
<body>

<h2>🔍 Session Variables</h2>
<pre><?php var_dump($_SESSION); ?></pre>

<h2>🗄️ Database Connection</h2>
<pre><?php
$candidates = ['db.php', '../db.php', '../includes/db.php', '../config/db.php'];
$found = false;
foreach ($candidates as $c) {
    echo "Checking: $c → ";
    if (file_exists($c)) {
        echo "<span class='ok'>EXISTS</span>\n";
        try {
            require_once $c;
            echo "Connection: <span class='ok'>OK ✓</span>\n";
            $found = true;
        } catch (Throwable $e) {
            echo "<span class='err'>ERROR: " . $e->getMessage() . "</span>\n";
        }
        break;
    } else {
        echo "<span class='warn'>not found</span>\n";
    }
}
if (!$found) echo "<span class='err'>No db.php found in any location!</span>\n";
?></pre>

<h2>📁 Directory Listing (current folder)</h2>
<pre><?php
$files = scandir('.');
foreach ($files as $f) {
    if (in_array($f, ['.','..'])) continue;
    echo (is_dir($f) ? "[DIR]  " : "[FILE] ") . $f . "\n";
}
?></pre>

<h2>⚙️ PHP Info Summary</h2>
<pre><?php
echo "PHP Version : " . PHP_VERSION . "\n";
echo "Server OS   : " . PHP_OS . "\n";
echo "Script path : " . __FILE__ . "\n";
echo "Server root : " . ($_SERVER['DOCUMENT_ROOT'] ?? 'N/A') . "\n";
echo "Session save: " . session_save_path() . "\n";
?></pre>

<p style="color:#EF4444;margin-top:24px;">
    ⚠️ <strong>DELETE this file immediately after use.</strong> It exposes server info.
</p>
</body>
</html>