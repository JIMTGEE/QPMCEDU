<?php
/**
 * Import Student Debts into fees table
 * Access: https://yourdomain.com/import_fees_debts.php
 */

require_once($_SERVER['DOCUMENT_ROOT'] . '/config/db.php');
set_time_limit(300);
error_reporting(E_ALL);
ini_set('display_errors', 1);

$message = '';
$message_type = '';
$import_success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['import_debts'])) {
    
    $current_academic_year_label = '2025/2026';
    $current_term_id = 6;          // Term 3 from academic_calendar
    $current_academic_year_id = 2;  // 2025/2026 from academic_years
    $due_date = date('Y-m-d');      // Today's date
    
    // Debt data: [user_id, amount_due]
    $debts_data = [
        [274, 1000], [138, 1000], [200, 1800], [134, 2330],
        [294, 1650], [127, 2600], [135, 2700], [126, 2700],
        [90, 2900], [137, 14440], [100, 10870], [108, 1700],
        [106, 1700], [98, 6280], [288, 1850], [283, 1850],
        [258, 1050], [325, 1070], [115, 938], [198, 10],
        [118, 700], [117, 3150], [120, 3700], [122, 2890],
        [124, 700], [240, 3000], [59, 1200], [62, 2700],
        [52, 4770], [61, 2700], [51, 500], [47, 2700],
        [230, 3000], [46, 220], [48, 2220], [95, 4710],
        [96, 700], [290, 1700], [86, 1200], [87, 1480],
        [94, 200], [88, 9840], [83, 2100], [89, 1200],
        [84, 1200], [268, 1800], [71, 40], [67, 2030],
        [82, 700], [65, 2700], [292, 1650], [76, 700],
        [69, 2900], [66, 830], [70, 1200], [249, 1000],
        [78, 1700], [186, 12895], [187, 3100], [298, 2850],
        [185, 1050], [194, 950], [183, 1750], [237, 850],
        [285, 850], [190, 1550], [310, 650], [238, 1600],
        [184, 1935], [181, 1260], [210, 350], [234, 2850],
        [173, 350], [214, 1350], [305, 850], [178, 1550],
        [175, 7250], [256, 2850], [327, 1070], [166, 2050],
        [165, 550], [159, 550], [158, 550], [167, 3840],
        [242, 1350], [171, 790], [164, 1400], [146, 7250],
        [148, 3390], [157, 750], [329, 1070], [147, 3850],
        [143, 1050], [145, 550], [154, 2550], [287, 850]
    ];
    
    $insert_count = 0;
    $skip_count = 0;
    
    // Check if fee already exists
    $check_sql = "SELECT id FROM fees 
                  WHERE user_id = ? 
                    AND fee_type = 'School Fees Debt'
                    AND academic_year = ? 
                    AND term_id = ?";
    $check_stmt = mysqli_prepare($conn, $check_sql);
    
    // Get student's class_id
    $get_class_sql = "SELECT class_id FROM users WHERE id = ?";
    $class_stmt = mysqli_prepare($conn, $get_class_sql);
    
    // Insert statement – 8 placeholders
    $insert_sql = "INSERT INTO fees (
        class_id, department, fee_type, amount, due_date,
        academic_year, term_id, target_type, user_id, is_recurring,
        scholarship_percentage, original_amount, academic_year_id, amount_paid, student_id_ref
    ) VALUES (?, 'Upper Primary', 'School Fees Debt', ?, ?, ?, ?, 'Learner', ?, 0, 0.00, ?, ?, 0.00, NULL)";
    $insert_stmt = mysqli_prepare($conn, $insert_sql);
    
    foreach ($debts_data as $debt) {
        $user_id = $debt[0];
        $amount_due = $debt[1];
        
        // Check duplicate
        mysqli_stmt_bind_param($check_stmt, 'isi', $user_id, $current_academic_year_label, $current_term_id);
        mysqli_stmt_execute($check_stmt);
        mysqli_stmt_store_result($check_stmt);
        
        if (mysqli_stmt_num_rows($check_stmt) > 0) {
            $skip_count++;
            continue;
        }
        
        // Get class_id (may be NULL)
        mysqli_stmt_bind_param($class_stmt, 'i', $user_id);
        mysqli_stmt_execute($class_stmt);
        mysqli_stmt_bind_result($class_stmt, $class_id);
        mysqli_stmt_fetch($class_stmt);
        mysqli_stmt_free_result($class_stmt);
        
        // Bind parameters for the 8 placeholders:
        // 1: class_id (int, can be NULL)
        // 2: amount (double)
        // 3: due_date (string)
        // 4: academic_year (string)
        // 5: term_id (int)
        // 6: user_id (int)
        // 7: original_amount (double)
        // 8: academic_year_id (int)
        mysqli_stmt_bind_param($insert_stmt, 'idsissiid', 
            $class_id,                     // i
            $amount_due,                   // d
            $due_date,                     // s
            $current_academic_year_label,  // s
            $current_term_id,              // i
            $user_id,                      // i
            $amount_due,                   // d (original_amount)
            $current_academic_year_id      // i
        );
        
        if (mysqli_stmt_execute($insert_stmt)) {
            $insert_count++;
        } else {
            error_log("Insert failed for user $user_id: " . mysqli_stmt_error($insert_stmt));
        }
    }
    
    mysqli_stmt_close($check_stmt);
    mysqli_stmt_close($class_stmt);
    mysqli_stmt_close($insert_stmt);
    
    $message = "✅ Import completed! Inserted: $insert_count fee records. Skipped: $skip_count duplicates.";
    $message_type = "success";
    $import_success = true;
}

