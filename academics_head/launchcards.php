<?php
session_start();

// Restrict to admins only
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    header("Location: ../login.php");
    exit();
}

// Include database connection and header
require_once($_SERVER['DOCUMENT_ROOT'] . '/config/db.php');
include $_SERVER['DOCUMENT_ROOT'] . '/templates/admin_header.php';
require_once($_SERVER['DOCUMENT_ROOT'] . '/functions/log_activity.php');

// Fetch classes for the dropdown
$class_query = "SELECT id, class_name FROM classes ORDER BY class_name ASC";
$class_result = $conn->query($class_query);
$classes = $class_result ? $class_result->fetch_all(MYSQLI_ASSOC) : [];

// Fetch users based on selected class (if any)
$class_id = isset($_GET['class_id']) ? (int)$_GET['class_id'] : 0;
$query = "SELECT u.id, u.name, u.email, c.class_name 
          FROM users u 
          LEFT JOIN student_classes sc ON u.id = sc.user_id 
          LEFT JOIN classes c ON sc.class_id = c.id 
          WHERE sc.class_id = ? OR ? = 0 
          ORDER BY u.name ASC";
$stmt = $conn->prepare($query);
$stmt->bind_param("ii", $class_id, $class_id);
$stmt->execute();
$result = $stmt->get_result();
$users = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
?>

