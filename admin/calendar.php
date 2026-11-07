<?php
session_start();

// Check if the user is logged in and has an admin role
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    header("Location: ../login.php");
    exit();
}

// Include database connection and header
require_once($_SERVER['DOCUMENT_ROOT'] . '/config/db.php');
include $_SERVER['DOCUMENT_ROOT'] . '/templates/admin_header.php';
require_once($_SERVER['DOCUMENT_ROOT'] . '/functions/log_activity.php');

if (!$conn) {
    die("Connection failed: " . mysqli_connect_error());
}

// Helper functions
function setFlashMessage($type, $message) {
    $_SESSION['calendar_flash'] = ['type' => $type, 'message' => $message];
}

function getFlashMessage() {
    if (isset($_SESSION['calendar_flash'])) {
        $flash = $_SESSION['calendar_flash'];
        unset($_SESSION['calendar_flash']);
        return $flash;
    }
    return null;
}

// CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// --- AJAX handler: fetch a single record for editing ---
if (isset($_GET['action']) && $_GET['action'] === 'get_entry' && isset($_GET['id'])) {
    header('Content-Type: application/json');
    $id = intval($_GET['id']);
    $stmt = $conn->prepare("SELECT id, year, term_name, start_date, end_date FROM academic_calendar WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        echo json_encode(['success' => true, 'data' => $row]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Record not found']);
    }
    exit();
}

// --- POST handling: Add, Edit, Delete ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['sub_action'])) {
    // CSRF check
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        setFlashMessage('danger', 'Invalid security token. Please refresh and try again.');
        header("Location: " . $_SERVER['PHP_SELF']);
        exit();
    }

    $sub_action = $_POST['sub_action'];
    $redirect = true;

    // ----- ADD (supports both new year or existing year) -----
    if ($sub_action === 'add') {
        $year_mode = $_POST['year_mode'] ?? 'existing'; // 'existing' or 'new'
        $year = '';
        if ($year_mode === 'existing') {
            $year = trim($_POST['existing_year'] ?? '');
        } else {
            $year = trim($_POST['new_year'] ?? '');
        }
        $term_name = trim($_POST['term_name'] ?? '');
        $start_date = $_POST['start_date'] ?? '';
        $end_date = $_POST['end_date'] ?? '';

        $errors = [];
        if (empty($year)) $errors[] = "Academic year is required.";
        if (strlen($year) > 9) $errors[] = "Year must be at most 9 characters (e.g., 2024-2025).";
        if (empty($term_name)) $errors[] = "Term name is required.";
        if (empty($start_date)) $errors[] = "Start date is required.";
        if (empty($end_date)) $errors[] = "End date is required.";
        if ($start_date && $end_date && $start_date > $end_date) $errors[] = "Start date cannot be after end date.";

        if (empty($errors)) {
            $stmt = $conn->prepare("INSERT INTO academic_calendar (year, term_name, start_date, end_date) VALUES (?, ?, ?, ?)");
            $stmt->bind_param("ssss", $year, $term_name, $start_date, $end_date);
            if ($stmt->execute()) {
                log_activity($conn, $_SESSION['user_id'], "Added academic calendar entry: $term_name ($year)");
                setFlashMessage('success', 'Academic calendar entry added successfully!');
            } else {
                setFlashMessage('danger', 'Database error: ' . $stmt->error);
            }
            $stmt->close();
        } else {
            setFlashMessage('danger', implode('<br>', $errors));
        }
    }

    // ----- EDIT -----
    elseif ($sub_action === 'edit') {
        $id = intval($_POST['entry_id'] ?? 0);
        $year_mode = $_POST['year_mode'] ?? 'existing';
        $year = '';
        if ($year_mode === 'existing') {
            $year = trim($_POST['existing_year'] ?? '');
        } else {
            $year = trim($_POST['new_year'] ?? '');
        }
        $term_name = trim($_POST['term_name'] ?? '');
        $start_date = $_POST['start_date'] ?? '';
        $end_date = $_POST['end_date'] ?? '';

        $errors = [];
        if ($id <= 0) $errors[] = "Invalid entry ID.";
        if (empty($year)) $errors[] = "Academic year is required.";
        if (strlen($year) > 9) $errors[] = "Year must be at most 9 characters.";
        if (empty($term_name)) $errors[] = "Term name is required.";
        if (empty($start_date)) $errors[] = "Start date is required.";
        if (empty($end_date)) $errors[] = "End date is required.";
        if ($start_date && $end_date && $start_date > $end_date) $errors[] = "Start date cannot be after end date.";

        if (empty($errors)) {
            $stmt = $conn->prepare("UPDATE academic_calendar SET year = ?, term_name = ?, start_date = ?, end_date = ? WHERE id = ?");
            $stmt->bind_param("ssssi", $year, $term_name, $start_date, $end_date, $id);
            if ($stmt->execute()) {
                log_activity($conn, $_SESSION['user_id'], "Updated academic calendar entry ID: $id");
                setFlashMessage('success', 'Academic calendar entry updated successfully!');
            } else {
                setFlashMessage('danger', 'Database error: ' . $stmt->error);
            }
            $stmt->close();
        } else {
            setFlashMessage('danger', implode('<br>', $errors));
        }
    }

    // ----- DELETE -----
    elseif ($sub_action === 'delete') {
        $id = intval($_POST['entry_id'] ?? 0);
        if ($id > 0) {
            $stmt = $conn->prepare("DELETE FROM academic_calendar WHERE id = ?");
            $stmt->bind_param("i", $id);
            if ($stmt->execute()) {
                log_activity($conn, $_SESSION['user_id'], "Deleted academic calendar entry ID: $id");
                setFlashMessage('success', 'Academic calendar entry deleted successfully!');
            } else {
                setFlashMessage('danger', 'Delete failed: ' . $stmt->error);
            }
            $stmt->close();
        } else {
            setFlashMessage('danger', 'Invalid entry ID for deletion.');
        }
    }

    if ($redirect) {
        header("Location: " . $_SERVER['PHP_SELF']);
        exit();
    }
}

