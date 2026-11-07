<?php
function generateInsights($conn, $student_id, $metrics) {
    $insights = [];
    $prev = $conn->query("SELECT attendance FROM student_reports WHERE user_id = $student_id ORDER BY report_date DESC LIMIT 1 OFFSET 1")->fetch_assoc()['attendance'] ?? null;
    if ($prev && $metrics['attendance'] < $prev - 5) {
        $insights[] = ['icon'=>'fa-exclamation-triangle','title'=>'Attendance Drop','desc'=>"Down " . ($prev - $metrics['attendance']) . "% from last report."];
    }
    if (!empty($metrics['debts'])) {
        $insights[] = ['icon'=>'fa-money-bill-wave','title'=>'Outstanding Fees','desc'=>count($metrics['debts']) . ' fee(s) due totaling GH₵ ' . number_format(array_sum(array_column($metrics['debts'],'amount_due')),2)];
    }
    return $insights;
}
?>