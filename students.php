<?php
/**
 * Student Admission Date Editor with Live Search
 * Run this file to view and edit student admission dates
 * Access: https://yourdomain.com/edit_students.php
 */

// Include database connection
require_once($_SERVER['DOCUMENT_ROOT'] . '/config/db.php');

// Set execution time limit
set_time_limit(300);

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Function to format date for display
function formatDate($date) {
    if (!$date || $date == '0000-00-00') {
        return '';
    }
    return $date;
}

// Handle update request
$message = '';
$message_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_student'])) {
    $student_id = intval($_POST['student_id']);
    $new_admission_date = $_POST['admission_date'];
    $new_name = trim($_POST['student_name']);
    $new_department = trim($_POST['department']);
    $new_address = trim($_POST['address']);
    
    // Validate date
    if ($new_admission_date === '' || preg_match('/^\d{4}-\d{2}-\d{2}$/', $new_admission_date)) {
        $update_sql = "UPDATE users SET 
                        admission_date = ?,
                        name = ?,
                        department = ?,
                        address = ?
                       WHERE id = ? AND role = 'student'";
        
        $stmt = mysqli_prepare($conn, $update_sql);
        mysqli_stmt_bind_param($stmt, 'ssssi', $new_admission_date, $new_name, $new_department, $new_address, $student_id);
        
        if (mysqli_stmt_execute($stmt)) {
            $message = "✅ Student record updated successfully!";
            $message_type = "success";
        } else {
            $message = "❌ Error updating record: " . mysqli_error($conn);
            $message_type = "error";
        }
        mysqli_stmt_close($stmt);
    } else {
        $message = "❌ Invalid date format. Please use YYYY-MM-DD or leave empty";
        $message_type = "error";
    }
}

// Get filter parameters (for initial load)
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$department_filter = isset($_GET['department']) ? trim($_GET['department']) : '';

// Build query for initial load
$sql = "SELECT id, name, email, phone_number, gender, address, national_id, 
        admission_date, department, status 
        FROM users 
        WHERE role = 'student'";

if ($search) {
    $sql .= " AND (name LIKE '%" . mysqli_real_escape_string($conn, $search) . "%' 
              OR national_id LIKE '%" . mysqli_real_escape_string($conn, $search) . "%'
              OR email LIKE '%" . mysqli_real_escape_string($conn, $search) . "%')";
}

if ($department_filter) {
    $sql .= " AND department = '" . mysqli_real_escape_string($conn, $department_filter) . "'";
}

$sql .= " ORDER BY id DESC";

$result = mysqli_query($conn, $sql);

// Get unique departments for filter
$dept_sql = "SELECT DISTINCT department FROM users WHERE role = 'student' AND department IS NOT NULL AND department != '' ORDER BY department";
$dept_result = mysqli_query($conn, $dept_sql);
$departments = [];
while ($row = mysqli_fetch_assoc($dept_result)) {
    $departments[] = $row['department'];
}

// Get statistics
$stats_sql = "SELECT 
                COUNT(*) as total_students,
                COUNT(CASE WHEN admission_date IS NULL OR admission_date = '0000-00-00' THEN 1 END) as missing_dates
              FROM users WHERE role = 'student'";
$stats_result = mysqli_query($conn, $stats_sql);
$stats = mysqli_fetch_assoc($stats_result);