// Get statistics
$stats_sql = "SELECT 
                COUNT(*) as total_fees,
                SUM(amount) as total_amount,
                COUNT(DISTINCT user_id) as unique_students
              FROM fees
              WHERE fee_type = 'School Fees Debt'
                AND academic_year = '2025/2026'
                AND term_id = 6";
$stats_result = mysqli_query($conn, $stats_sql);
$stats = mysqli_fetch_assoc($stats_result);

// Get list of imported fees
$fees_list_sql = "SELECT u.id, u.name, f.amount, f.academic_year, ac.term_name
                  FROM fees f
                  JOIN users u ON u.id = f.user_id
                  JOIN academic_calendar ac ON ac.id = f.term_id
                  WHERE f.fee_type = 'School Fees Debt'
                    AND f.academic_year = '2025/2026'
                    AND f.term_id = 6
                  ORDER BY u.name";
$fees_list_result = mysqli_query($conn, $fees_list_sql);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Import Student Debts into Fees Table</title>
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
        h1 { color: #667eea; margin-bottom: 10px; }
        .subtitle { color: #666; margin-bottom: 30px; }
        .success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; padding: 15px; border-radius: 10px; margin: 20px 0; }
        .error { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; padding: 15px; border-radius: 10px; margin: 20px 0; }
        .info { background: #d1ecf1; color: #0c5460; border: 1px solid #bee5eb; padding: 15px; border-radius: 10px; margin: 20px 0; }
        .warning { background: #fff3cd; color: #856404; border: 1px solid #ffeeba; padding: 15px; border-radius: 10px; margin: 20px 0; }
        .stats-bar {
            background: #f8f9fa; padding: 20px; border-radius: 10px; display: flex; gap: 40px; margin: 20px 0; flex-wrap: wrap;
        }
        .stat-card { text-align: center; flex: 1; min-width: 150px; }
        .stat-number { font-size: 32px; font-weight: bold; color: #667eea; }
        .stat-label { font-size: 14px; color: #666; margin-top: 5px; }
        .import-btn {
            background: linear-gradient(to right, #28a745, #20c997);
            border: none; color: white; padding: 15px 40px; border-radius: 10px; cursor: pointer;
            font-size: 18px; font-weight: bold; transition: all 0.3s; margin: 20px 0;
        }
        .import-btn:hover { transform: translateY(-2px); box-shadow: 0 5px 15px rgba(40,167,69,0.4); }
        .table-wrapper { overflow-x: auto; max-height: 500px; overflow-y: auto; border-radius: 10px; border: 1px solid #eee; margin-top: 20px; }
        table { width: 100%; border-collapse: collapse; font-size: 13px; }
        th, td { padding: 12px 10px; text-align: left; border-bottom: 1px solid #ddd; }
        th { background: #667eea; color: white; position: sticky; top: 0; z-index: 10; }
        tr:hover { background: #f5f5f5; }
        .amount { color: #dc3545; font-weight: bold; }
        .btn-back { background: #6c757d; color: white; border: none; padding: 10px 20px; border-radius: 8px; cursor: pointer; text-decoration: none; display: inline-block; margin-top: 20px; }
        .btn-back:hover { background: #5a6268; }
        @media (max-width: 768px) { .container { padding: 15px; } th, td { font-size: 11px; padding: 8px 5px; } .stat-number { font-size: 24px; } }
    </style>
</head>
<body>
    <div class="container">
        <h1>💰 Import Student Debts (into Fees Table)</h1>
        <div class="subtitle">Create fee records for each student's debt (June 10, 2026)</div>
        
        <?php if ($message): ?>
            <div class="<?php echo $message_type; ?>"><?php echo $message; ?></div>
        <?php endif; ?>
        
        <div class="stats-bar">
            <div class="stat-card"><div class="stat-number"><?php echo number_format($stats['total_fees'] ?? 0); ?></div><div class="stat-label">Total Fee Records</div></div>
            <div class="stat-card"><div class="stat-number">₵<?php echo number_format($stats['total_amount'] ?? 0, 2); ?></div><div class="stat-label">Total Debt Amount</div></div>
            <div class="stat-card"><div class="stat-number"><?php echo $stats['unique_students'] ?? 0; ?></div><div class="stat-label">Students with Debt</div></div>
        </div>
        
        <?php if (!$import_success): ?>
            <div class="warning"><strong>⚠️ Notice:</strong> No fee records have been imported yet. Click the button below to create fee records for all student debts.</div>
            <form method="POST">
                <button type="submit" name="import_debts" class="import-btn" onclick="return confirm('Are you sure you want to create fee records for all student debts? This will add records for 96 students.');">📥 Import Debts into Fees Table</button>
            </form>
        <?php else: ?>
            <div class="info"><strong>✅ Import Successful!</strong> All student debts have been recorded in the fees table.</div>
        <?php endif; ?>
        
        <?php if ($fees_list_result && mysqli_num_rows($fees_list_result) > 0): ?>
            <h3 style="margin-top: 30px;">📋 Imported Fee Records</h3>
            <div class="table-wrapper">
                <table>
                    <thead><tr><th>User ID</th><th>Student Name</th><th>Amount Due</th><th>Academic Year</th><th>Term</th></tr></thead>
                    <tbody>
                        <?php while ($fee = mysqli_fetch_assoc($fees_list_result)): ?>
                            <tr><td><?php echo $fee['id']; ?></td><td><?php echo htmlspecialchars($fee['name']); ?></td><td class="amount">₵<?php echo number_format($fee['amount'], 2); ?></td><td><?php echo $fee['academic_year']; ?></td><td><?php echo $fee['term_name']; ?></td></tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
        
        <a href="javascript:history.back()" class="btn-back">← Go Back</a>
    </div>
</body>
</html>

<?php
mysqli_close($conn);
?>