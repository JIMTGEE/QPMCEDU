<?php
// /parent/includes/navbar.php
// Left sidebar navigation for all parent pages on QPMCEDU.com
// Usage: require_once $_SERVER['DOCUMENT_ROOT'] . '/parent/includes/navbar.php';
// Requires: $conn and $_SESSION['user_id'] to already be set before including.
// IMPORTANT: Each page that includes this must close </div> at the bottom
//            to close the #qpmc-content wrapper div this file opens.

// Fetch unread notification count
$_navbar_unread = 0;
if (isset($conn) && isset($_SESSION['user_id'])) {
    try {
        $stmt = $conn->prepare("
            SELECT COUNT(*) AS cnt
            FROM notifications
            WHERE (user_id = ? OR target_role = 'parent')
              AND is_read = 0
        ");
        $stmt->bind_param("i", $_SESSION['user_id']);
        $stmt->execute();
        $_navbar_unread = (int)($stmt->get_result()->fetch_assoc()['cnt'] ?? 0);
        $stmt->close();
    } catch (Exception $e) {
        $_navbar_unread = 0;
    }
}

// Active page detection
$_current_page = basename($_SERVER['PHP_SELF']);
if (!function_exists('navActive')) {
    function navActive(string $page): string {
        global $_current_page;
        return $_current_page === $page ? 'active' : '';
    }
}

// Parent display name from session
$_nav_name    = htmlspecialchars($_SESSION['user_name'] ?? 'Parent');
$_nav_initial = strtoupper(substr($_SESSION['user_name'] ?? 'P', 0, 1));
?>
<style>
    /* ── Variables ── */
    :root {
        --sb-width: 260px;
        --sb-bg: #1a1a2e;
        --sb-accent: #ff6b00;
        --sb-text: #b0b0c8;
        --sb-hover: rgba(255,107,0,0.13);
        --sb-active: #ff6b00;
        --sb-border: rgba(255,255,255,0.07);
    }

    body { margin: 0; overflow-x: hidden; }

    /* ── Layout wrapper ── */
    #qpmc-sidebar {
        width: var(--sb-width);
        min-height: 100vh;
        background: var(--sb-bg);
        position: fixed;
        top: 0; left: 0;
        z-index: 1040;
        display: flex;
        flex-direction: column;
        transition: transform 0.3s ease;
        overflow-y: auto;
        overflow-x: hidden;
    }

    /* All page content sits beside the sidebar */
    #qpmc-content {
        margin-left: var(--sb-width);
        min-height: 100vh;
        transition: margin-left 0.3s ease;
    }

    /* ── Logo ── */
    .sb-logo {
        padding: 1.4rem 1.25rem 1rem;
        border-bottom: 1px solid var(--sb-border);
        display: flex;
        align-items: center;
        gap: 0.75rem;
        text-decoration: none;
    }
    .sb-logo img { height: 36px; flex-shrink: 0; }
    .sb-logo .brand { line-height: 1.25; }
    .sb-logo .brand-name { font-size: 0.95rem; font-weight: 700; color: #fff; display: block; }
    .sb-logo .brand-sub  { font-size: 0.65rem; color: var(--sb-accent); text-transform: uppercase; letter-spacing: 0.08em; }

    /* ── Profile strip ── */
    .sb-profile {
        padding: 0.9rem 1.25rem;
        border-bottom: 1px solid var(--sb-border);
        display: flex;
        align-items: center;
        gap: 0.7rem;
    }
    .sb-avatar {
        width: 36px; height: 36px;
        background: var(--sb-accent);
        border-radius: 50%;
        display: flex; align-items: center; justify-content: center;
        font-weight: 700; font-size: 0.9rem; color: #fff;
        flex-shrink: 0;
    }
    .sb-profile .p-name { font-size: 0.82rem; font-weight: 600; color: #fff; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .sb-profile .p-role { font-size: 0.68rem; color: var(--sb-text); }

    /* ── Nav section label ── */
    .sb-label {
        font-size: 0.62rem;
        letter-spacing: 0.1em;
        text-transform: uppercase;
        color: rgba(255,255,255,0.28);
        padding: 1rem 1.25rem 0.3rem;
    }

    /* ── Nav links ── */
    .sb-nav { padding: 0.5rem 0.6rem; flex: 1; }

    .sb-nav a {
        display: flex;
        align-items: center;
        gap: 0.7rem;
        padding: 0.58rem 0.75rem;
        border-radius: 0.45rem;
        color: var(--sb-text);
        text-decoration: none;
        font-size: 0.86rem;
        font-weight: 500;
        transition: background 0.15s, color 0.15s;
        margin-bottom: 0.12rem;
    }
    .sb-nav a i { width: 17px; text-align: center; font-size: 0.85rem; flex-shrink: 0; }
    .sb-nav a:hover  { background: var(--sb-hover); color: #fff; }
    .sb-nav a.active { background: var(--sb-active); color: #fff; font-weight: 600; }

    /* Notification badge pill */
    .sb-badge {
        margin-left: auto;
        background: #fff;
        color: var(--sb-accent);
        font-size: 0.62rem;
        font-weight: 700;
        border-radius: 999px;
        padding: 0.1em 0.5em;
        min-width: 18px;
        text-align: center;
        line-height: 1.6;
    }

    /* ── Footer (logout) ── */
    .sb-footer {
        padding: 0.6rem;
        border-top: 1px solid var(--sb-border);
    }
    .sb-footer a {
        display: flex;
        align-items: center;
        gap: 0.7rem;
        padding: 0.58rem 0.75rem;
        border-radius: 0.45rem;
        color: #ff7070;
        text-decoration: none;
        font-size: 0.86rem;
        font-weight: 500;
        transition: background 0.15s;
    }
    .sb-footer a:hover { background: rgba(255,80,80,0.1); }

    /* ── Mobile topbar ── */
    #qpmc-topbar {
        display: none;
        align-items: center;
        justify-content: space-between;
        padding: 0.6rem 1rem;
        background: linear-gradient(90deg, #ff4500, #ff6b00);
        position: sticky;
        top: 0;
        z-index: 1039;
    }
    #qpmc-topbar .tb-brand { color: #fff; font-weight: 700; font-size: 0.95rem; }
    #qpmc-topbar .tb-bell  { color: #fff; position: relative; text-decoration: none; }
    #qpmc-topbar .tb-bell .tb-badge {
        position: absolute; top: -6px; right: -8px;
        background: #fff; color: var(--sb-accent);
        font-size: 0.6rem; font-weight: 700;
        border-radius: 999px; padding: 0.1em 0.4em;
        min-width: 16px; text-align: center;
    }

    /* ── Overlay behind open sidebar on mobile ── */
    #qpmc-overlay {
        display: none;
        position: fixed; inset: 0;
        background: rgba(0,0,0,0.45);
        z-index: 1039;
    }
    #qpmc-overlay.show { display: block; }

    /* ── Responsive: collapse sidebar on mobile ── */
    @media (max-width: 991px) {
        #qpmc-sidebar  { transform: translateX(-100%); }
        #qpmc-sidebar.open { transform: translateX(0); }
        #qpmc-content  { margin-left: 0; }
        #qpmc-topbar   { display: flex; }
    }
</style>

<!-- Mobile overlay (click to close sidebar) -->
<div id="qpmc-overlay" onclick="closeSidebar()"></div>

<!-- ═══════════════════════════════════
     LEFT SIDEBAR
     ═══════════════════════════════════ -->
<aside id="qpmc-sidebar">

    <!-- Logo -->
    <a class="sb-logo" href="/parent/dashboard.php">
        <img src="/assets/images/logo.png" alt="QPMCEDU" onerror="this.style.display='none'">
        <div class="brand">
            <span class="brand-name">QueensPa School</span>
            <span class="brand-sub">Parent Portal</span>
        </div>
    </a>

    <!-- Profile strip -->
    <div class="sb-profile">
        <div class="sb-avatar"><?= $_nav_initial ?></div>
        <div style="min-width:0;">
            <div class="p-name"><?= $_nav_name ?></div>
            <div class="p-role">Parent Account</div>
        </div>
    </div>

    <!-- Navigation links -->
    <nav class="sb-nav">
        <div class="sb-label">Main Menu</div>

        <a href="/parent/dashboard.php" class="<?= navActive('dashboard.php') ?>">
            <i class="fas fa-tachometer-alt"></i> Dashboard
        </a>
        <a href="/parent/fees.php" class="<?= navActive('fees.php') ?>">
            <i class="fas fa-money-bill-wave"></i> Fees
        </a>
        <a href="/parent/reports.php" class="<?= navActive('reports.php') ?>">
            <i class="fas fa-chart-line"></i> Reports
        </a>
        <a href="/parent/behavior_comments.php" class="<?= navActive('behavior_comments.php') ?>">
            <i class="fas fa-comment-dots"></i> Behaviour
        </a>
        <a href="/parent/attendance.php" class="<?= navActive('attendance.php') ?>">
            <i class="fas fa-calendar-alt"></i> Attendance
        </a>
        <a href="/parent/assignments.php" class="<?= navActive('assignments.php') ?>">
            <i class="fas fa-tasks"></i> Assignments
        </a>
        <a href="/parent/exams.php" class="<?= navActive('exams.php') ?>">
            <i class="fas fa-book"></i> Exams
        </a>
        <a href="/parent/library.php" class="<?= navActive('library.php') ?>">
            <i class="fas fa-book-open"></i> Library
        </a>

        <div class="sb-label" style="margin-top:0.5rem;">Account</div>

        <a href="/parent/profile.php" class="<?= navActive('profile.php') ?>">
            <i class="fas fa-user"></i> My Profile
        </a>
        <a href="/parent/notifications.php" class="<?= navActive('notifications.php') ?>">
            <i class="fas fa-bell"></i> Notifications
            <?php if ($_navbar_unread > 0): ?>
                <span class="sb-badge"><?= $_navbar_unread > 99 ? '99+' : $_navbar_unread ?></span>
            <?php endif; ?>
        </a>
    </nav>

    <!-- Logout -->
    <div class="sb-footer">
        <a href="/logout.php">
            <i class="fas fa-sign-out-alt"></i> Logout
        </a>
    </div>

</aside>

<!-- ═══════════════════════════════════
     MOBILE TOP BAR (hamburger + bell)
     ═══════════════════════════════════ -->
<div id="qpmc-topbar">
    <div class="d-flex align-items-center gap-2">
        <button onclick="openSidebar()"
                style="background:none;border:none;color:#fff;font-size:1.2rem;padding:0;cursor:pointer;">
            <i class="fas fa-bars"></i>
        </button>
        <span class="tb-brand">QueensPa School</span>
    </div>
    <a href="/parent/notifications.php" class="tb-bell">
        <i class="fas fa-bell fa-lg"></i>
        <?php if ($_navbar_unread > 0): ?>
            <span class="tb-badge"><?= $_navbar_unread > 99 ? '99+' : $_navbar_unread ?></span>
        <?php endif; ?>
    </a>
</div>

<!-- ═══════════════════════════════════
     PAGE CONTENT WRAPPER OPENS HERE
     Every page MUST close with: </div><!-- end #qpmc-content -->
     ═══════════════════════════════════ -->
<div id="qpmc-content">

<script>
function openSidebar() {
    document.getElementById('qpmc-sidebar').classList.add('open');
    document.getElementById('qpmc-overlay').classList.add('show');
    document.body.style.overflow = 'hidden';
}
function closeSidebar() {
    document.getElementById('qpmc-sidebar').classList.remove('open');
    document.getElementById('qpmc-overlay').classList.remove('show');
    document.body.style.overflow = '';
}
function markNavRead(id) {
    fetch('/parent/mark_notification.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'id=' + encodeURIComponent(id)
    }).catch(() => {});
}
</script>