// Start output buffering
ob_start();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Admission Date Editor - QueensPa School</title>
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
        }
        .container {
            max-width: 1400px;
            margin: 0 auto;
            background: white;
            border-radius: 20px;
            padding: 30px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.3);
        }
        h1 {
            color: #667eea;
            margin-bottom: 20px;
        }
        .success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
            padding: 15px;
            border-radius: 10px;
            margin: 20px 0;
        }
        .error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
            padding: 15px;
            border-radius: 10px;
            margin: 20px 0;
        }
        .info {
            background: #d1ecf1;
            color: #0c5460;
            border: 1px solid #bee5eb;
            padding: 15px;
            border-radius: 10px;
            margin: 20px 0;
        }
        .filters {
            display: flex;
            gap: 15px;
            margin: 20px 0;
            flex-wrap: wrap;
            align-items: center;
        }
        .search-box {
            position: relative;
            flex: 2;
            min-width: 250px;
        }
        .search-box input {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid #ddd;
            border-radius: 10px;
            font-size: 14px;
            transition: all 0.3s;
        }
        .search-box input:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 10px rgba(102,126,234,0.3);
        }
        .search-box .search-icon {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            color: #999;
            pointer-events: none;
        }
        .search-results-count {
            font-size: 12px;
            color: #666;
            margin-left: 10px;
        }
        .filters select {
            padding: 12px 15px;
            border: 2px solid #ddd;
            border-radius: 10px;
            font-size: 14px;
            flex: 1;
            min-width: 180px;
            background: white;
            cursor: pointer;
        }
        .filters button {
            background: linear-gradient(to right, #667eea, #764ba2);
            border: none;
            color: white;
            padding: 12px 24px;
            border-radius: 10px;
            cursor: pointer;
            font-size: 14px;
            font-weight: bold;
            transition: all 0.3s;
        }
        .filters button:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(102,126,234,0.4);
        }
        .loading {
            text-align: center;
            padding: 40px;
            font-size: 16px;
            color: #667eea;
        }
        .loading:after {
            content: '...';
            animation: dots 1.5s steps(4, end) infinite;
        }
        @keyframes dots {
            0%, 20% { content: '.'; }
            40% { content: '..'; }
            60%, 100% { content: '...'; }
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
            font-size: 13px;
        }
        th, td {
            padding: 12px 10px;
            text-align: left;
            border-bottom: 1px solid #ddd;
        }
        th {
            background: #667eea;
            color: white;
            position: sticky;
            top: 0;
            z-index: 10;
        }
        tr:hover {
            background: #f5f5f5;
        }
        .badge {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 5px;
            font-size: 11px;
            font-weight: bold;
        }
        .badge-active {
            background: #d4edda;
            color: #155724;
        }
        .badge-missing {
            background: #fff3cd;
            color: #856404;
        }
        .editable-form {
            display: inline-flex;
            gap: 8px;
            align-items: center;
            flex-wrap: wrap;
        }
        .editable-form input[type="date"] {
            padding: 6px 10px;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-size: 12px;
        }
        .editable-form input[type="text"] {
            padding: 6px 10px;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-size: 12px;
            width: 140px;
        }
        .btn-update {
            background: #28a745;
            color: white;
            border: none;
            padding: 6px 12px;
            border-radius: 5px;
            cursor: pointer;
            font-size: 11px;
            transition: all 0.2s;
        }
        .btn-update:hover {
            background: #218838;
            transform: scale(1.02);
        }
        .stats-bar {
            background: #f8f9fa;
            padding: 15px 20px;
            border-radius: 10px;
            display: flex;
            gap: 40px;
            margin: 20px 0;
            flex-wrap: wrap;
        }
        .stat-card {
            text-align: center;
        }
        .stat-number {
            font-size: 28px;
            font-weight: bold;
            color: #667eea;
        }
        .stat-label {
            font-size: 12px;
            color: #666;
        }
        .export-btn {
            background: #17a2b8;
        }
        .export-btn:hover {
            background: #138496;
        }
        .table-wrapper {
            overflow-x: auto;
            max-height: 600px;
            overflow-y: auto;
            border-radius: 10px;
            border: 1px solid #eee;
        }
        .no-results {
            text-align: center;
            padding: 40px;
            color: #999;
            font-size: 16px;
        }
        .highlight {
            background-color: #fff3cd;
            transition: background-color 0.5s;
        }
        @keyframes fadeHighlight {
            from { background-color: #ffeb3b; }
            to { background-color: transparent; }
        }
        @media (max-width: 768px) {
            .editable-form {
                flex-direction: column;
            }
            .editable-form input {
                width: 100% !important;
            }
            th, td {
                font-size: 11px;
                padding: 8px 5px;
            }
            .stats-bar {
                gap: 20px;
            }
            .stat-number {
                font-size: 20px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>📚 Student Admission Date Editor</h1>
        
        <?php if ($message): ?>
            <div class="<?php echo $message_type; ?>">
                <?php echo $message; ?>
            </div>
        <?php endif; ?>
        
        <div class="stats-bar">
            <div class="stat-card">
                <div class="stat-number" id="totalStudents"><?php echo $stats['total_students']; ?></div>
                <div class="stat-label">Total Students</div>
            </div>
            <div class="stat-card">
                <div class="stat-number" id="missingDates"><?php echo $stats['missing_dates']; ?></div>
                <div class="stat-label">Missing Admission Dates</div>
            </div>
            <div class="stat-card">
                <div class="stat-number" id="visibleCount"><?php echo mysqli_num_rows($result); ?></div>
                <div class="stat-label">Showing Results</div>
            </div>
        </div>
        
        <div class="filters">
            <div class="search-box">
                <input type="text" id="liveSearch" placeholder="🔍 Live search by name, student ID, email, department, or address..." autocomplete="off">
                <span class="search-icon">⌕</span>
            </div>
            <select id="departmentFilter">
                <option value="">All Departments</option>
                <?php foreach ($departments as $dept): ?>
                    <option value="<?php echo htmlspecialchars($dept); ?>" <?php echo $department_filter == $dept ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($dept); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <button onclick="resetFilters()">🔄 Reset</button>
            <button class="export-btn" onclick="exportToCSV()">📥 Export to CSV</button>
        </div>
        
        <div class="table-wrapper">
            <table id="studentTable">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Student ID</th>
                        <th>Full Name</th>
                        <th>Department</th>
                        <th>Gender</th>
                        <th>Address</th>
                        <th>Phone</th>
                        <th>Admission Date</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody id="tableBody">
                    <?php if ($result && mysqli_num_rows($result) > 0): ?>
                        <?php while ($row = mysqli_fetch_assoc($result)): ?>
                            <tr data-search="<?php echo strtolower(htmlspecialchars($row['name'] . ' ' . ($row['national_id'] ?? '') . ' ' . ($row['email'] ?? '') . ' ' . ($row['department'] ?? '') . ' ' . ($row['address'] ?? ''))); ?>"
                                data-department="<?php echo strtolower(htmlspecialchars($row['department'] ?? '')); ?>">
                                <td><?php echo $row['id']; ?></td>
                                <td>
                                    <strong><?php echo htmlspecialchars($row['national_id'] ?: 'N/A'); ?></strong>
                                </td>
                                <td>
                                    <form method="POST" class="editable-form" onsubmit="return false;">
                                        <input type="hidden" name="student_id" value="<?php echo $row['id']; ?>">
                                        <input type="text" name="student_name" value="<?php echo htmlspecialchars($row['name']); ?>" style="width: 180px;" class="edit-field">
                                    </form>
                                </td>
                                <td>
                                    <form method="POST" class="editable-form" onsubmit="return false;">
                                        <input type="hidden" name="student_id" value="<?php echo $row['id']; ?>">
                                        <input type="text" name="department" value="<?php echo htmlspecialchars($row['department']); ?>" style="width: 120px;" class="edit-field">
                                    </form>
                                </td>
                                <td><?php echo ucfirst($row['gender']); ?></td>
                                <td>
                                    <form method="POST" class="editable-form" onsubmit="return false;">
                                        <input type="hidden" name="student_id" value="<?php echo $row['id']; ?>">
                                        <input type="text" name="address" value="<?php echo htmlspecialchars($row['address']); ?>" style="width: 150px;" class="edit-field">
                                    </form>
                                </td>
                                <td><?php echo htmlspecialchars($row['phone_number'] ?: 'N/A'); ?></td>
                                <td>
                                    <form method="POST" class="editable-form" onsubmit="return false;">
                                        <input type="hidden" name="student_id" value="<?php echo $row['id']; ?>">
                                        <input type="date" name="admission_date" value="<?php echo formatDate($row['admission_date']); ?>" class="edit-field">
                                    </form>
                                </td>
                                <td>
                                    <span class="badge badge-active"><?php echo ucfirst($row['status']); ?></span>
                                    <?php if (!$row['admission_date'] || $row['admission_date'] == '0000-00-00'): ?>
                                        <span class="badge badge-missing">Missing</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <form method="POST">
                                        <input type="hidden" name="student_id" value="<?php echo $row['id']; ?>">
                                        <input type="hidden" name="student_name" value="<?php echo htmlspecialchars($row['name']); ?>">
                                        <input type="hidden" name="department" value="<?php echo htmlspecialchars($row['department']); ?>">
                                        <input type="hidden" name="address" value="<?php echo htmlspecialchars($row['address']); ?>">
                                        <input type="hidden" name="admission_date" value="<?php echo formatDate($row['admission_date']); ?>">
                                        <button type="submit" name="update_student" class="btn-update">💾 Save</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr class="no-results">
                            <td colspan="10">
                                No students found matching your criteria.
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        
        <div class="info" style="margin-top: 20px;">
            <strong>💡 Tips:</strong>
            <ul style="margin-top: 8px; margin-left: 20px;">
                <li><strong>Live Search:</strong> Start typing in the search box - results update instantly!</li>
                <li>Search works on: Name, Student ID, Email, Department, and Address</li>
                <li>Edit any field directly in the table and click "Save" to update</li>
                <li>Use the department filter to narrow down by class/department</li>
                <li>Leave admission date empty to clear it</li>
                <li>Use Export to CSV to download the current filtered data</li>
            </ul>
        </div>
    </div>
    
    <script>
        // Live search functionality
        const searchInput = document.getElementById('liveSearch');
        const departmentFilter = document.getElementById('departmentFilter');
        const tableBody = document.getElementById('tableBody');
        const rows = Array.from(document.querySelectorAll('#studentTable tbody tr'));
        const visibleCountSpan = document.getElementById('visibleCount');
        
        function filterTable() {
            const searchTerm = searchInput.value.toLowerCase().trim();
            const selectedDept = departmentFilter.value.toLowerCase();
            
            let visibleCount = 0;
            
            rows.forEach(row => {
                // Skip if it's a "no results" row
                if (row.classList.contains('no-results')) return;
                
                const searchData = row.getAttribute('data-search') || '';
                const rowDept = row.getAttribute('data-department') || '';
                
                const matchesSearch = searchTerm === '' || searchData.includes(searchTerm);
                const matchesDept = selectedDept === '' || rowDept === selectedDept;
                
                if (matchesSearch && matchesDept) {
                    row.style.display = '';
                    visibleCount++;
                } else {
                    row.style.display = 'none';
                }
            });
            
            // Update visible count
            visibleCountSpan.textContent = visibleCount;
            
            // Show "no results" message if needed
            let noResultsRow = document.querySelector('#studentTable tbody tr.no-results');
            if (visibleCount === 0 && rows.length > 0) {
                if (!noResultsRow) {
                    const newRow = document.createElement('tr');
                    newRow.className = 'no-results';
                    newRow.innerHTML = '<td colspan="10">No students found matching your search criteria.</td>';
                    tableBody.appendChild(newRow);
                }
            } else if (noResultsRow) {
                noResultsRow.remove();
            }
        }
        
        // Debounce function for better performance
        function debounce(func, wait) {
            let timeout;
            return function executedFunction(...args) {
                const later = () => {
                    clearTimeout(timeout);
                    func(...args);
                };
                clearTimeout(timeout);
                timeout = setTimeout(later, wait);
            };
        }
        
        // Apply filter on input with debounce
        searchInput.addEventListener('input', debounce(filterTable, 300));
        departmentFilter.addEventListener('change', filterTable);
        
        // Reset filters function
        function resetFilters() {
            searchInput.value = '';
            departmentFilter.value = '';
            filterTable();
            // Highlight reset action
            const container = document.querySelector('.container');
            container.style.transition = 'background-color 0.3s';
            container.style.backgroundColor = '#f0f4ff';
            setTimeout(() => {
                container.style.backgroundColor = '';
            }, 500);
        }
        
        // Highlight row when saved (visual feedback)
        function highlightRow(button) {
            const row = button.closest('tr');
            if (row) {
                row.style.transition = 'background-color 0.3s';
                row.style.backgroundColor = '#d4edda';
                setTimeout(() => {
                    row.style.backgroundColor = '';
                }, 1000);
            }
        }
        
        // Override form submission for inline saves
        document.querySelectorAll('form[method="POST"]').forEach(form => {
            form.addEventListener('submit', function(e) {
                const saveButton = this.querySelector('.btn-update');
                if (saveButton) {
                    highlightRow(saveButton);
                }
            });
        });
        
        // Export to CSV function (respects current filters)
        function exportToCSV() {
            let csv = [];
            let headers = ['ID', 'Student ID', 'Full Name', 'Department', 'Gender', 'Address', 'Phone', 'Admission Date', 'Status'];
            csv.push(headers.map(h => '"' + h + '"').join(','));
            
            // Get only visible rows
            const visibleRows = rows.filter(row => row.style.display !== 'none' && !row.classList.contains('no-results'));
            
            visibleRows.forEach(row => {
                const cells = row.querySelectorAll('td');
                if (cells.length >= 9) {
                    let rowData = [];
                    // Get text content, cleaning up
                    for (let i = 0; i < 9; i++) {
                        let text = cells[i].innerText || '';
                        // For name/department/address cells, get the input value if it exists
                        if (i === 2 || i === 3 || i === 5) {
                            const input = cells[i].querySelector('input');
                            if (input) text = input.value;
                        }
                        if (i === 7) {
                            const input = cells[i].querySelector('input');
                            if (input) text = input.value;
                        }
                        text = text.replace(/,/g, ';').replace(/\n/g, ' ').replace(/"/g, '""');
                        rowData.push('"' + text + '"');
                    }
                    csv.push(rowData.join(','));
                }
            });
            
            const blob = new Blob([csv.join('\n')], { type: 'text/csv;charset=utf-8;' });
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = 'students_export_' + new Date().toISOString().slice(0,19).replace(/:/g, '-') + '.csv';
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            URL.revokeObjectURL(url);
        }
        
        // Make functions global for button access
        window.resetFilters = resetFilters;
        window.exportToCSV = exportToCSV;
        
        // Add keyboard shortcut (Ctrl+F to focus search)
        document.addEventListener('keydown', function(e) {
            if ((e.ctrlKey || e.metaKey) && e.key === 'f') {
                e.preventDefault();
                searchInput.focus();
            }
        });
        
        // Initialize tooltip on page load
        console.log('Live search ready! Use Ctrl+F to focus search box.');
    </script>
</body>
</html>

<?php
ob_end_flush();
mysqli_close($conn);
?>