<?php
session_start();
require_once '../config/db.php';

// Check if admin is logged in
if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    header('Location: ../login.php');
    exit;
}

// Handle AJAX request for student data
if (isset($_POST['action']) && $_POST['action'] === 'get_student_stats') {
    $student_id = $_POST['student_id'];
    $term_id = $_POST['term_id'] ?? null;
    $student_name = $_POST['student_name'] ?? 'Student';

    if (!$term_id) {
        echo '<p class="text-red-500 text-center">Please select a term to view detailed statistics.</p>';
        exit;
    }

    // Fetch current term details
    $term_query = "SELECT term_name, year FROM academic_calendar WHERE id = ?";
    $term_stmt = $conn->prepare($term_query);
    $term_stmt->bind_param('i', $term_id);
    $term_stmt->execute();
    $current_term = $term_stmt->get_result()->fetch_assoc();
    $term_stmt->close();
    $current_term_label = $current_term['term_name'] . ' - ' . $current_term['year'];

    // Fetch all historical term grand totals, ordered chronologically
    $terms_query = "SELECT ac.term_name, ac.year, SUM((g.mid_term_exam * 0.5 + g.end_term_exam * 0.5)) AS grand_total
                    FROM grades g
                    JOIN academic_calendar ac ON g.term_id = ac.id
                    WHERE g.user_id = ?
                    GROUP BY ac.id
                    ORDER BY ac.year ASC, FIELD(ac.term_name, 'Term 1', 'Term 2', 'Term 3') ASC";
    $terms_stmt = $conn->prepare($terms_query);
    $terms_stmt->bind_param('i', $student_id);
    $terms_stmt->execute();
    $terms_result = $terms_stmt->get_result();
    $term_data = [];
    $footnote_data = [];
    while ($row = $terms_result->fetch_assoc()) {
        $term_label = $row['year'] . ' - ' . $row['term_name'];
        $term_data[] = ['label' => $term_label, 'value' => (float)$row['grand_total']];
        $footnote_data[] = ['term' => $term_label, 'total' => (float)$row['grand_total']];
    }
    $terms_stmt->close();

    // Calculate footnotes (improvement/regression)
    $prev_total = 0;
    foreach ($footnote_data as &$entry) {
        $current_total = $entry['total'];
        if ($prev_total == 0) {
            $entry['sign'] = 'ORANGE SIGN.webp';
            $entry['remark'] = 'NO CHANGE';
        } elseif ($current_total > $prev_total) {
            $entry['sign'] = 'GREEN SIGN.webp';
            $entry['remark'] = 'IMPROVEMENT';
        } elseif ($current_total < $prev_total) {
            $entry['sign'] = 'RED SIGN.png';
            $entry['remark'] = 'REGRESSION';
        } else {
            $entry['sign'] = 'ORANGE SIGN.webp';
            $entry['remark'] = 'NO CHANGE';
        }
        $prev_total = $current_total;
    }

    // Fetch subjects for the selected term
    $subjects_query = "SELECT s.subject_name, (g.mid_term_exam * 0.5 + g.end_term_exam * 0.5) AS total
                       FROM grades g
                       JOIN subjects s ON g.subject_id = s.id
                       WHERE g.user_id = ? AND g.term_id = ?";
    $subjects_stmt = $conn->prepare($subjects_query);
    $subjects_stmt->bind_param('ii', $student_id, $term_id);
    $subjects_stmt->execute();
    $subjects_result = $subjects_stmt->get_result();
    $subject_data = [];
    $student_grand_total = 0;
    while ($row = $subjects_result->fetch_assoc()) {
        $total = (float)$row['total'];
        $subject_data[] = ['label' => $row['subject_name'], 'value' => $total];
        $student_grand_total += $total;
    }
    $subjects_stmt->close();

    // Fetch class performance for the selected term
    $year = $current_term['year'];
    $class_query = "SELECT 
                        AVG(student_total) as class_average,
                        MAX(student_total) as class_highest,
                        MIN(student_total) as class_lowest
                    FROM (
                        SELECT g.user_id, SUM((g.mid_term_exam * 0.5 + g.end_term_exam * 0.5)) as student_total
                        FROM grades g 
                        JOIN student_classes sc ON g.user_id = sc.user_id 
                        WHERE g.term_id = ? 
                        AND sc.academic_year = ?
                        AND sc.class_id = (SELECT class_id FROM student_classes WHERE user_id = ? AND academic_year = ? LIMIT 1)
                        GROUP BY g.user_id
                    ) as sub";
    $class_stmt = $conn->prepare($class_query);
    $class_stmt->bind_param('isis', $term_id, $year, $student_id, $year);
    $class_stmt->execute();
    $class_results = $class_stmt->get_result()->fetch_assoc();
    $class_average = (float)($class_results['class_average'] ?? 0);
    $class_highest = (float)($class_results['class_highest'] ?? 0);
    $class_lowest = (float)($class_results['class_lowest'] ?? 0);
    $class_stmt->close();

    // Build output HTML similar to the sample
    $output = '<table width="70%" align="center">';
    $output .= '<tr><td colspan="2" align="center"><h2 style="color:#FF6633"><font color="blue">' . htmlspecialchars($student_name) . '</font> - ACADEMIC ANALYSIS<br>' . htmlspecialchars($current_term_label) . '</h2></td></tr>';
    $output .= '<tr><td><div id="chart_div_sub" style="width: 600px; height: 500px;"></div></td>';
    $output .= '<td><div id="chart_div2_sub" style="width: 600px; height: 500px;"></div></td>';
    $output .= '<td><div id="barcharter" style="width: 650px; height: 500px;"></div></td></tr>';
    $output .= '<tr><td><div id="chart_div" style="width: 600px; height: 500px;"></div></td>';
    $output .= '<td><div id="chart_div2" style="width: 600px; height: 500px;"></div></td></tr>';
    $output .= '<tr><td colspan="2"><div class="bg-white p-6 rounded-lg shadow mt-4">';
    $output .= '<h3 class="text-xl font-semibold mb-2 text-center">FOOT NOTE ANALYSIS</h3>';
    $output .= '<div class="overflow-x-auto">';
    $output .= '<table class="min-w-full border border-gray-300 rounded-lg">';
    $output .= '<thead class="bg-gray-200">';
    $output .= '<tr>';
    $output .= '<th class="px-4 py-2 text-left text-sm font-medium text-gray-700">TERM</th>';
    $output .= '<th class="px-4 py-2 text-left text-sm font-medium text-gray-700">SIGN</th>';
    $output .= '<th class="px-4 py-2 text-left text-sm font-medium text-gray-700">REMARKS</th>';
    $output .= '<th class="px-4 py-2 text-left text-sm font-medium text-gray-700">TOTAL MKS</th>';
    $output .= '</tr>';
    $output .= '</thead>';
    $output .= '<tbody>';
    foreach ($footnote_data as $foot) {
        $output .= '<tr class="hover:bg-gray-100">';
        $output .= '<td class="px-4 py-2 border-t font-bold text-gray-800">' . htmlspecialchars($foot['term']) . '</td>';
        $output .= '<td class="px-4 py-2 border-t"><img src="/assets/images/' . $foot['sign'] . '" width="30" height="30" alt="' . $foot['remark'] . '" class="inline-block"></td>';
        $output .= '<td class="px-4 py-2 border-t font-bold italic text-gray-800">' . $foot['remark'] . '</td>';
        $output .= '<td class="px-4 py-2 border-t font-bold text-gray-800">' . number_format($foot['total'], 0) . '</td>';
        $output .= '</tr>';
    }
    $output .= '</tbody>';
    $output .= '</table>';
    $output .= '</div></div></td></tr></table>';

    // Build Google Charts scripts
    $output .= '<script type="text/javascript" src="https://www.gstatic.com/charts/loader.js"></script>';
    $output .= '<script type="text/javascript">';
    $output .= 'google.charts.load("current", {"packages":["corechart", "bar"]});';
    $output .= 'google.charts.setOnLoadCallback(drawChart);';
    $output .= 'function drawChart() {';

    // Term Pie Chart
    $term_rows = '';
    foreach ($term_data as $t) {
        $term_rows .= '{"c":[{"v":"' . addslashes($t['label']) . '"},{"v":' . $t['value'] . '}]},';
    }
    $term_rows = rtrim($term_rows, ',');
    $output .= 'var data = new google.visualization.DataTable({"cols":[{"label":"TERM","type":"string"},{"label":"GRADE","type":"number"}],"rows":[' . $term_rows . ']});';
    $output .= 'var piechart_options = {"title":"PIE CHART - TERMINAL ANALYSIS", is3D:true, width:600, height:500};';
    $output .= 'var chart = new google.visualization.PieChart(document.getElementById("chart_div"));';
    $output .= 'chart.draw(data, piechart_options);';

    // Term Column Chart
    $output .= 'var data = new google.visualization.DataTable({"cols":[{"label":"TERM","type":"string"},{"label":"GRADE","type":"number"}],"rows":[' . $term_rows . ']});';
    $output .= 'var barchart_options = {"title":"COLUMN CHART - TERMINAL ANALYSIS", is3D:true, legend:"none", width:600, height:500};';
    $output .= 'var chart = new google.visualization.ColumnChart(document.getElementById("chart_div2"));';
    $output .= 'chart.draw(data, barchart_options);';

    // Subject Pie Chart
    $subject_rows = '';
    foreach ($subject_data as $s) {
        $subject_rows .= '{"c":[{"v":"' . addslashes($s['label']) . '"},{"v":' . $s['value'] . '}]},';
    }
    $subject_rows = rtrim($subject_rows, ',');
    $output .= 'var data = new google.visualization.DataTable({"cols":[{"label":"SUBJECT/COURSE","type":"string"},{"label":"GRADE","type":"number"}],"rows":[' . $subject_rows . ']});';
    $output .= 'var piechart_sub_options = {"title":"PIE CHART - SUBJECT / COURSE ANALYSIS", is3D:true, width:600, height:500};';
    $output .= 'var chart = new google.visualization.PieChart(document.getElementById("chart_div_sub"));';
    $output .= 'chart.draw(data, piechart_sub_options);';

    // Subject Column Chart
    $output .= 'var data = new google.visualization.DataTable({"cols":[{"label":"SUBJECT/COURSE","type":"string"},{"label":"GRADE","type":"number"}],"rows":[' . $subject_rows . ']});';
    $output .= 'var barchart_sub_options = {"title":"COLUMN CHART - SUBJECT / COURSE ANALYSIS", is3D:true, legend:"none", width:600, height:500};';
    $output .= 'var chart = new google.visualization.ColumnChart(document.getElementById("chart_div2_sub"));';
    $output .= 'chart.draw(data, barchart_sub_options);';

    // Class Bar Chart
    $subtitle = 'STUDENT AVERAGE --> ' . number_format($student_grand_total, 4) . ', CLASS HIGHEST --> ' . number_format($class_highest, 4) . ', CLASS AVERAGE --> ' . number_format($class_average, 4) . ' & CLASS LOWEST --> ' . number_format($class_lowest, 4);
    $output .= 'var data = google.visualization.arrayToDataTable([["ACADEMIC YEAR / TERM", "STUDENT AVERAGE (' . number_format($student_grand_total, 4) . ')", "CLASS HIGHEST (' . number_format($class_highest, 4) . ')", "CLASS AVERAGE (' . number_format($class_average, 4) . ')", "CLASS LOWEST (' . number_format($class_lowest, 4) . ')"],["' . addslashes($current_term_label) . '", ' . $student_grand_total . ', ' . $class_highest . ', ' . $class_average . ', ' . $class_lowest . ']]);';
    $output .= 'var options = {chart: {subtitle: "' . addslashes($subtitle) . '", title: "' . addslashes($subtitle) . '"}};';
    $output .= 'var chart = new google.charts.Bar(document.getElementById("barcharter"));';
    $output .= 'chart.draw(data, google.charts.Bar.convertOptions(options));';

    $output .= '}';
    $output .= '</script>';

    echo $output;
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Student Statistics</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script type="text/javascript" src="https://www.gstatic.com/charts/loader.js"></script>
</head>
<body class="bg-gray-100">
    <?php include '../templates/admin_header.php'; ?>
    
    <div class="container mx-auto p-4">
        <h2 class="text-3xl font-bold mb-6 text-center">Student Statistics</h2>
        
        <form method="POST" class="mb-8 bg-white p-6 rounded-lg shadow" id="statsForm">
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div>
                    <label for="student_id" class="block text-sm font-medium text-gray-700">Select Student</label>
                    <select name="student_id" id="student_id" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:border-blue-500 focus:ring-blue-500" required>
                        <option value="">Select a Student</option>
                        <?php
                        $students_query = "SELECT id, name FROM users WHERE role = 'student'";
                        $students_result = $conn->query($students_query);
                        while ($student = $students_result->fetch_assoc()) {
                            echo '<option value="' . $student['id'] . '">' . htmlspecialchars($student['name']) . '</option>';
                        }
                        ?>
                    </select>
                </div>
                <div>
                    <label for="term_id" class="block text-sm font-medium text-gray-700">Select Term</label>
                    <select name="term_id" id="term_id" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:border-blue-500 focus:ring-blue-500" required>
                        <option value="">Select a Term</option>
                        <?php
                        $terms_query = "SELECT id, term_name, year FROM academic_calendar ORDER BY year DESC, term_name";
                        $terms_result = $conn->query($terms_query);
                        while ($term = $terms_result->fetch_assoc()) {
                            echo '<option value="' . $term['id'] . '">' . htmlspecialchars($term['term_name'] . ' (' . $term['year'] . ')') . '</option>';
                        }
                        ?>
                    </select>
                </div>
                <div class="flex items-end">
                    <button type="submit" class="w-full bg-blue-500 text-white py-2 px-4 rounded-md hover:bg-blue-600">Generate Stats</button>
                </div>
            </div>
        </form>
        
        <div id="statsContent" class="bg-white p-6 rounded-lg shadow">
            <!-- AJAX content will be loaded here -->
        </div>
    </div>
    
    <?php include '../templates/admin_footer.php'; ?>
    
    <script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
    <script>
        $(document).ready(function() {
            // Handle form submission
            $('#statsForm').on('submit', function(e) {
                e.preventDefault();
                var studentId = $('#student_id').val();
                var termId = $('#term_id').val();
                var studentName = $('#student_id option:selected').text();

                if (!termId) {
                    $('#statsContent').html('<p class="text-red-500 text-center">Please select a term.</p>');
                    return;
                }

                $.ajax({
                    url: 'student_statistics.php',
                    method: 'POST',
                    data: { action: 'get_student_stats', student_id: studentId, term_id: termId, student_name: studentName },
                    success: function(response) {
                        $('#statsContent').html(response);
                    },
                    error: function(xhr, status, error) {
                        $('#statsContent').html('<p class="text-red-500">Error loading statistics: ' + error + '</p>');
                    }
                });
            });

            // Automatically load stats if URL parameters are provided (from report_card.php)
            const urlParams = new URLSearchParams(window.location.search);
            const userId = urlParams.get('user_id');
            const termIdParam = urlParams.get('term_id');
            if (userId && termIdParam) {
                // Hide the form when accessed via link
                $('#statsForm').hide();

                $('#student_id').val(userId);
                $('#term_id').val(termIdParam);
                const studentName = $('#student_id option:selected').text() || 'Student';
                $.ajax({
                    url: 'student_statistics.php',
                    method: 'POST',
                    data: { action: 'get_student_stats', student_id: userId, term_id: termIdParam, student_name: studentName },
                    success: function(response) {
                        $('#statsContent').html(response);
                    },
                    error: function() {
                        $('#statsContent').html('<p class="text-red-500">Error loading statistics.</p>');
                    }
                });
            }
        });
    </script>
</body>
</html>