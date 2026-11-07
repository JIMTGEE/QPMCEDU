<?php
/**
 * launchcards.php
 * Queenest Paradise Montessori Complex — Permanent Login Launch Cards
 * Each user gets an ID-card-sized card (CR80: 85.60mm × 53.98mm) with
 * email, QR code for gate access, and login URL.
 *
 * QR codes encode: USER_ID|EMAIL (for gate scanner)
 * When scanned at the gate, it checks the user ID and grants/denies access.
 * 
 * RESTRICTED TO ADMIN ROLE ONLY.
 */

ob_start();
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ── Access Control ──────────────────────────────────────────────────────────
if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    $_SESSION['error_msg'] = "You must be logged in as an administrator to view launch cards.";
    header("Location: /login_form.php");
    exit();
}

// ── Database ─────────────────────────────────────────────────────────────────
$db_path = $_SERVER['DOCUMENT_ROOT'] . '/config/db.php';
if (!file_exists($db_path)) {
    die("System error: Database configuration file not found.");
}
require_once($db_path);

if (!isset($conn) || $conn->connect_error) {
    die("Database connection failed.");
}

// ── Constants ─────────────────────────────────────────────────────────────────
define('DEFAULT_PASSWORD_DISPLAY', '••••••••'); // Show masked password
define('PORTAL_BASE_URL',  'https://qpmcedu.com');
define('LOGIN_URL',        PORTAL_BASE_URL . '/login_form.php');
define('GATE_SCANNER_URL', PORTAL_BASE_URL . '/attendance/index.php');
define('SCHOOL_NAME',      'Queenest Paradise Montessori Complex');
define('SCHOOL_SHORT',     'QPM');
define('UPLOAD_DIR',       $_SERVER['DOCUMENT_ROOT'] . '/uploads/profile_pictures/');

// ── Class name mapping ──────────────────────────────────────────────────────
define('CLASS_MAPPING', [
    'Esther Foundation'   => 'Creche',
    'Sheba 1'             => 'Nursery 1',
    'Sheba 2'             => 'Nursery 2',
    'Yaasant 1'           => 'KG 1',
    'Yaasant 2'           => 'KG 2',
    'Cleopatra 1'         => 'Basic 1',
    'Cleopatra 2'         => 'Basic 2',
    'Nefertiti 1'         => 'Basic 3',
    'Nefertiti 2'         => 'Basic 4',
    'Malika 1'            => 'Basic 5',
    'Malika 2'            => 'Basic 6',
    'Makeda 1'            => 'Basic 7',
    'Makeda 2'            => 'Basic 8',
    'An Majesty'          => 'Basic 9',
]);

function getDisplayClassName(string $className): string {
    return CLASS_MAPPING[$className] ?? $className;
}

// ── Fetch all classes ─────────────────────────────────────────────────────
$classesQuery = "SELECT id, class_name FROM classes ORDER BY class_name ASC";
$classesResult = $conn->query($classesQuery);
$allClasses = [];
while ($row = $classesResult->fetch_assoc()) {
    $allClasses[] = $row;
}

// ── Fetch all active users with class info ──────────────────────────────────
$sql = "SELECT u.id, u.name, u.email, u.role, u.gender, u.admission_date,
               u.status, u.profile_picture, u.password,
               sc.class_id, c.class_name
        FROM users u
        LEFT JOIN student_classes sc ON u.id = sc.user_id 
            AND sc.academic_year = (SELECT MAX(academic_year) FROM student_classes WHERE user_id = u.id)
        LEFT JOIN classes c ON sc.class_id = c.id
        WHERE u.status = 'active'
        ORDER BY FIELD(u.role, 'student', 'teacher', 'parent', 'admin'), 
                 c.class_name ASC, u.name ASC";

$result = $conn->query($sql);
if (!$result) {
    die("Query failed: " . $conn->error);
}

$users = [];
while ($row = $result->fetch_assoc()) {
    $users[] = $row;
}

$conn->close();

// ── URL builders ─────────────────────────────────────────────────────────────
function buildLoginURL(string $email): string {
    return LOGIN_URL . '?' . http_build_query([
        'email' => $email,
        'auto'  => '1'
    ]);
}

// Gate QR encodes USER_ID|EMAIL for the gate scanner
function buildGateQRData(int $userId, string $email): string {
    return $userId . '|' . $email;
}

function qrCodeUrl(string $data, int $size = 250): string {
    return "https://api.qrserver.com/v1/create-qr-code/?size={$size}x{$size}&ecc=M&margin=1&data=" . urlencode($data);
}

// ── Profile picture helpers ───────────────────────────────────────────────────
function getProfilePictureUrl(?string $pic, string $name): string {
    if ($pic && file_exists(UPLOAD_DIR . $pic)) {
        return '/uploads/profile_pictures/' . $pic;
    }
    return generateInitialsAvatar($name);
}