// --- Fetch all entries for display, plus distinct existing years for dropdown ---
$entries_query = "SELECT * FROM academic_calendar ORDER BY year DESC, start_date DESC";
$entries_result = mysqli_query($conn, $entries_query);
$entries = [];
while ($row = mysqli_fetch_assoc($entries_result)) {
    $entries[] = $row;
}

// Get distinct years for the "existing year" dropdown
$years_query = "SELECT DISTINCT year FROM academic_calendar ORDER BY year DESC";
$years_result = mysqli_query($conn, $years_query);
$existing_years = [];
while ($row = mysqli_fetch_assoc($years_result)) {
    $existing_years[] = $row['year'];
}

// Get distinct term names for datalist (suggestions)
$terms_query = "SELECT DISTINCT term_name FROM academic_calendar ORDER BY term_name";
$terms_result = mysqli_query($conn, $terms_query);
$term_suggestions = [];
while ($row = mysqli_fetch_assoc($terms_result)) {
    $term_suggestions[] = htmlspecialchars($row['term_name']);
}

$flash = getFlashMessage();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Academic Calendar Management | Enhanced</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background-color: #ffffff; color: #000000; }
        .card-header { background-color: #ff4500; color: #ffffff; }
        .table thead { background-color: #ff4500; color: #ffffff; }
        .table-hover tbody tr:hover { background-color: #f5f5f5; }
        .btn-primary { background-color: #ff4500; border-color: #ff4500; }
        .btn-primary:hover { background-color: #e03e00; border-color: #e03e00; }
        .btn-warning { background-color: #ffff00; border-color: #ffff00; color: #000000; }
        .btn-warning:hover { background-color: #e6e600; border-color: #e6e600; }
        .btn-danger { background-color: #dc3545; border-color: #dc3545; }
        .card { box-shadow: 0 4px 8px rgba(0,0,0,0.1); }
        .night-mode { background-color: #000000; color: #ffffff; }
        .night-mode .card { background-color: #333333; color: #ffffff; }
        .night-mode .table thead { background-color: #ff4500; color: #ffffff; }
        .night-mode .table tbody { background-color: #333333; color: #ffffff; }
        .night-mode .table tbody tr:hover { background-color: #444444; }
        .night-mode .modal-content { background-color: #2c2c2c; color: #ffffff; }
        .night-mode .modal-header, .night-mode .modal-footer { border-color: #555555; }
        .night-mode .form-control, .night-mode .form-label, .night-mode .form-select {
            background-color: #444; color: #fff; border-color: #666;
        }
        .action-btns { white-space: nowrap; }
        .action-btns .btn { margin: 0 2px; }
        .year-toggle-group { margin-bottom: 1rem; }
    </style>
</head>
<body>
<div class="container mt-4">
    <h1 class="mb-4 text-center">📅 Academic Calendar Management</h1>

    <?php if ($flash): ?>
        <div class="alert alert-<?php echo htmlspecialchars($flash['type']); ?> alert-dismissible fade show" role="alert">
            <?php echo htmlspecialchars($flash['message']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="card mb-4">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h3 class="mb-0">Calendar Overview</h3>
            <button type="button" class="btn btn-light" data-bs-toggle="modal" data-bs-target="#calendarEntryModal" onclick="prepareAddModal()">
                ➕ Add New Academic Year/Term
            </button>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-striped table-hover table-bordered" id="calendarTable">
                    <thead>
                        <tr><th>Year</th><th>Term</th><th>Start Date</th><th>End Date</th><th>Actions</th></tr>
                    </thead>
                    <tbody>
                        <?php if (count($entries) > 0): ?>
                            <?php foreach ($entries as $entry): ?>
                                <tr data-id="<?php echo $entry['id']; ?>">
                                    <td><?php echo htmlspecialchars($entry['year']); ?></td>
                                    <td><?php echo htmlspecialchars($entry['term_name']); ?></td>
                                    <td><?php echo htmlspecialchars($entry['start_date']); ?></td>
                                    <td><?php echo htmlspecialchars($entry['end_date']); ?></td>
                                    <td class="action-btns">
                                        <button class="btn btn-warning btn-sm edit-entry" data-id="<?php echo $entry['id']; ?>">✏️ Edit</button>
                                        <button class="btn btn-danger btn-sm delete-entry" data-id="<?php echo $entry['id']; ?>" data-name="<?php echo htmlspecialchars($entry['term_name'] . ' (' . $entry['year'] . ')'); ?>">🗑️ Delete</button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="5" class="text-center">No academic calendar entries found. Click "Add New" to create one.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Modal for Add/Edit Entry -->
<div class="modal fade" id="calendarEntryModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="" id="calendarForm">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalTitle">Add New Academic Term</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="sub_action" id="sub_action" value="add">
                    <input type="hidden" name="entry_id" id="entry_id" value="0">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">

                    <!-- Year selection: existing or new -->
                    <div class="mb-3 year-toggle-group">
                        <label class="form-label fw-bold">Academic Year <span class="text-danger">*</span></label>
                        <div class="form-check form-check-inline">
                            <input class="form-check-input" type="radio" name="year_mode" id="year_mode_existing" value="existing" checked>
                            <label class="form-check-label" for="year_mode_existing">Select existing year</label>
                        </div>
                        <div class="form-check form-check-inline">
                            <input class="form-check-input" type="radio" name="year_mode" id="year_mode_new" value="new">
                            <label class="form-check-label" for="year_mode_new">Enter new year</label>
                        </div>
                    </div>
                    <div class="mb-3" id="existing_year_div">
                        <label for="existing_year" class="form-label">Existing Year</label>
                        <select class="form-select" id="existing_year" name="existing_year">
                            <option value="">-- Choose a year --</option>
                            <?php foreach ($existing_years as $y): ?>
                                <option value="<?php echo htmlspecialchars($y); ?>"><?php echo htmlspecialchars($y); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3 d-none" id="new_year_div">
                        <label for="new_year" class="form-label">New Year (format: YYYY-YYYY, max 9 chars)</label>
                        <input type="text" class="form-control" id="new_year" name="new_year" placeholder="e.g., 2025-2026" maxlength="9">
                    </div>

                    <!-- Term name with datalist for suggestions -->
                    <div class="mb-3">
                        <label for="term_name" class="form-label">Term Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="term_name" name="term_name" list="term_suggestions" placeholder="e.g., Term 1, Michaelmas" required>
                        <datalist id="term_suggestions">
                            <?php foreach ($term_suggestions as $term): ?>
                                <option value="<?php echo $term; ?>">
                            <?php endforeach; ?>
                        </datalist>
                    </div>

                    <div class="mb-3">
                        <label for="start_date" class="form-label">Start Date <span class="text-danger">*</span></label>
                        <input type="date" class="form-control" id="start_date" name="start_date" required>
                    </div>
                    <div class="mb-3">
                        <label for="end_date" class="form-label">End Date <span class="text-danger">*</span></label>
                        <input type="date" class="form-control" id="end_date" name="end_date" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Entry</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Delete Confirmation Modal -->
<div class="modal fade" id="deleteConfirmModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Confirm Deletion</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                Are you sure you want to delete "<span id="deleteItemName"></span>"?
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <form method="POST" action="" id="deleteForm">
                    <input type="hidden" name="sub_action" value="delete">
                    <input type="hidden" name="entry_id" id="delete_entry_id" value="">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                    <button type="submit" class="btn btn-danger">Delete Permanently</button>
                </form>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // Night mode toggle (preserved from original)
    (function() {
        const toggleButton = document.createElement('button');
        toggleButton.textContent = '🌙 Night Mode';
        toggleButton.className = 'btn btn-secondary position-fixed top-0 end-0 m-2';
        toggleButton.style.zIndex = '1050';
        document.body.appendChild(toggleButton);
        const isNightMode = localStorage.getItem('nightMode') === 'true';
        if (isNightMode) {
            document.body.classList.add('night-mode');
            toggleButton.textContent = '☀️ Day Mode';
        }
        toggleButton.addEventListener('click', () => {
            document.body.classList.toggle('night-mode');
            const isNight = document.body.classList.contains('night-mode');
            localStorage.setItem('nightMode', isNight);
            toggleButton.textContent = isNight ? '☀️ Day Mode' : '🌙 Night Mode';
        });
    })();

    // Year mode toggle (existing vs new year)
    const existingYearDiv = document.getElementById('existing_year_div');
    const newYearDiv = document.getElementById('new_year_div');
    const radioExisting = document.getElementById('year_mode_existing');
    const radioNew = document.getElementById('year_mode_new');
    const existingYearSelect = document.getElementById('existing_year');
    const newYearInput = document.getElementById('new_year');

    function toggleYearFields() {
        if (radioExisting.checked) {
            existingYearDiv.classList.remove('d-none');
            newYearDiv.classList.add('d-none');
            existingYearSelect.required = true;
            newYearInput.required = false;
            newYearInput.value = '';
        } else {
            existingYearDiv.classList.add('d-none');
            newYearDiv.classList.remove('d-none');
            existingYearSelect.required = false;
            existingYearSelect.value = '';
            newYearInput.required = true;
        }
    }
    radioExisting.addEventListener('change', toggleYearFields);
    radioNew.addEventListener('change', toggleYearFields);

    // Modals
    let calendarModal = new bootstrap.Modal(document.getElementById('calendarEntryModal'));
    let deleteModal = new bootstrap.Modal(document.getElementById('deleteConfirmModal'));

    // Prepare Add Modal
    window.prepareAddModal = function() {
        document.getElementById('modalTitle').innerText = 'Add New Academic Term';
        document.getElementById('sub_action').value = 'add';
        document.getElementById('entry_id').value = '0';
        document.getElementById('term_name').value = '';
        document.getElementById('start_date').value = '';
        document.getElementById('end_date').value = '';
        // Reset year mode to "existing"
        radioExisting.checked = true;
        toggleYearFields();
        existingYearSelect.value = '';
        newYearInput.value = '';
        // Remove any validation styles
        document.querySelectorAll('.is-invalid').forEach(el => el.classList.remove('is-invalid'));
    };

    // Edit: fetch via AJAX
    document.querySelectorAll('.edit-entry').forEach(button => {
        button.addEventListener('click', async function(e) {
            const id = this.getAttribute('data-id');
            try {
                const response = await fetch(`?action=get_entry&id=${id}`);
                const result = await response.json();
                if (result.success) {
                    const data = result.data;
                    document.getElementById('modalTitle').innerText = 'Edit Academic Term';
                    document.getElementById('sub_action').value = 'edit';
                    document.getElementById('entry_id').value = data.id;
                    document.getElementById('term_name').value = data.term_name;
                    document.getElementById('start_date').value = data.start_date;
                    document.getElementById('end_date').value = data.end_date;
                    // Determine if the year exists in the dropdown
                    let yearExists = false;
                    const selectEl = existingYearSelect;
                    for (let i = 0; i < selectEl.options.length; i++) {
                        if (selectEl.options[i].value === data.year) {
                            yearExists = true;
                            break;
                        }
                    }
                    if (yearExists) {
                        radioExisting.checked = true;
                        existingYearSelect.value = data.year;
                    } else {
                        radioNew.checked = true;
                        newYearInput.value = data.year;
                    }
                    toggleYearFields();
                    calendarModal.show();
                } else {
                    alert('Error loading record: ' + (result.message || 'Unknown error'));
                }
            } catch (err) {
                console.error(err);
                alert('Could not fetch entry details. Please refresh and try again.');
            }
        });
    });

    // Delete
    document.querySelectorAll('.delete-entry').forEach(button => {
        button.addEventListener('click', function(e) {
            const id = this.getAttribute('data-id');
            const name = this.getAttribute('data-name');
            if (id && name) {
                document.getElementById('deleteItemName').innerText = name;
                document.getElementById('delete_entry_id').value = id;
                deleteModal.show();
            } else {
                alert('Invalid deletion data.');
            }
        });
    });

    // Front-end validation for dates
    const calendarForm = document.getElementById('calendarForm');
    calendarForm.addEventListener('submit', function(event) {
        let yearValue = '';
        if (radioExisting.checked) {
            yearValue = existingYearSelect.value;
        } else {
            yearValue = newYearInput.value.trim();
        }
        if (!yearValue) {
            event.preventDefault();
            alert('Please provide a valid academic year.');
            return false;
        }
        if (yearValue.length > 9) {
            event.preventDefault();
            alert('Academic year must be at most 9 characters.');
            return false;
        }
        const startDate = document.getElementById('start_date').value;
        const endDate = document.getElementById('end_date').value;
        if (startDate && endDate && startDate > endDate) {
            event.preventDefault();
            alert('Start date cannot be after end date.');
            return false;
        }
        // Ensure term name is not empty
        if (!document.getElementById('term_name').value.trim()) {
            event.preventDefault();
            alert('Term name is required.');
            return false;
        }
    });
</script>
</body>
</html>

<?php
include $_SERVER['DOCUMENT_ROOT'] . '/templates/admin_footer.php';
mysqli_close($conn);
?>