<div class="container-fluid mt-4 px-lg-5">
    <!-- Header Section -->
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center mb-4">
        <div class="mb-3 mb-md-0">
            <h1 class="h3 fw-bold text-gradient-orange">🎴 Generate Login Cards</h1>
            <p class="text-muted mb-0">Select users to generate printable login cards for classroom distribution</p>
        </div>
        <a href="user_management.php" class="btn btn-outline-primary d-flex align-items-center">
            <i class="bi bi-arrow-left me-2"></i> Back to User Management
        </a>
    </div>

    <!-- Info Alert -->
    <div class="alert alert-info border-start-4 border-start-info bg-light-info-subtle mb-4">
        <div class="d-flex align-items-center">
            <i class="bi bi-info-circle-fill text-info fs-5 me-3"></i>
            <div>
                <p class="mb-0"><strong>Printing Instructions:</strong> Select cards, then click 'Print Selected'. Cards are optimized for A4 paper with 2 cards per row.</p>
            </div>
        </div>
    </div>

    <!-- Controls Section -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body">
            <div class="row g-3 align-items-center">
                <!-- Class Filter -->
                <div class="col-md-6">
                    <label for="class_id" class="form-label fw-semibold">Filter by Class:</label>
                    <form method="GET" action="" class="d-flex align-items-center">
                        <select name="class_id" id="class_id" class="form-select form-select-lg" onchange="this.form.submit()">
                            <option value="0">All Classes</option>
                            <?php foreach ($classes as $class): ?>
                                <option value="<?= htmlspecialchars($class['id']) ?>" <?= $class_id == $class['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($class['class_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </form>
                </div>
                
                <!-- Selection Controls -->
                <div class="col-md-6">
                    <div class="d-flex align-items-center justify-content-end">
                        <div class="btn-group me-3">
                            <button type="button" class="btn btn-outline-secondary" id="selectAll">
                                <i class="bi bi-check-all me-1"></i> Select All
                            </button>
                            <button type="button" class="btn btn-outline-secondary" id="deselectAll">
                                <i class="bi bi-x-circle me-1"></i> Deselect All
                            </button>
                        </div>
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" role="switch" id="nightModeToggle">
                            <label class="form-check-label" for="nightModeToggle">Night Mode</label>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- User Cards Grid -->
    <form id="printForm">
        <?php if (!empty($users)): ?>
            <div class="d-flex justify-content-between align-items-center mb-3">
                <span class="badge bg-primary-subtle text-primary fs-6">
                    <i class="bi bi-people-fill me-1"></i> <?= count($users) ?> User(s) Found
                </span>
                <div id="selectedCount" class="text-success fw-semibold">
                    <i class="bi bi-check-circle-fill me-1"></i> 0 selected
                </div>
            </div>
            
            <div class="row row-cols-1 row-cols-md-2 row-cols-lg-3 g-4" id="card-container">
                <?php foreach ($users as $user): ?>
                    <div class="col d-flex justify-content-center">
                        <div class="card login-card h-100 border-0 shadow-sm" style="width: 9.2cm;">
                            <!-- Card Header with Selection -->
                            <div class="card-header bg-white border-bottom-0 pt-3 pb-2">
                                <div class="form-check form-switch">
                                    <input type="checkbox" class="form-check-input print-checkbox" 
                                           name="print_users[]" 
                                           value="<?= htmlspecialchars($user['id']) ?>" 
                                           id="user_<?= htmlspecialchars($user['id']) ?>">
                                    <label class="form-check-label fw-medium" for="user_<?= htmlspecialchars($user['id']) ?>">
                                        Select for Printing
                                    </label>
                                </div>
                            </div>
                            
                            <!-- Card Body with User Info -->
                            <div class="card-body text-center p-4 gradient-bg">
                                <!-- User Name -->
                                <div class="mb-3">
                                    <h5 class="card-title mb-0 fw-bold text-white fs-4">
                                        <?= htmlspecialchars($user['name']) ?>
                                    </h5>
                                </div>
                                
                                <!-- Login Details -->
                                <div class="bg-white bg-opacity-10 rounded-3 p-3 mb-3">
                                    <div class="row g-2 text-start">
                                        <div class="col-12">
                                            <div class="d-flex align-items-center mb-2">
                                                <i class="bi bi-envelope-fill text-white me-2 fs-6"></i>
                                                <span class="text-white fw-medium">Email:</span>
                                            </div>
                                            <div class="bg-white bg-opacity-20 rounded-2 p-2">
                                                <code class="text-white fs-6"><?= htmlspecialchars($user['email']) ?></code>
                                            </div>
                                        </div>
                                        <div class="col-12">
                                            <div class="d-flex align-items-center mb-2 mt-2">
                                                <i class="bi bi-key-fill text-white me-2 fs-6"></i>
                                                <span class="text-white fw-medium">Temporary Password:</span>
                                            </div>
                                            <div class="bg-white bg-opacity-20 rounded-2 p-2">
                                                <code class="text-white fs-6">12345678</code>
                                            </div>
                                        </div>
                                        <div class="col-12">
                                            <div class="d-flex align-items-center mb-2 mt-2">
                                                <i class="bi bi-people-fill text-white me-2 fs-6"></i>
                                                <span class="text-white fw-medium">Class:</span>
                                            </div>
                                            <div class="bg-white bg-opacity-20 rounded-2 p-2">
                                                <code class="text-white fs-6"><?= htmlspecialchars($user['class_name'] ?? 'N/A') ?></code>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Security Note -->
                                <div class="alert alert-warning alert-dismissible fade show py-2 mb-0" role="alert">
                                    <small class="d-flex align-items-center">
                                        <i class="bi bi-shield-exclamation me-2"></i>
                                        <span>Change password after first login for security</span>
                                    </small>
                                </div>
                            </div>
                            
                            <!-- Card Footer -->
                            <div class="card-footer bg-dark text-white text-center py-2">
                                <div class="row g-1">
                                    <div class="col-6 border-end border-secondary">
                                        <small class="fw-medium">ID: <?= htmlspecialchars($user['id']) ?></small>
                                    </div>
                                    <div class="col-6">
                                        <small class="fw-medium">qpmsedu.com</small>
                                    </div>
                                    <div class="col-12 mt-1">
                                        <small><i class="bi bi-telephone-fill me-1"></i> 0531475205</small>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            
            <!-- Print Button -->
            <div class="text-center mt-5 pt-4 border-top">
                <button type="button" class="btn btn-success btn-lg px-5 py-3 fw-bold" id="printButton">
                    <i class="bi bi-printer-fill me-2"></i> Print Selected Cards
                </button>
                <p class="text-muted mt-2">
                    <small><i class="bi bi-info-circle me-1"></i> Cards will be arranged 2 per row on A4 paper</small>
                </p>
            </div>
            
        <?php else: ?>
            <!-- Empty State -->
            <div class="text-center py-5 my-5">
                <div class="mb-4">
                    <i class="bi bi-person-x display-1 text-muted"></i>
                </div>
                <h3 class="h4 text-muted mb-3">No Users Found</h3>
                <p class="text-muted mb-4">No users available in the selected class. Try selecting a different class.</p>
                <a href="?class_id=0" class="btn btn-outline-primary">
                    <i class="bi bi-arrow-repeat me-1"></i> View All Users
                </a>
            </div>
        <?php endif; ?>
    </form>
</div>

<?php include $_SERVER['DOCUMENT_ROOT'] . '/templates/admin_footer.php'; ?>

<style>
    :root {
        --gradient-orange-start: #ff6b00;
        --gradient-orange-end: #ff4500;
        --card-width: 9.2cm;
        --card-height: 5.8cm;
    }

    .text-gradient-orange {
        background: linear-gradient(135deg, var(--gradient-orange-start), var(--gradient-orange-end));
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
        background-clip: text;
    }

    .gradient-bg {
        background: linear-gradient(135deg, var(--gradient-orange-start), var(--gradient-orange-end));
        border-radius: 12px;
    }

    .login-card {
        transition: all 0.3s ease;
        border-radius: 12px;
        overflow: hidden;
    }

    .login-card:hover {
        transform: translateY(-4px);
        box-shadow: 0 8px 25px rgba(255, 107, 0, 0.15) !important;
    }

    .login-card .form-check-input:checked {
        background-color: #198754;
        border-color: #198754;
    }

    /* Night Mode */
    body.night-mode {
        background-color: #1a1d28;
        color: #e9ecef;
    }

    .night-mode .card {
        background-color: #2d303e;
        border-color: #3d404e;
    }

    .night-mode .card-header {
        background-color: #2d303e;
    }

    .night-mode .form-select,
    .night-mode .form-control {
        background-color: #3d404e;
        color: #e9ecef;
        border-color: #4d505e;
    }

    .night-mode .alert-info {
        background-color: rgba(13, 110, 253, 0.1);
        border-color: rgba(13, 110, 253, 0.3);
    }

    /* Print Styles - Enhanced for Perfect Printing */
    @media print {
        @page {
            size: A4 portrait;
            margin: 0.8cm;
        }

        body {
            background: white !important;
            color: black !important;
            font-size: 11pt;
            line-height: 1.4;
        }

        /* Hide all unnecessary elements */
        .container-fluid > :not(#card-container):not(.print-button-container),
        .d-flex,
        .btn,
        .form-check,
        .alert,
        .badge,
        .text-muted,
        .mt-5,
        .pt-4,
        .border-top,
        footer,
        header,
        .card-header,
        .alert-warning {
            display: none !important;
        }

        /* Card container for print */
        #card-container {
            display: grid !important;
            grid-template-columns: repeat(2, 1fr) !important;
            gap: 0.5cm !important;
            margin: 0 !important;
            padding: 0 !important;
            width: 100% !important;
        }

        /* Individual cards */
        .login-card {
            width: var(--card-width) !important;
            height: var(--card-height) !important;
            min-height: var(--card-height) !important;
            margin: 0 !important;
            page-break-inside: avoid !important;
            break-inside: avoid !important;
            border: 1px solid #000 !important;
            box-shadow: none !important;
            border-radius: 8px !important;
            position: relative;
        }

        /* Hide unselected cards */
        .col:not(:has(.print-checkbox:checked)) {
            display: none !important;
        }

        /* Card body adjustments */
        .card-body {
            padding: 0.4cm !important;
            background: linear-gradient(to bottom, #ff6b00, #ff4500) !important;
            color: white !important;
            border-radius: 8px 8px 0 0 !important;
        }

        .card-title {
            font-size: 14pt !important;
            font-weight: bold !important;
            margin-bottom: 0.2cm !important;
        }

        .bg-white.bg-opacity-10 {
            background: rgba(255, 255, 255, 0.15) !important;
            padding: 0.3cm !important;
            margin-bottom: 0.2cm !important;
            border-radius: 6px !important;
        }

        code {
            font-family: 'Courier New', monospace !important;
            font-size: 10pt !important;
            color: white !important;
            background: transparent !important;
        }

        .card-footer {
            background: #000 !important;
            color: white !important;
            padding: 0.2cm !important;
            border-radius: 0 0 8px 8px !important;
            font-size: 8pt !important;
        }

        /* Ensure proper spacing */
        .row.g-2.text-start > div {
            margin-bottom: 0.15cm !important;
        }

        /* Watermark protection for printed cards */
        .login-card::after {
            content: "QPMS Login Card - qpmsedu.com";
            position: absolute;
            bottom: 0.1cm;
            right: 0.3cm;
            font-size: 7pt;
            color: rgba(255, 255, 255, 0.3);
            font-style: italic;
        }
    }

    /* Responsive adjustments */
    @media (max-width: 1200px) {
        .login-card {
            width: 100% !important;
            max-width: 9.2cm;
        }
    }

    @media (max-width: 768px) {
        #card-container {
            grid-template-columns: 1fr !important;
        }
        
        .login-card {
            max-width: 100%;
        }
    }
</style>

<script>
    document.addEventListener('DOMContentLoaded', () => {
        // Night mode toggle
        const nightModeToggle = document.getElementById('nightModeToggle');
        const isNightMode = localStorage.getItem('nightMode') === 'true';
        
        if (isNightMode) {
            document.body.classList.add('night-mode');
            nightModeToggle.checked = true;
        }

        nightModeToggle.addEventListener('change', () => {
            document.body.classList.toggle('night-mode');
            localStorage.setItem('nightMode', document.body.classList.contains('night-mode'));
        });

        // Selection logic
        const printButton = document.getElementById('printButton');
        const checkboxes = document.querySelectorAll('.print-checkbox');
        const selectAll = document.getElementById('selectAll');
        const deselectAll = document.getElementById('deselectAll');
        const selectedCount = document.getElementById('selectedCount');

        // Update selected count
        function updateSelectedCount() {
            const checkedCount = Array.from(checkboxes).filter(cb => cb.checked).length;
            selectedCount.innerHTML = `
                <i class="bi bi-check-circle-fill me-1"></i> 
                ${checkedCount} selected
            `;
            
            if (checkedCount > 0) {
                selectedCount.classList.remove('text-muted');
                selectedCount.classList.add('text-success');
            } else {
                selectedCount.classList.remove('text-success');
                selectedCount.classList.add('text-muted');
            }
        }

        // Print button functionality
        printButton.addEventListener('click', () => {
            const anyChecked = Array.from(checkboxes).some(checkbox => checkbox.checked);
            if (!anyChecked) {
                // Show Bootstrap toast notification
                const toast = new bootstrap.Toast(document.getElementById('selectToast'), {
                    autohide: true,
                    delay: 3000
                });
                toast.show();
                return;
            }
            window.print();
        });

        // Select all functionality
        selectAll.addEventListener('click', () => {
            checkboxes.forEach(checkbox => checkbox.checked = true);
            updateSelectedCount();
        });

        // Deselect all functionality
        deselectAll.addEventListener('click', () => {
            checkboxes.forEach(checkbox => checkbox.checked = false);
            updateSelectedCount();
        });

        // Update count when individual checkboxes change
        checkboxes.forEach(checkbox => {
            checkbox.addEventListener('change', updateSelectedCount);
        });

        // Initialize count
        updateSelectedCount();
    });
</script>

<!-- Toast Notification (added dynamically) -->
<div class="position-fixed bottom-0 end-0 p-3" style="z-index: 11">
    <div id="selectToast" class="toast align-items-center text-bg-warning border-0" role="alert" aria-live="assertive" aria-atomic="true">
        <div class="d-flex">
            <div class="toast-body">
                <i class="bi bi-exclamation-triangle-fill me-2"></i>
                Please select at least one user to print
            </div>
            <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
        </div>
    </div>
</div>