function generateInitialsAvatar(string $name): string {
    $initials = implode('', array_map(
        fn($w) => strtoupper($w[0] ?? ''),
        array_filter(explode(' ', $name))
    ));
    $initials  = mb_substr($initials, 0, 2) ?: mb_strtoupper(mb_substr($name, 0, 2));
    $colors    = ['#1a237e', '#0d47a1', '#1565c0', '#e65100', '#bf360c', '#2e7d32'];
    $bgColor   = $colors[abs(crc32($name)) % count($colors)];

    $svg  = '<svg xmlns="http://www.w3.org/2000/svg" width="100" height="100" viewBox="0 0 100 100">';
    $svg .= '<rect width="100" height="100" fill="' . $bgColor . '"/>';
    $svg .= '<text x="50" y="50" dominant-baseline="central" text-anchor="middle" '
          . 'fill="white" font-family="Arial,sans-serif" font-size="40" font-weight="bold">'
          . htmlspecialchars($initials) . '</text></svg>';

    return 'data:image/svg+xml;base64,' . base64_encode($svg);
}

// ── Handle print mode ────────────────────────────────────────────────────────
$printMode = isset($_GET['print']);
$printIds  = [];

if ($printMode) {
    if ($_GET['print'] === 'selected' && isset($_GET['ids'])) {
        $printIds = array_map('intval', explode(',', $_GET['ids']));
    }
}

// ── Filter & search ──────────────────────────────────────────────────────────
$search       = trim($_GET['search'] ?? '');
$roleFilter   = trim($_GET['role']   ?? '');
$classFilter  = trim($_GET['class']  ?? '');
$sortBy       = trim($_GET['sort']   ?? 'class');

// Apply print filter
if ($printMode && !empty($printIds)) {
    $users = array_values(array_filter($users, fn($u) => in_array($u['id'], $printIds)));
}

// Apply search and filters
$users = array_values(array_filter($users, function ($u) use ($search, $roleFilter, $classFilter) {
    $match = true;
    if ($search !== '') {
        $match = stripos($u['name'], $search) !== false
              || stripos($u['email'], $search) !== false;
    }
    if ($roleFilter !== '' && $match) {
        $match = $u['role'] === $roleFilter;
    }
    if ($classFilter !== '' && $match) {
        $match = (string)$u['class_id'] === $classFilter;
    }
    return $match;
}));

// ── Sort users ──────────────────────────────────────────────────────────────
usort($users, function ($a, $b) use ($sortBy) {
    if ($sortBy === 'class') {
        $classA = $a['class_name'] ?? 'ZZZ';
        $classB = $b['class_name'] ?? 'ZZZ';
        $classCmp = strcasecmp($classA, $classB);
        if ($classCmp !== 0) return $classCmp;
        return strcasecmp($a['name'], $b['name']);
    } elseif ($sortBy === 'role') {
        $roleOrder = ['student' => 1, 'teacher' => 2, 'parent' => 3, 'admin' => 4];
        $roleA = $roleOrder[$a['role']] ?? 5;
        $roleB = $roleOrder[$b['role']] ?? 5;
        if ($roleA !== $roleB) return $roleA - $roleB;
        return strcasecmp($a['name'], $b['name']);
    } else {
        return strcasecmp($a['name'], $b['name']);
    }
});

// ── Group users by class for SCREEN display ─────────────────────────────
$groupedUsers = [];
foreach ($users as $user) {
    $className = $user['class_name'] ? getDisplayClassName($user['class_name']) : 'Unassigned';
    $groupedUsers[$className][] = $user;
}

// ── Role stats ────────────────────────────────────────────────────────────────
$roleCounts = [];
foreach ($users as $user) {
    $roleCounts[$user['role']] = ($roleCounts[$user['role']] ?? 0) + 1;
}

// ── Visual helpers ────────────────────────────────────────────────────────────
function getRoleBadgeColor(string $role): string {
    return match($role) {
        'admin'   => '#dc3545',
        'teacher' => '#0d6efd',
        'student' => '#28a745',
        'parent'  => '#ff6b00',
        default   => '#6c757d'
    };
}

function getRoleIcon(string $role): string {
    return match($role) {
        'admin'   => '👑',
        'teacher' => '👨‍🏫',
        'student' => '🎓',
        'parent'  => '👨‍👩‍👧',
        default   => '👤'
    };
}

$isPrintView = $printMode;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Launch Cards — <?= htmlspecialchars(SCHOOL_NAME) ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        :root {
            --primary:    #003087;
            --secondary:  #1a4db8;
            --accent:     #d81b60;
            --surface:    #ffffff;
            --bg:         #f8f9fa;
            --text-dark:  #1a1a2e;
            --text-muted: #6b7280;
            --border:     #e0e0e0;
            --card-w: 85.60mm;
            --card-h: 53.98mm;
            --radius:     3mm;
        }

        body {
            font-family: 'Segoe UI', Arial, sans-serif;
            background: linear-gradient(135deg, #d81b60 0%, #003087 100%);
            color: var(--text-dark);
            min-height: 100vh;
        }

        <?php if ($isPrintView): ?>
        body { background: #fff !important; }
        <?php endif; ?>

        /* ── Top Bar ─────────────────────────────────────────────────── */
        .topbar {
            background: rgba(255,255,255,.95);
            color: var(--primary);
            padding: 16px 28px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 14px;
            flex-wrap: wrap;
            position: sticky;
            top: 0;
            z-index: 100;
            box-shadow: 0 2px 12px rgba(0,0,0,.15);
            border-bottom: 3px solid var(--accent);
        }
        .topbar-title { display: flex; flex-direction: column; }
        .topbar-title span:first-child { font-size: 1.2rem; font-weight: 700; }
        .topbar-title span:last-child  { font-size: .78rem; color: var(--text-muted); margin-top: 2px; }
        .topbar-actions { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
        .topbar-actions input[type="search"],
        .topbar-actions select { padding: 8px 14px; border-radius: 8px; border: 2px solid var(--border); font-size: .88rem; outline: none; }
        .topbar-actions input[type="search"] { width: 180px; }
        .topbar-actions select { cursor: pointer; }
        .topbar-actions input:focus,
        .topbar-actions select:focus { border-color: var(--primary); }

        .btn {
            padding: 8px 16px; border-radius: 8px; font-size: .85rem;
            font-weight: 600; cursor: pointer; border: none;
            transition: all .2s; text-decoration: none; display: inline-flex; align-items: center; gap: 5px;
        }
        .btn:hover { opacity: .9; transform: translateY(-1px); box-shadow: 0 4px 12px rgba(0,0,0,.2); }
        .btn-accent  { background: linear-gradient(to right, var(--primary), var(--secondary)); color: #fff; }
        .btn-outline { background: transparent; color: var(--primary); border: 2px solid var(--primary); }
        .btn-success { background: #28a745; color: #fff; }
        .btn-gate    { background: #e67e22; color: #fff; }
        .btn-gate:hover { background: #d35400; }

        /* ── Bulk Actions Bar ────────────────────────────────────────── */
        .bulk-actions {
            background: rgba(255,255,255,.9);
            border-bottom: 2px solid var(--accent);
            padding: 12px 28px;
            display: flex;
            align-items: center;
            gap: 14px;
            flex-wrap: wrap;
        }
        .bulk-actions.hidden { display: none; }
        .bulk-actions .selected-count { font-weight: 600; color: var(--primary); }

        /* ── Stats Bar ───────────────────────────────────────────────── */
        .statsbar {
            background: rgba(255,255,255,.9);
            border-bottom: 1px solid var(--border);
            padding: 12px 28px;
            display: flex;
            gap: 20px;
            align-items: center;
            flex-wrap: wrap;
        }
        .stat-chip { display: flex; align-items: center; gap: 6px; font-size: .82rem; color: var(--text-muted); }
        .stat-chip strong { color: var(--primary); font-size: .95rem; }
        .stat-chip.warning { background: #fff3cd; padding: 6px 12px; border-radius: 8px; border: 1px solid #ffc107; }
        .role-summary { display: flex; gap: 12px; flex-wrap: wrap; margin-bottom: 6px; }
        .role-summary-item {
            display: flex; align-items: center; gap: 6px;
            padding: 5px 10px; border-radius: 8px; background: #fff;
            border: 1px solid var(--border); font-size: .82rem;
        }
        .role-summary-item .count { font-weight: 700; font-size: 1rem; }

        /* ── Print Preview Bar ───────────────────────────────────────── */
        .print-preview-bar {
            background: linear-gradient(to right, #059669, #10b981);
            color: #fff;
            padding: 14px 28px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 12px;
        }
        .print-preview-bar .info { display: flex; align-items: center; gap: 10px; }
        .print-preview-bar .info i { font-size: 1.3rem; }
        .print-preview-bar .info span { font-size: .9rem; }
        .print-preview-bar .actions { display: flex; gap: 8px; }
        .print-preview-bar .btn-white {
            background: #fff; color: #059669; border: none;
            padding: 10px 20px; border-radius: 8px; font-weight: 600;
            cursor: pointer; font-size: .88rem; transition: all .2s;
            display: inline-flex; align-items: center; gap: 6px;
        }
        .print-preview-bar .btn-white:hover { background: #f0fdf4; transform: translateY(-1px); }
        .print-preview-bar .btn-outline-white {
            background: transparent; color: #fff; border: 2px solid rgba(255,255,255,.6);
            padding: 10px 20px; border-radius: 8px; font-weight: 600;
            cursor: pointer; font-size: .88rem; transition: all .2s;
            display: inline-flex; align-items: center; gap: 6px; text-decoration: none;
        }
        .print-preview-bar .btn-outline-white:hover { background: rgba(255,255,255,.15); }

        /* ── Content Wrapper ─────────────────────────────────────────── */
        .content-wrapper {
            background: rgba(255,255,255,.95);
            border-radius: 16px;
            margin: 20px;
            padding: 20px;
            box-shadow: 0 8px 24px rgba(0,0,0,.2);
        }

        /* ── SCREEN VIEW: Class Section Headers ──────────────────────── */
        .class-section { margin-bottom: 20px; }
        .class-section-header {
            background: linear-gradient(to right, var(--primary), var(--secondary));
            color: #fff;
            padding: 8px 16px;
            border-radius: 8px 8px 0 0;
            font-size: .95rem;
            font-weight: 700;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 8px;
        }
        .class-section-header .class-count {
            background: rgba(255,255,255,.25);
            padding: 2px 10px;
            border-radius: 12px;
            font-size: .8rem;
        }

        /* ── Cards Grid ──────────────────────────────────────────────── */
        .cards-grid {
            display: flex !important;
            flex-wrap: wrap !important;
            gap: 12px;
            padding: 10px 0;
            justify-content: flex-start;
        }
        .cards-grid.print-layout { gap: 6mm; justify-content: flex-start; }

        /* ── Single ID Card (CR80: 85.60mm × 53.98mm) ──────────────── */
        .launch-card {
            width: var(--card-w);
            height: var(--card-h);
            min-width: var(--card-w);
            min-height: var(--card-h);
            background: var(--surface);
            border-radius: var(--radius);
            box-shadow: 0 2px 8px rgba(0,48,135,.12);
            border: 1.5px solid var(--border);
            display: flex;
            overflow: hidden;
            position: relative;
            transition: box-shadow .2s, transform .15s, border-color .2s;
            page-break-inside: avoid;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }
        .launch-card:hover {
            box-shadow: 0 6px 20px rgba(0,48,135,.2);
            transform: translateY(-1px);
        }
        .launch-card.selected {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(0,48,135,.25);
        }

        .card-select { position: absolute; top: 3mm; right: 3mm; z-index: 10; }
        .card-select input[type="checkbox"] { width: 4mm; height: 4mm; cursor: pointer; accent-color: var(--primary); }

        /* Left accent stripe */
        .card-stripe {
            width: 3mm;
            flex-shrink: 0;
            background: linear-gradient(180deg, var(--primary) 0%, var(--accent) 100%);
        }
        .card-stripe.admin   { background: linear-gradient(180deg, #dc3545 0%, #f8a0a8 100%); }
        .card-stripe.teacher { background: linear-gradient(180deg, #0d6efd 0%, #7db8ff 100%); }
        .card-stripe.parent  { background: linear-gradient(180deg, #ff6b00 0%, #ffb380 100%); }

        /* Main info area */
        .card-info {
            flex: 1;
            padding: 3mm 3mm 2mm 3mm;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            min-width: 0;
        }

        .card-header  { display: flex; align-items: center; gap: 2mm; }
        .card-avatar  {
            width: 10mm; height: 10mm; border-radius: 50%; overflow: hidden;
            flex-shrink: 0; border: 1px solid var(--primary);
        }
        .card-avatar img { width: 100%; height: 100%; object-fit: cover; }
        .card-name-wrap { min-width: 0; }
        .card-school {
            font-size: 1.8mm; text-transform: uppercase; letter-spacing: .5px;
            color: var(--primary); font-weight: 700;
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
        }
        .card-name {
            font-size: 2.5mm; font-weight: 700; color: var(--text-dark);
            line-height: 1.2; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
        }
        .card-class {
            font-size: 1.8mm; color: var(--accent); font-weight: 600;
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
        }
        .role-badge {
            display: inline-block; padding: 0.5mm 2mm; border-radius: 1.5mm;
            font-size: 1.6mm; font-weight: 700; text-transform: uppercase;
            color: #fff; margin-top: 1px;
        }

        /* Credentials block */
        .card-credentials {
            background: #fafafa; border-radius: 2mm;
            padding: 2mm; border: 1px solid var(--border);
        }
        .cred-row { display: flex; align-items: center; gap: 1.5mm; margin-bottom: 1mm; }
        .cred-row:last-child { margin-bottom: 0; }
        .cred-label { font-size: 1.6mm; font-weight: 700; text-transform: uppercase; letter-spacing: .3px; color: var(--text-muted); width: 10mm; flex-shrink: 0; }
        .cred-value { font-size: 2mm; font-weight: 600; color: var(--text-dark); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .cred-value.pass {
            font-family: 'Courier New', monospace;
            background: #e8e8e8; color: #555;
            padding: 0.5mm 1.5mm; border-radius: 1mm;
            letter-spacing: 0.5px; font-size: 2mm;
        }

        /* Footer */
        .card-footer { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 1mm; }
        .card-url {
            font-size: 1.6mm; color: var(--primary); font-weight: 700;
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
            max-width: 40mm; text-decoration: none;
        }
        .card-id-badge {
            background: #fff; border: 1px solid var(--border);
            border-radius: 1mm; padding: 0.5mm 1.5mm;
            font-size: 1.6mm; color: var(--text-muted); font-weight: 600;
        }
        .gate-badge {
            background: #e67e22; border: 1px solid #d35400; border-radius: 1mm;
            padding: 0.5mm 1.5mm; font-size: 1.6mm; color: #fff; font-weight: 700;
            display: flex; align-items: center; gap: 1mm;
        }

        /* QR Area - right side */
        .card-qr {
            width: 22mm; flex-shrink: 0;
            background: #fff;
            display: flex; flex-direction: column;
            align-items: center; justify-content: center;
            padding: 2mm; gap: 1mm;
            border-left: 1px dashed var(--border);
        }
        .card-qr img {
            width: 19mm; height: 19mm;
            border-radius: 1mm; border: 1px solid #ddd;
        }
        .card-qr-label { font-size: 1.5mm; text-align: center; color: var(--text-muted); line-height: 1.2; padding: 0 1mm; }
        .card-qr-label strong { color: var(--primary); font-size: 1.7mm; }

        /* ── Empty State ─────────────────────────────────────────────── */
        .empty-state { padding: 64px 32px; text-align: center; color: var(--text-muted); width: 100%; }
        .empty-state p { font-size: 1.05rem; }

        /* ── Info Banner ─────────────────────────────────────────────── */
        .info-banner {
            background: #d1ecf1; border: 1px solid #bee5eb; border-radius: 8px;
            padding: 10px 16px; margin-bottom: 16px;
            display: flex; align-items: center; gap: 10px;
            font-size: .82rem; color: #0c5460;
        }
        .info-banner i { font-size: 1.1rem; color: #17a2b8; }
        .info-banner code { background: #fff; padding: 2px 6px; border-radius: 3px; font-size: .78rem; }

        /* ── PRINT STYLES ────────────────────────────────────────────── */
        @media print {
            html, body {
                width: 100% !important;
                height: auto !important;
                margin: 0 !important;
                padding: 0 !important;
                background: #fff !important;
                color: #000 !important;
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
            }
            .topbar, .statsbar, .bulk-actions, .info-banner, .print-preview-bar, 
            .card-select, .class-section-header, .no-print, .btn {
                display: none !important;
                visibility: hidden !important;
                opacity: 0 !important;
                width: 0 !important;
                height: 0 !important;
                margin: 0 !important;
                padding: 0 !important;
                overflow: hidden !important;
            }
            .class-section { display: contents !important; margin: 0 !important; break-inside: auto !important; }
            .content-wrapper {
                background: none !important;
                box-shadow: none !important;
                border-radius: 0 !important;
                margin: 0 !important;
                padding: 0 !important;
                border: none !important;
            }
            .cards-grid {
                display: flex !important;
                flex-wrap: wrap !important;
                gap: 3mm !important;
                padding: 2mm !important;
                margin: 0 !important;
                justify-content: flex-start !important;
                align-content: flex-start !important;
                width: 100% !important;
                visibility: visible !important;
                opacity: 1 !important;
            }
            .class-section > div { display: contents !important; }
            .launch-card {
                display: flex !important;
                visibility: visible !important;
                opacity: 1 !important;
                width: 85.60mm !important;
                height: 53.98mm !important;
                min-width: 85.60mm !important;
                min-height: 53.98mm !important;
                box-shadow: none !important;
                border: 1px solid #999 !important;
                border-radius: 3mm !important;
                margin: 0 !important;
                page-break-inside: avoid !important;
                break-inside: avoid !important;
                overflow: hidden !important;
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
            }
            .card-avatar img, .card-qr img {
                visibility: visible !important;
                opacity: 1 !important;
                display: block !important;
            }
            .card-stripe, .role-badge, .gate-badge, .card-credentials {
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
            }
            @page { size: A4; margin: 8mm; }
        }

        @media (max-width: 768px) {
            .content-wrapper { margin: 10px; padding: 12px; }
            .topbar, .statsbar { padding: 12px 16px; }
            .launch-card { width: 100%; max-width: var(--card-w); }
        }
    </style>
</head>
<body>

<?php if ($isPrintView): ?>
<div class="print-preview-bar no-print">
    <div class="info">
        <i class="fas fa-print"></i>
        <span>
            <strong>Print Preview</strong> — 
            <?= count($users) ?> card<?= count($users) !== 1 ? 's' : '' ?> ready 
            | CR80 (85.60mm × 53.98mm)
        </span>
    </div>
    <div class="actions">
        <button class="btn-white" onclick="triggerPrint()">
            <i class="fas fa-print"></i> Print Now
        </button>
        <a href="launchcards.php" class="btn-outline-white">
            <i class="fas fa-arrow-left"></i> Back
        </a>
    </div>
</div>
<?php endif; ?>

<?php if (!$isPrintView): ?>
<div class="topbar no-print">
    <div class="topbar-title">
        <span><i class="fas fa-id-card me-2"></i>Permanent Launch Cards</span>
        <span><?= htmlspecialchars(SCHOOL_NAME) ?> — CR80 Cards (85.60mm × 53.98mm)</span>
    </div>
    <div class="topbar-actions">
        <form method="GET" action="" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
            <input type="search" name="search" placeholder="Search name or email…" value="<?= htmlspecialchars($search) ?>">
            <select name="role" onchange="this.form.submit()">
                <option value="">All Roles</option>
                <option value="admin"   <?= $roleFilter === 'admin'   ? 'selected' : '' ?>>Admin</option>
                <option value="teacher" <?= $roleFilter === 'teacher' ? 'selected' : '' ?>>Teacher</option>
                <option value="student" <?= $roleFilter === 'student' ? 'selected' : '' ?>>Student</option>
                <option value="parent"  <?= $roleFilter === 'parent'  ? 'selected' : '' ?>>Parent</option>
            </select>
            <select name="class" onchange="this.form.submit()">
                <option value="">All Classes</option>
                <?php foreach ($allClasses as $cls): ?>
                <option value="<?= $cls['id'] ?>" <?= $classFilter === (string)$cls['id'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars(getDisplayClassName($cls['class_name'])) ?>
                </option>
                <?php endforeach; ?>
            </select>
            <select name="sort" onchange="this.form.submit()">
                <option value="class" <?= $sortBy === 'class' ? 'selected' : '' ?>>Sort by Class</option>
                <option value="role"  <?= $sortBy === 'role'  ? 'selected' : '' ?>>Sort by Role</option>
                <option value="name"  <?= $sortBy === 'name'  ? 'selected' : '' ?>>Sort by Name</option>
            </select>
            <button type="submit" class="btn btn-accent"><i class="fas fa-filter"></i> Apply</button>
        </form>
        <button class="btn btn-success" onclick="printSelected()"><i class="fas fa-print"></i> Print Selected</button>
        <button class="btn btn-accent" onclick="printAll()"><i class="fas fa-print"></i> Print All</button>
        <a href="/attendance/index.php" class="btn btn-gate" target="_blank">
            <i class="fas fa-gate"></i> Gate Scanner
        </a>
        <a href="/admin/dashboard.php" class="btn btn-outline"><i class="fas fa-arrow-left"></i> Dashboard</a>
    </div>
</div>
<?php endif; ?>

<div class="content-wrapper">

<?php if (!$isPrintView): ?>
<div class="info-banner no-print">
    <i class="fas fa-credit-card"></i>
    <span>
        <strong>CR80 Standard ID Cards:</strong> 85.60mm × 53.98mm.
        QR codes work with the <strong>Gate Scanner</strong> for entry/exit control.
        Cards are <strong>grouped by class</strong> on screen but <strong>flow continuously when printed</strong>.
    </span>
</div>

<div class="bulk-actions hidden no-print" id="bulkActions">
    <span class="selected-count"><i class="fas fa-check-circle"></i> <span id="selectedCount">0</span> user(s) selected</span>
    <div style="display:flex;gap:8px;align-items:center;">
        <button type="button" class="btn btn-success" onclick="printSelected()" style="font-size:.8rem;">
            <i class="fas fa-print"></i> Print Selected
        </button>
        <button type="button" class="btn btn-outline" onclick="clearSelection()"
                style="font-size:.8rem;color:#333;border-color:#333;"><i class="fas fa-times"></i> Clear</button>
    </div>
    <div class="select-all-container">
        <input type="checkbox" id="selectAll" onchange="toggleSelectAll(this)">
        <label for="selectAll" style="font-size:.82rem;font-weight:600;">Select All</label>
    </div>
</div>

<div class="statsbar no-print">
    <div class="stat-chip">
        <strong><?= count($users) ?></strong> user<?= count($users) !== 1 ? 's' : '' ?>
    </div>
    <div class="role-summary">
        <?php if (!empty($roleCounts['admin'])): ?>
        <div class="role-summary-item stat-chip admin-badge">
            👑 <span class="count"><?= $roleCounts['admin'] ?></span>
        </div>
        <?php endif; ?>
        <?php if (!empty($roleCounts['teacher'])): ?>
        <div class="role-summary-item stat-chip teacher-badge">
            👨‍🏫 <span class="count"><?= $roleCounts['teacher'] ?></span>
        </div>
        <?php endif; ?>
        <?php if (!empty($roleCounts['student'])): ?>
        <div class="role-summary-item stat-chip student-badge">
            🎓 <span class="count"><?= $roleCounts['student'] ?></span>
        </div>
        <?php endif; ?>
        <?php if (!empty($roleCounts['parent'])): ?>
        <div class="role-summary-item stat-chip parent-badge">
            👨‍👩‍👧 <span class="count"><?= $roleCounts['parent'] ?></span>
        </div>
        <?php endif; ?>
    </div>
    <div class="stat-chip warning">
        <i class="fas fa-ruler"></i>
        <strong>CR80: 85.60mm × 53.98mm</strong>
    </div>
    <div class="stat-chip" style="margin-left:auto;">
        <i class="fas fa-globe"></i>
        <strong><?= htmlspecialchars(PORTAL_BASE_URL) ?></strong>
    </div>
</div>
<?php endif; ?>

<!-- ── Cards Grid ────────────────────────────────────────────────────────── -->
<div class="cards-grid <?= $isPrintView ? 'print-layout' : '' ?>" id="cardsGrid">

<?php if (empty($users)): ?>
    <div class="empty-state">
        <i class="fas fa-users-slash" style="font-size:3rem;opacity:.3;display:block;margin-bottom:12px;"></i>
        <p>No active users found<?= $search ? ' matching "' . htmlspecialchars($search) . '"' : '' ?>.</p>
    </div>
<?php else: ?>

    <?php if ($isPrintView): ?>
    <!-- PRINT VIEW: Continuous flow -->
    <?php foreach ($users as $user):
        $email      = htmlspecialchars($user['email']);
        $name       = htmlspecialchars($user['name']);
        $role       = $user['role'];
        $userId     = (int)$user['id'];
        $roleIcon   = getRoleIcon($role);
        $roleColor  = getRoleBadgeColor($role);
        $stripeClass = in_array($role, ['admin', 'teacher', 'parent']) ? $role : '';
        $className  = $user['class_name'] ? getDisplayClassName($user['class_name']) : 'N/A';

        $profilePicUrl = getProfilePictureUrl($user['profile_picture'], $user['name']);
        $loginUrl = buildLoginURL($user['email']);
        
        // Gate QR encodes: USER_ID|EMAIL
        $gateData = buildGateQRData($userId, $user['email']);
        $qrUrl    = qrCodeUrl($gateData);
    ?>
    <div class="launch-card" data-id="<?= $userId ?>" data-role="<?= $role ?>" data-class="<?= htmlspecialchars($className) ?>">
        <div class="card-stripe <?= $stripeClass ?>"></div>
        <div class="card-info">
            <div class="card-header">
                <div class="card-avatar">
                    <img src="<?= htmlspecialchars($profilePicUrl) ?>" alt="<?= $name ?>"
                         loading="lazy"
                         onerror="this.src='<?= htmlspecialchars(generateInitialsAvatar($user['name'])) ?>'">
                </div>
                <div class="card-name-wrap">
                    <div class="card-school"><?= htmlspecialchars(SCHOOL_SHORT) ?></div>
                    <div class="card-name" title="<?= $name ?>"><?= $name ?></div>
                    <div class="card-class"><?= htmlspecialchars($className) ?></div>
                    <span class="role-badge" style="background:<?= $roleColor ?>;">
                        <?= $roleIcon ?> <?= ucfirst($role) ?>
                    </span>
                </div>
            </div>
            <div class="card-credentials">
                <div class="cred-row">
                    <span class="cred-label">Email</span>
                    <span class="cred-value" title="<?= $email ?>"><?= $email ?></span>
                </div>
                <div class="cred-row">
                    <span class="cred-label">Pass</span>
                    <span class="cred-value pass"><?= DEFAULT_PASSWORD_DISPLAY ?></span>
                </div>
            </div>
            <div class="card-footer">
                <span class="card-url" title="Visit portal">
                    🌐 <?= htmlspecialchars(str_replace('https://', '', PORTAL_BASE_URL)) ?>
                </span>
                <span class="gate-badge">
                    <i class="fas fa-gate"></i> Gate Access
                </span>
                <span class="card-id-badge">#<?= $userId ?></span>
            </div>
        </div>
        <div class="card-qr">
            <img src="<?= htmlspecialchars($qrUrl) ?>"
                 alt="Scan for gate access"
                 loading="lazy"
                 title="Scan at gate for <?= $name ?>">
            <div class="card-qr-label">
                <strong>🚪 Gate Access</strong><br>
                <span style="font-size:1.2mm;">Scan at gate • Never expires</span>
            </div>
        </div>
    </div>
    <?php endforeach; ?>

    <?php else: ?>
    <!-- SCREEN VIEW: Grouped by class -->
    <?php foreach ($groupedUsers as $className => $classUsers): ?>
    
    <div class="class-section" style="width:100%;">
        <div class="class-section-header">
            <div class="class-name">
                <i class="fas fa-users"></i>
                <span><?= htmlspecialchars($className) ?></span>
            </div>
            <span class="class-count"><?= count($classUsers) ?> card<?= count($classUsers) !== 1 ? 's' : '' ?></span>
        </div>
        
        <div style="display:flex;flex-wrap:wrap;gap:12px;padding:10px 0;">
        <?php foreach ($classUsers as $user):
            $email      = htmlspecialchars($user['email']);
            $name       = htmlspecialchars($user['name']);
            $role       = $user['role'];
            $userId     = (int)$user['id'];
            $roleIcon   = getRoleIcon($role);
            $roleColor  = getRoleBadgeColor($role);
            $stripeClass = in_array($role, ['admin', 'teacher', 'parent']) ? $role : '';
            $className  = $user['class_name'] ? getDisplayClassName($user['class_name']) : 'N/A';

            $profilePicUrl = getProfilePictureUrl($user['profile_picture'], $user['name']);
            $loginUrl = buildLoginURL($user['email']);
            
            // Gate QR encodes: USER_ID|EMAIL
            $gateData = buildGateQRData($userId, $user['email']);
            $qrUrl    = qrCodeUrl($gateData);
        ?>

        <div class="launch-card" data-id="<?= $userId ?>" data-role="<?= $role ?>" data-class="<?= htmlspecialchars($className) ?>" id="card-<?= $userId ?>">

            <div class="card-select no-print">
                <input type="checkbox" name="selected_users[]" value="<?= $userId ?>"
                       class="user-select-checkbox" onchange="updateSelection(this,<?= $userId ?>)">
            </div>

            <div class="card-stripe <?= $stripeClass ?>"></div>

            <div class="card-info">
                <div class="card-header">
                    <div class="card-avatar">
                        <img src="<?= htmlspecialchars($profilePicUrl) ?>" alt="<?= $name ?>"
                             loading="lazy"
                             onerror="this.src='<?= htmlspecialchars(generateInitialsAvatar($user['name'])) ?>'">
                    </div>
                    <div class="card-name-wrap">
                        <div class="card-school"><?= htmlspecialchars(SCHOOL_SHORT) ?></div>
                        <div class="card-name" title="<?= $name ?>"><?= $name ?></div>
                        <div class="card-class"><?= htmlspecialchars($className) ?></div>
                        <span class="role-badge" style="background:<?= $roleColor ?>;">
                            <?= $roleIcon ?> <?= ucfirst($role) ?>
                        </span>
                    </div>
                </div>

                <div class="card-credentials">
                    <div class="cred-row">
                        <span class="cred-label">Email</span>
                        <span class="cred-value" title="<?= $email ?>"><?= $email ?></span>
                    </div>
                    <div class="cred-row">
                        <span class="cred-label">Pass</span>
                        <span class="cred-value pass"><?= DEFAULT_PASSWORD_DISPLAY ?></span>
                    </div>
                </div>

                <div class="card-footer">
                    <span class="card-url" title="Visit portal">
                        🌐 <?= htmlspecialchars(str_replace('https://', '', PORTAL_BASE_URL)) ?>
                    </span>
                    <span class="gate-badge">
                        <i class="fas fa-gate"></i> Gate Access
                    </span>
                    <span class="card-id-badge">#<?= $userId ?></span>
                </div>
            </div>

            <div class="card-qr">
                <img src="<?= htmlspecialchars($qrUrl) ?>"
                     alt="Gate access QR"
                     loading="lazy"
                     title="Scan at gate for <?= $name ?>">
                <div class="card-qr-label">
                    <strong>🚪 Gate Access</strong><br>
                    <span style="font-size:1.2mm;">Scan at gate • Never expires</span>
                </div>
            </div>

        </div><!-- /.launch-card -->

        <?php endforeach; ?>
        </div>
    </div><!-- /.class-section -->

    <?php endforeach; ?>
    <?php endif; ?>

<?php endif; ?>

</div><!-- /.cards-grid -->

</div><!-- /.content-wrapper -->

<script>
let selectedUsers = new Set();

function updateSelection(checkbox, userId) {
    const card = document.getElementById('card-' + userId);
    if (checkbox.checked) { 
        selectedUsers.add(userId); 
        if (card) card.classList.add('selected'); 
    } else { 
        selectedUsers.delete(userId); 
        if (card) card.classList.remove('selected'); 
    }
    updateBulkActionsBar();
}

function toggleSelectAll(cb) {
    const checkboxes = document.querySelectorAll('.user-select-checkbox');
    const cards = document.querySelectorAll('.launch-card');
    checkboxes.forEach((chk, i) => {
        chk.checked = cb.checked;
        const uid = parseInt(chk.value);
        if (cb.checked) { 
            selectedUsers.add(uid); 
            if (cards[i]) cards[i].classList.add('selected'); 
        } else { 
            selectedUsers.delete(uid); 
            if (cards[i]) cards[i].classList.remove('selected'); 
        }
    });
    updateBulkActionsBar();
}

function updateBulkActionsBar() {
    const bulk  = document.getElementById('bulkActions');
    const count = document.getElementById('selectedCount');
    const sa    = document.getElementById('selectAll');
    const all   = document.querySelectorAll('.user-select-checkbox');

    if (count) count.textContent = selectedUsers.size;
    if (bulk) bulk.classList.toggle('hidden', selectedUsers.size === 0);
    if (sa) {
        sa.checked       = all.length > 0 && selectedUsers.size === all.length;
        sa.indeterminate = selectedUsers.size > 0 && selectedUsers.size < all.length;
    }
}

function clearSelection() {
    selectedUsers.clear();
    document.querySelectorAll('.user-select-checkbox').forEach(c => c.checked = false);
    document.querySelectorAll('.launch-card').forEach(c => c.classList.remove('selected'));
    updateBulkActionsBar();
}

function printSelected() {
    if (selectedUsers.size === 0) { 
        alert('⚠️ Please select at least one user to print.'); 
        return; 
    }
    const ids = Array.from(selectedUsers).join(',');
    let url = 'launchcards.php?print=selected&ids=' + ids;
    <?php if ($search): ?>
    url += '&search=<?= urlencode($search) ?>';
    <?php endif; ?>
    <?php if ($roleFilter): ?>
    url += '&role=<?= urlencode($roleFilter) ?>';
    <?php endif; ?>
    <?php if ($classFilter): ?>
    url += '&class=<?= urlencode($classFilter) ?>';
    <?php endif; ?>
    url += '&sort=<?= urlencode($sortBy) ?>';
    window.open(url, '_blank');
}

function printAll() {
    let url = 'launchcards.php?print=all';
    <?php if ($search): ?>
    url += '&search=<?= urlencode($search) ?>';
    <?php endif; ?>
    <?php if ($roleFilter): ?>
    url += '&role=<?= urlencode($roleFilter) ?>';
    <?php endif; ?>
    <?php if ($classFilter): ?>
    url += '&class=<?= urlencode($classFilter) ?>';
    <?php endif; ?>
    url += '&sort=<?= urlencode($sortBy) ?>';
    window.open(url, '_blank');
}

function triggerPrint() {
    setTimeout(function() { window.print(); }, 500);
}

(function () {
    const p = new URLSearchParams(window.location.search);
    if (p.get('regenerated') === 'success') {
        alert('✅ Launch cards are ready! QR codes are permanent and never expire.');
        window.history.replaceState({}, document.title, window.location.pathname);
    }
    
    <?php if ($isPrintView): ?>
    document.querySelectorAll('.launch-card').forEach(function(card) {
        card.style.display = 'flex';
        card.style.visibility = 'visible';
        card.style.opacity = '1';
    });
    window.addEventListener('load', function() {
        setTimeout(function() { triggerPrint(); }, 1000);
    });
    <?php endif; ?>
})();
</script>

</body>
</html>
<?php ob_end_flush(); ?>