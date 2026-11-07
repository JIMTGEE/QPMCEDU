<?php
session_start();
require_once($_SERVER['DOCUMENT_ROOT'] . '/config/db.php');

if (!$conn || $conn->connect_error) {
    die("System error: Database connection failed.");
}

if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    $_SESSION['error_msg'] = "Please log in as an admin to access this page.";
    header('Location: /login.php');
    exit;
}

$log_activity_path = $_SERVER['DOCUMENT_ROOT'] . '/functions/log_activity.php';
if (file_exists($log_activity_path)) require_once($log_activity_path);
if (!function_exists('log_activity')) {
    function log_activity($user_id, $action, $details, $conn = null) {
        @file_put_contents($_SERVER['DOCUMENT_ROOT'] . '/logs/activity.log', 
            "[" . date('Y-m-d H:i:s') . "] [user_id:$user_id] $action: $details\n", FILE_APPEND);
    }
}

$learner_id = isset($_GET['learner_id']) ? (int)$_GET['learner_id'] : 0;
$selected_academic_year = isset($_GET['academic_year']) ? $_GET['academic_year'] : '';
$show_all = isset($_GET['show_all']) && $_GET['show_all'] == '1';

if ($learner_id <= 0) {
    $_SESSION['error_msg'] = "Invalid learner ID.";
    header('Location: fees_payments.php');
    exit;
}

$class_name_map = [
    'Esther Foundation' => ['display' => 'Creche', 'level' => 'Pre-School'],
    'Sheba One'         => ['display' => 'Nursery 1', 'level' => 'Pre-School'],
    'Sheba Two'         => ['display' => 'Nursery 2', 'level' => 'Pre-School'],
    'Yaasant One'       => ['display' => 'KG 1', 'level' => 'Kindergarten'],
    'Yaasant Two'       => ['display' => 'KG 2', 'level' => 'Kindergarten'],
    'Cleopatra One'     => ['display' => 'Basic 1', 'level' => 'Lower Primary'],
    'Cleopatra Two'     => ['display' => 'Basic 2', 'level' => 'Lower Primary'],
    'Nefertiti One'     => ['display' => 'Basic 3', 'level' => 'Lower Primary'],
    'Nefertiti Two'     => ['display' => 'Basic 4', 'level' => 'Lower Primary'],
    'Malika One'        => ['display' => 'Basic 5', 'level' => 'Upper Primary'],
    'Malika Two'        => ['display' => 'Basic 6', 'level' => 'Upper Primary'],
    'Makeda One'        => ['display' => 'Basic 7', 'level' => 'Junior High'],
    'Makeda Two'        => ['display' => 'Basic 8', 'level' => 'Junior High'],
    'An Majesty'        => ['display' => 'Basic 9', 'level' => 'Junior High'],
];

// Fetch learner
$learner_query = "
    SELECT u.id, u.name, u.email, u.admission_date, u.status, u.gender,
           s.student_name,
           sc.class_id, c.class_name, c.department,
           p.parent_name, p.phone_number as parent_phone
    FROM users u
    LEFT JOIN students s ON u.id = s.user_id
    LEFT JOIN student_classes sc ON u.id = sc.user_id
    LEFT JOIN classes c ON sc.class_id = c.id
    LEFT JOIN parent_student_mapping psm ON u.id = psm.student_id
    LEFT JOIN parents p ON psm.parent_id = p.id
    WHERE u.id = ? AND u.role = 'student'
    ORDER BY sc.created_at DESC LIMIT 1
";
$stmt = $conn->prepare($learner_query);
$stmt->bind_param("i", $learner_id);
$stmt->execute();
$learner = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$learner) {
    $_SESSION['error_msg'] = "Learner not found.";
    header('Location: fees_payments.php');
    exit;
}

$learner_name = $learner['name'] ?? $learner['student_name'] ?? 'Student';
$class_db_name = $learner['class_name'] ?? '';
$class_display = isset($class_name_map[$class_db_name]) ? $class_name_map[$class_db_name]['display'] : $class_db_name;
$class_level = isset($class_name_map[$class_db_name]) ? $class_name_map[$class_db_name]['level'] : '';
$learner_class_id = (int)($learner['class_id'] ?? 0);

// Class history
$class_history = [];
$ch = $conn->prepare("SELECT sc.class_id, sc.academic_year FROM student_classes sc WHERE sc.user_id = ? ORDER BY sc.academic_year ASC");
$ch->bind_param("i", $learner_id);
$ch->execute();
$chr = $ch->get_result();
while ($row = $chr->fetch_assoc()) $class_history[$row['academic_year']] = (int)$row['class_id'];
$ch->close();

// Find years with fees
$years_set = [];
$q = $conn->prepare("SELECT DISTINCT academic_year FROM fees WHERE target_type = 'Learner' AND user_id = ?");
$q->bind_param("i", $learner_id); $q->execute(); $r = $q->get_result();
while ($row = $r->fetch_assoc()) $years_set[$row['academic_year']] = true; $q->close();

$q = $conn->prepare("SELECT DISTINCT academic_year FROM fees WHERE target_type = 'Bulk Learners' AND user_id = ?");
$q->bind_param("i", $learner_id); $q->execute(); $r = $q->get_result();
while ($row = $r->fetch_assoc()) $years_set[$row['academic_year']] = true; $q->close();

foreach ($class_history as $year => $cid) {
    $q = $conn->prepare("SELECT DISTINCT academic_year FROM fees WHERE target_type = 'Class' AND class_id = ? AND academic_year = ?");
    $q->bind_param("is", $cid, $year); $q->execute();
    if ($q->get_result()->num_rows > 0) $years_set[$year] = true; $q->close();
}

if (!empty($class_history)) {
    $result = $conn->query("SELECT DISTINCT academic_year FROM fees WHERE target_type = 'School'");
    while ($row = $result->fetch_assoc()) {
        if (isset($class_history[$row['academic_year']])) $years_set[$row['academic_year']] = true;
    }
}

$all_years = array_keys($years_set);
sort($all_years);
if (empty($selected_academic_year) && !empty($all_years)) $selected_academic_year = $all_years[0];
$years_to_process = $show_all ? $all_years : [$selected_academic_year];

// Build fee data
$all_term_data = [];
$carry_over_arrears = 0;
$overall_total_fees = 0;
$overall_total_paid = 0;

foreach ($years_to_process as $year) {
    $year_class_id = isset($class_history[$year]) ? $class_history[$year] : $learner_class_id;
    
    $terms = [];
    $tq = $conn->prepare("SELECT id, term_name FROM academic_calendar WHERE year = ? ORDER BY start_date ASC");
    $tq->bind_param("s", $year); $tq->execute(); $tr = $tq->get_result();
    while ($row = $tr->fetch_assoc()) $terms[] = $row; $tq->close();
    
    if (empty($terms)) {
        $terms = [['id'=>1,'term_name'=>'Term 1'],['id'=>2,'term_name'=>'Term 2'],['id'=>3,'term_name'=>'Term 3']];
    }
    
    foreach ($terms as $term) {
        $term_id = (int)$term['id'];
        
        $fq = $conn->prepare("SELECT f.id, f.fee_type, f.amount, f.department, f.target_type, f.due_date, f.academic_year, f.term_id FROM fees f WHERE f.academic_year = ? AND ((f.target_type = 'Learner' AND f.user_id = ?) OR (f.target_type = 'Bulk Learners' AND f.user_id = ?) OR (f.target_type = 'Class' AND f.class_id = ?) OR (f.target_type = 'School')) ORDER BY f.due_date ASC");
        $fq->bind_param("siii", $year, $learner_id, $learner_id, $year_class_id);
        $fq->execute(); $af = $fq->get_result()->fetch_all(MYSQLI_ASSOC); $fq->close();
        
        $term_fees = []; $ttf = 0; $ttp = 0;
        foreach ($af as $fee) {
            $ftid = (int)($fee['term_id'] ?? 0);
            if ($ftid === 0 || $ftid === $term_id) {
                $pq = $conn->prepare("SELECT COALESCE(SUM(amount_paid),0) AS tp FROM payments WHERE fee_id=? AND user_id=?");
                $pq->bind_param("ii", $fee['id'], $learner_id); $pq->execute();
                $paid = (float)$pq->get_result()->fetch_assoc()['tp']; $pq->close();
                $fee['amount_paid'] = $paid;
                $term_fees[] = $fee; $ttf += (float)$fee['amount']; $ttp += $paid;
            }
        }
        
        $sq = $conn->prepare("SELECT id, amount_paid, payment_date, payment_method, receipt_number FROM payments WHERE user_id=? AND (fee_id IS NULL OR fee_id=0)");
        $sq->bind_param("i", $learner_id); $sq->execute();
        $standalone = $sq->get_result()->fetch_all(MYSQLI_ASSOC); $sq->close();
        foreach ($standalone as $sp) $ttp += (float)$sp['amount_paid'];
        
        $abf = $carry_over_arrears;
        $tdue = $ttf + $abf;
        $bal = $tdue - $ttp;
        $carry_over_arrears = max(0, $bal);
        
        $all_term_data[] = [
            'year'=>$year, 'term_name'=>$term['term_name'], 'term_number'=>$term_id,
            'fees'=>$term_fees, 'standalone_payments'=>$standalone,
            'total_fees'=>$ttf, 'arrears_brought_forward'=>$abf, 'total_due'=>$tdue,
            'total_paid'=>$ttp, 'balance'=>$bal, 'is_fully_paid'=>$bal<=0,
            'has_data'=>!empty($term_fees)||!empty($standalone)||$abf>0
        ];
        $overall_total_fees += $ttf; $overall_total_paid += $ttp;
    }
}

$overall_total_due = $overall_total_fees - $overall_total_paid;
$overall_balance = $overall_total_due;

// Payment history
$ph = $conn->prepare("SELECT p.id, p.amount_paid, p.payment_date, p.payment_method, p.receipt_number, f.fee_type, f.academic_year, u.name AS rb FROM payments p LEFT JOIN fees f ON p.fee_id=f.id LEFT JOIN users u ON p.recorded_by=u.id WHERE p.user_id=? ORDER BY p.payment_date DESC");
$ph->bind_param("i", $learner_id); $ph->execute();
$payment_history = $ph->get_result()->fetch_all(MYSQLI_ASSOC); $ph->close();

log_activity($_SESSION['user_id'], 'fee_details', "Viewed fees for learner $learner_id", $conn);

$name_parts = explode(' ', trim($learner_name));
$initials = strtoupper(substr($name_parts[0], 0, 1) . (isset($name_parts[1]) ? substr($name_parts[1], 0, 1) : ''));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Fee Details - <?= htmlspecialchars($learner_name) ?> | QPMC</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        :root{--p:#FF6200;--pl:#FFF0E6;--s:#10B981;--w:#F59E0B;--d:#EF4444;--i:#3B82F6;--bg:#F8FAFC;--c:#fff;--b:#E2E8F0;--t:#1E293B;--tm:#64748B;--dk:#1a1a2e}
        *{margin:0;padding:0;box-sizing:border-box}
        body{background:var(--bg);font-family:'Segoe UI',system-ui,sans-serif;min-height:100vh;color:var(--t)}
        .wrap{max-width:1200px;margin:0 auto;padding:1rem}
        
        .topbar{background:var(--c);border-radius:12px;padding:.75rem 1rem;margin-bottom:1rem;box-shadow:0 1px 3px rgba(0,0,0,.05);display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.5rem;border:1px solid var(--b)}
        .back{display:inline-flex;align-items:center;gap:.4rem;color:var(--p);text-decoration:none;font-weight:600;font-size:.85rem;padding:.45rem .9rem;border-radius:8px;background:var(--pl)}
        .back:hover{background:var(--p);color:#fff}
        .yrsel{display:flex;align-items:center;gap:.4rem}
        .yrsel select{border:2px solid var(--b);border-radius:8px;padding:.4rem .8rem;font-weight:600;font-size:.82rem;background:#fff}
        .tgl{padding:.4rem .9rem;border-radius:8px;border:2px solid var(--b);font-weight:600;font-size:.78rem;text-decoration:none;display:inline-flex;align-items:center;gap:.35rem;transition:all .2s}
        .tgl.on{background:var(--p);color:#fff;border-color:var(--p)}
        .tgl:not(.on){background:#fff;color:#666}
        .tgl:hover:not(.on){border-color:var(--p);color:var(--p)}
        
        .pcard{background:var(--c);border-radius:12px;padding:1.25rem;margin-bottom:1rem;box-shadow:0 1px 3px rgba(0,0,0,.05);border:1px solid var(--b);display:flex;align-items:flex-start;gap:1rem;flex-wrap:wrap}
        .av{width:56px;height:56px;border-radius:14px;background:linear-gradient(135deg,var(--p),#FF8C00);color:#fff;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:1.2rem;flex-shrink:0}
        .pinfo{flex:1;min-width:180px}
        .pname{font-size:1.3rem;font-weight:800;margin:0}
        .pmeta{display:flex;flex-wrap:wrap;gap:.6rem;margin-top:.3rem}
        .pmeta span{font-size:.78rem;color:var(--tm);display:flex;align-items:center;gap:.3rem}
        .pmeta span i{color:var(--p)}
        .statbadge{display:inline-flex;align-items:center;gap:.25rem;padding:.2rem .6rem;border-radius:50px;font-size:.7rem;font-weight:700;margin-top:.4rem;background:rgba(16,185,129,.1);color:var(--s);border:1px solid rgba(16,185,129,.2)}
        
        .sumgrid{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:.7rem;margin-bottom:1rem}
        .sc{background:var(--c);border-radius:10px;padding:1rem;text-align:center;border-top:4px solid transparent;box-shadow:0 1px 3px rgba(0,0,0,.04);border:1px solid var(--b)}
        .sc.f{border-top-color:var(--p)}.sc.p{border-top-color:var(--s)}.sc.d{border-top-color:var(--d)}.sc.b{border-top-color:var(--w)}
        .sc .ic{font-size:1.2rem;margin-bottom:.3rem}.sc.f .ic{color:var(--p)}.sc.p .ic{color:var(--s)}.sc.d .ic{color:var(--d)}.sc.b .ic{color:var(--w)}
        .sc .vl{font-size:1.2rem;font-weight:800}.sc .lb{font-size:.65rem;color:var(--tm);text-transform:uppercase;letter-spacing:.4px;font-weight:600}
        
        .banner{border-radius:10px;padding:.85rem 1rem;margin-bottom:1rem;display:flex;align-items:center;gap:.8rem;flex-wrap:wrap}
        .banner.r{background:#FFFBEB;border:2px solid #FCD34D}.banner.g{background:#ECFDF5;border:2px solid #6EE7B7}
        .banner .bi{font-size:1.6rem}.banner.r .bi{color:#D97706}.banner.g .bi{color:#059669}
        .banner .bt{flex:1;font-weight:700;font-size:.85rem}.banner.r .bt{color:#92400E}.banner.g .bt{color:#065F46}
        .banner .ba{font-weight:800;font-size:1.1rem}.banner.r .ba{color:#D97706}.banner.g .ba{color:#059669}
        
        .arow{display:flex;gap:.4rem;flex-wrap:wrap;margin-bottom:.7rem}
        .btn-c{display:inline-flex;align-items:center;gap:.35rem;padding:.5rem 1rem;border-radius:8px;font-weight:600;font-size:.8rem;text-decoration:none;border:none;cursor:pointer;transition:all .2s}
        .btn-p{background:linear-gradient(135deg,var(--p),#FF8C00);color:#fff}.btn-p:hover{transform:translateY(-1px);box-shadow:0 4px 12px rgba(255,98,0,.25);color:#fff}
        .btn-o{background:#fff;border:2px solid var(--p);color:var(--p)}.btn-o:hover{background:var(--p);color:#fff}
        .btn-s{background:linear-gradient(135deg,#10B981,#34D399);color:#fff}.btn-s:hover{transform:translateY(-1px);box-shadow:0 4px 12px rgba(16,185,129,.25);color:#fff}
        
        /* Year group heading */
        .year-heading{font-size:1.1rem;font-weight:800;color:var(--dk);margin:1.5rem 0 .7rem;padding:.6rem 1rem;background:var(--c);border-radius:10px;border-left:5px solid var(--p);box-shadow:0 1px 3px rgba(0,0,0,.04);display:flex;align-items:center;gap:.5rem}
        .year-heading i{color:var(--p)}
        
        /* Term section - always visible */
        .term-block{background:var(--c);border-radius:10px;margin-bottom:1.2rem;box-shadow:0 1px 3px rgba(0,0,0,.04);border:1px solid var(--b);overflow:hidden}
        .term-block-header{background:linear-gradient(135deg,var(--dk),#2d3748);color:#fff;padding:.7rem 1rem;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.5rem}
        .term-block-header .tl{display:flex;align-items:center;gap:.5rem;flex-wrap:wrap}
        .term-block-header .tb{background:rgba(255,255,255,.12);padding:.2rem .55rem;border-radius:50px;font-size:.7rem;font-weight:700}
        .term-block-header .tamt{display:flex;gap:1rem;align-items:center;flex-wrap:wrap}
        .term-block-header .tai{text-align:center}.term-block-header .tail{font-size:.55rem;text-transform:uppercase;opacity:.7;letter-spacing:.3px}.term-block-header .taiv{font-weight:800;font-size:.82rem}
        .tst{padding:.2rem .55rem;border-radius:50px;font-size:.65rem;font-weight:700;display:inline-flex;align-items:center;gap:.25rem}
        .tst.ok{background:rgba(16,185,129,.2);color:#34D399}.tst.no{background:rgba(239,68,68,.2);color:#F87171}
        .term-block.empty .term-block-header{background:#f1f5f9;color:#94a3b8}
        
        .term-block-body{padding:.5rem}
        .term-block-body table{width:100%;font-size:.78rem;margin:0}
        .term-block-body th{background:#f8fafc;color:#555;font-weight:600;font-size:.68rem;text-transform:uppercase;letter-spacing:.3px;padding:.55rem .45rem;border-bottom:2px solid #e2e8f0;white-space:nowrap}
        .term-block-body td{padding:.5rem .45rem;vertical-align:middle;border-bottom:1px solid #f1f5f9}
        .term-block-body tr:hover{background:#FFF8F0}
        .term-block-body tr.arr{background:#FFFBEB;font-weight:600}
        .term-block-body tr.arr td{border-top:2px solid #FCD34D;border-bottom:2px solid #FCD34D}
        .term-block-body tfoot td{font-weight:700;background:#fafafa;border-top:2px solid var(--dk)}
        
        .fb{display:inline-flex;align-items:center;gap:.25rem;padding:.15rem .45rem;border-radius:50px;font-size:.65rem;font-weight:600;white-space:nowrap}
        .fs{background:rgba(59,130,246,.1);color:#2563EB;border:1px solid rgba(59,130,246,.2)}
        .fc{background:rgba(245,158,11,.1);color:#D97706;border:1px solid rgba(245,158,11,.2)}
        .fl{background:rgba(16,185,129,.1);color:#059669;border:1px solid rgba(16,185,129,.2)}
        .fbk{background:rgba(139,92,246,.1);color:#7C3AED;border:1px solid rgba(139,92,246,.2)}
        
        .pwrap{background:var(--c);border-radius:10px;overflow:hidden;border:1px solid var(--b);box-shadow:0 1px 3px rgba(0,0,0,.04)}
        .pwrap table{width:100%;font-size:.78rem;margin:0}
        .pwrap th{background:#f8fafc;color:#555;font-weight:600;font-size:.68rem;text-transform:uppercase;letter-spacing:.3px;padding:.55rem .45rem;border-bottom:2px solid #e2e8f0}
        .pwrap td{padding:.5rem .45rem;vertical-align:middle;border-bottom:1px solid #f1f5f9}
        .pwrap tr:hover{background:#FFF8F0}
        .pwrap tfoot td{font-weight:700;background:#fafafa;border-top:2px solid var(--dk)}
        .mbadge{padding:.15rem .45rem;border-radius:50px;font-size:.65rem;font-weight:600;background:#f1f5f9;color:#64748B;white-space:nowrap}
        
        .empty{text-align:center;padding:2rem;color:#94a3b8}.empty i{font-size:2rem;margin-bottom:.5rem;color:#cbd5e1}
        .sect-title{font-size:1.05rem;font-weight:700;margin:1.8rem 0 .7rem;padding-bottom:.5rem;border-bottom:3px solid var(--pl);display:flex;align-items:center;gap:.4rem}
        
        @media(max-width:768px){
            .wrap{padding:.4rem}
            .sumgrid{grid-template-columns:1fr 1fr}.sc{padding:.7rem}.sc .vl{font-size:1rem}
            .term-block-header{flex-direction:column;align-items:flex-start}.term-block-header .tamt{width:100%;justify-content:space-between}
            .term-block-body table,.pwrap table{font-size:.7rem}.term-block-body th,.term-block-body td,.pwrap th,.pwrap td{padding:.35rem .25rem}
            .topbar{flex-direction:column;align-items:stretch}.yrsel{width:100%;justify-content:space-between}
            .arow{flex-direction:column}.btn-c{justify-content:center}
            .pcard{flex-direction:column;align-items:center;text-align:center}.pmeta{justify-content:center}
        }
        @media print{
            body{background:#fff}.topbar,.arow,.tgl,.btn-c{display:none!important}
            .term-block{break-inside:avoid;border:1px solid #ddd}
            .term-block-header{background:#333!important;-webkit-print-color-adjust:exact;print-color-adjust:exact}
            .year-heading{background:#fff!important}
        }
    </style>
</head>
<body>
    <?php $hdr = $_SERVER['DOCUMENT_ROOT'] . '/templates/admin_header.php'; if (file_exists($hdr)) include $hdr; ?>
    
    <div class="wrap">
        <!-- Top Bar -->
        <div class="topbar">
            <a href="fees_payments.php?academic_year=<?= urlencode($selected_academic_year) ?>" class="back"><i class="fas fa-arrow-left"></i> Back to Dashboard</a>
            <div class="yrsel">
                <span style="font-size:.75rem;color:#94a3b8;font-weight:600;">Year:</span>
                <select onchange="changeYear(this.value)">
                    <?php foreach ($all_years as $y): ?><option value="<?= htmlspecialchars($y) ?>" <?= $y===$selected_academic_year?'selected':'' ?>><?= htmlspecialchars($y) ?></option><?php endforeach; ?>
                    <?php if(empty($all_years)): ?><option>No fees</option><?php endif; ?>
                </select>
                <a href="?learner_id=<?= $learner_id ?>&academic_year=<?= urlencode($selected_academic_year) ?>&show_all=<?= $show_all?'0':'1' ?>" class="tgl <?= $show_all?'on':'' ?>">
                    <i class="fas <?= $show_all?'fa-compress':'fa-expand' ?>"></i> <?= $show_all?'Single Year':'All Years' ?>
                </a>
            </div>
        </div>
        
        <!-- Action Buttons -->
        <div class="arow">
            <a href="add_fee.php?learner_id=<?= $learner_id ?>" class="btn-c btn-p"><i class="fas fa-plus-circle"></i> Add Fee</a>
            <a href="pay_fee.php?learner_id=<?= $learner_id ?>&action=pay" class="btn-c btn-s"><i class="fas fa-money-bill-wave"></i> Record Payment</a>
            <button onclick="window.print()" class="btn-c btn-o"><i class="fas fa-print"></i> Print</button>
        </div>
        
        <!-- Profile Card -->
        <div class="pcard">
            <div class="av"><?= htmlspecialchars($initials) ?></div>
            <div class="pinfo">
                <h2 class="pname"><?= htmlspecialchars($learner_name) ?></h2>
                <div class="pmeta">
                    <span><i class="fas fa-id-card"></i> ID: <?= $learner_id ?></span>
                    <span><i class="fas fa-graduation-cap"></i> <?= htmlspecialchars($class_display) ?></span>
                    <span><i class="fas fa-layer-group"></i> <?= htmlspecialchars($class_level) ?></span>
                    <span><i class="fas fa-venus-mars"></i> <?= ucfirst($learner['gender']??'N/A') ?></span>
                    <span><i class="fas fa-calendar-plus"></i> Admitted: <?= $learner['admission_date']?date('M j, Y',strtotime($learner['admission_date'])):'N/A' ?></span>
                    <?php if(!empty($learner['parent_name'])): ?><span><i class="fas fa-user-friends"></i> <?= htmlspecialchars($learner['parent_name']) ?></span><?php endif; ?>
                </div>
                <span class="statbadge"><i class="fas fa-circle" style="font-size:.4rem"></i> <?= ucfirst($learner['status']??'Active') ?></span>
            </div>
        </div>
        
        <!-- Summary Cards -->
        <div class="sumgrid">
            <div class="sc f"><div class="ic"><i class="fas fa-file-invoice-dollar"></i></div><div class="vl">GH₵ <?= number_format($overall_total_fees,2) ?></div><div class="lb">Total Fees Billed</div></div>
            <div class="sc p"><div class="ic"><i class="fas fa-check-circle"></i></div><div class="vl">GH₵ <?= number_format($overall_total_paid,2) ?></div><div class="lb">Total Paid (<?= $overall_total_fees>0?round(($overall_total_paid/$overall_total_fees)*100):0 ?>%)</div></div>
            <div class="sc d"><div class="ic"><i class="fas fa-exclamation-circle"></i></div><div class="vl">GH₵ <?= number_format(abs($overall_total_due),2) ?></div><div class="lb"><?= $overall_total_due>=0?'Outstanding':'Overpaid' ?></div></div>
            <div class="sc b"><div class="ic"><i class="fas fa-balance-scale"></i></div><div class="vl" style="color:<?= $overall_balance>0?'#EF4444':'#10B981' ?>">GH₵ <?= number_format(abs($overall_balance),2) ?> <?= $overall_balance>0?'DR':($overall_balance<0?'CR':'') ?></div><div class="lb"><?= $overall_balance>0?'Amount Owed':($overall_balance<0?'Credit':'Settled') ?></div></div>
        </div>
        
        <!-- Banner -->
        <?php if($overall_balance>0): ?>
        <div class="banner r"><div class="bi"><i class="fas fa-exclamation-triangle"></i></div><div class="bt">Outstanding Balance — unpaid fees need attention.</div><div class="ba">GH₵ <?= number_format($overall_balance,2) ?></div></div>
        <?php elseif($overall_balance<0): ?>
        <div class="banner g"><div class="bi"><i class="fas fa-check-circle"></i></div><div class="bt">Credit Balance — overpayment available for future fees.</div><div class="ba">GH₵ <?= number_format(abs($overall_balance),2) ?> CR</div></div>
        <?php else: ?>
        <div class="banner g"><div class="bi"><i class="fas fa-check-circle"></i></div><div class="bt">All Fees Settled — no outstanding balance.</div><div class="ba">Fully Paid ✓</div></div>
        <?php endif; ?>
        
        <!-- Fee Breakdown - Always Visible, Grouped by Year -->
        <?php if(empty($all_term_data)): ?>
            <div class="term-block"><div class="term-block-body"><div class="empty"><i class="fas fa-inbox"></i><h5>No Fees Found</h5><p>No fees have been billed to this learner yet.</p><a href="add_fee.php?learner_id=<?= $learner_id ?>" class="btn-c btn-p mt-2"><i class="fas fa-plus"></i> Add First Fee</a></div></div></div>
        <?php else: ?>
            <?php 
            // Group terms by year
            $grouped = [];
            foreach($all_term_data as $td) {
                $grouped[$td['year']][] = $td;
            }
            foreach($grouped as $year => $terms): 
                // Calculate year totals
                $yf = 0; $yp = 0; foreach($terms as $t) { $yf += $t['total_fees']; $yp += $t['total_paid']; }
                $yd = $yf - $yp;
            ?>
            <!-- Year Heading -->
            <div class="year-heading">
                <i class="fas fa-calendar-alt"></i>
                <span>Academic Year: <?= htmlspecialchars($year) ?></span>
                <span style="margin-left:auto;font-size:.8rem;color:var(--tm);">
                    Fees: <strong style="color:var(--t)">GH₵ <?= number_format($yf,2) ?></strong> &nbsp;|&nbsp;
                    Paid: <strong style="color:var(--s)">GH₵ <?= number_format($yp,2) ?></strong> &nbsp;|&nbsp;
                    Balance: <strong style="color:<?= $yd>0?'var(--d)':'var(--s)' ?>">GH₵ <?= number_format($yd,2) ?></strong>
                </span>
            </div>
            
            <?php foreach($terms as $td): ?>
            <!-- Term Block - Always Visible -->
            <div class="term-block <?= !$td['has_data']?'empty':'' ?>">
                <div class="term-block-header">
                    <div class="tl">
                        <strong style="font-size:.9rem"><?= htmlspecialchars($td['term_name']) ?></strong>
                        <span class="tb"><i class="fas fa-calendar"></i> Term <?= $td['term_number'] ?></span>
                    </div>
                    <div class="tamt">
                        <div class="tai"><div class="tail">Fees</div><div class="taiv">GH₵ <?= number_format($td['total_fees'],2) ?></div></div>
                        <div class="tai"><div class="tail">Paid</div><div class="taiv" style="color:#34D399">GH₵ <?= number_format($td['total_paid'],2) ?></div></div>
                        <div class="tai"><div class="tail">Balance</div><div class="taiv" style="color:<?= $td['balance']<=0?'#34D399':'#F87171' ?>">GH₵ <?= number_format($td['balance'],2) ?></div></div>
                        <span class="tst <?= $td['is_fully_paid']?'ok':'no' ?>"><i class="fas <?= $td['is_fully_paid']?'fa-check-circle':'fa-times-circle' ?>"></i> <?= $td['is_fully_paid']?'Settled':'Due' ?></span>
                    </div>
                </div>
                
                <?php if($td['has_data']): ?>
                <div class="term-block-body">
                    <div class="table-responsive">
                        <table>
                            <thead><tr><th>#</th><th>Fee Type</th><th>Category</th><th>Dept</th><th>Amount</th><th>Paid</th><th>Balance</th><th>Due Date</th><th>Status</th></tr></thead>
                            <tbody>
                                <?php if($td['arrears_brought_forward']>0): ?>
                                <tr class="arr">
                                    <td colspan="3"><i class="fas fa-arrow-right text-warning me-1"></i><strong>Arrears Brought Forward</strong><small class="text-muted ms-1">(from previous term)</small></td>
                                    <td>-</td>
                                    <td class="text-warning"><strong><?= number_format($td['arrears_brought_forward'],2) ?></strong></td>
                                    <td>-</td>
                                    <td class="text-warning"><strong><?= number_format($td['arrears_brought_forward'],2) ?></strong></td>
                                    <td>-</td>
                                    <td><span class="badge bg-warning text-dark">Carried Over</span></td>
                                </tr>
                                <?php endif; ?>
                                
                                <?php $fc=1; foreach($td['fees'] as $fee): 
                                    $fb=$fee['amount']-$fee['amount_paid']; 
                                    $fs=$fb<=0?'Paid':($fee['amount_paid']>0?'Partial':'Unpaid'); 
                                    $fsc=$fb<=0?'success':($fee['amount_paid']>0?'warning':'danger');
                                    $tgt=match($fee['target_type']){'School'=>'fs','Class'=>'fc','Bulk Learners'=>'fbk',default=>'fl'};
                                    $tgti=match($fee['target_type']){'School'=>'fa-school','Class'=>'fa-users','Bulk Learners'=>'fa-layer-group',default=>'fa-user'};
                                ?>
                                <tr>
                                    <td><?= $fc++ ?></td>
                                    <td><strong><?= htmlspecialchars($fee['fee_type']) ?></strong></td>
                                    <td><span class="fb <?= $tgt ?>"><i class="fas <?= $tgti ?>"></i> <?= htmlspecialchars($fee['target_type']) ?></span></td>
                                    <td><?= htmlspecialchars($fee['department']??'N/A') ?></td>
                                    <td><strong>GH₵ <?= number_format($fee['amount'],2) ?></strong></td>
                                    <td class="text-success"><strong>GH₵ <?= number_format($fee['amount_paid'],2) ?></strong></td>
                                    <td class="<?= $fb>0?'text-danger':'text-success' ?>"><strong>GH₵ <?= number_format($fb,2) ?></strong></td>
                                    <td><?= $fee['due_date']?date('M j, Y',strtotime($fee['due_date'])):'N/A' ?></td>
                                    <td><span class="badge bg-<?= $fsc ?>"><?= $fs ?></span></td>
                                </tr>
                                <?php endforeach; ?>
                                
                                <?php foreach($td['standalone_payments'] as $sp): ?>
                                <tr style="background:#ECFDF5">
                                    <td>-</td>
                                    <td colspan="3"><i class="fas fa-money-bill-wave text-success me-1"></i><strong>Payment Received</strong><br><small class="text-muted"><?= htmlspecialchars($sp['payment_method']) ?> <?= $sp['receipt_number']?'· #'.htmlspecialchars($sp['receipt_number']):'' ?></small></td>
                                    <td>-</td>
                                    <td class="text-success"><strong>GH₵ <?= number_format($sp['amount_paid'],2) ?></strong></td>
                                    <td class="text-success"><strong>GH₵ <?= number_format($sp['amount_paid'],2) ?></strong></td>
                                    <td><?= date('M j, Y',strtotime($sp['payment_date'])) ?></td>
                                    <td><span class="badge bg-success">Paid</span></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot>
                                <tr>
                                    <td colspan="4" class="text-end"><strong>Term Totals:</strong></td>
                                    <td><strong>GH₵ <?= number_format($td['total_fees'],2) ?></strong></td>
                                    <td class="text-success"><strong>GH₵ <?= number_format($td['total_paid'],2) ?></strong></td>
                                    <td class="<?= $td['balance']>0?'text-danger':'text-success' ?>"><strong>GH₵ <?= number_format($td['balance'],2) ?></strong><?= $td['balance']>0?' <small>(→ next term)</small>':'' ?></td>
                                    <td colspan="2"></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
                <?php else: ?>
                <div class="term-block-body"><div class="empty"><i class="fas fa-inbox"></i><p>No fees or payments for this term.</p></div></div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
            <?php endforeach; ?>
        <?php endif; ?>
        
        <!-- Payment History -->
        <h3 class="sect-title"><i class="fas fa-history"></i> Payment History (All Payments)</h3>
        <div class="pwrap">
            <?php if(empty($payment_history)): ?>
                <div class="empty"><i class="fas fa-receipt"></i><h5>No Payments</h5><p>No payment records found for this learner.</p></div>
            <?php else: ?>
                <div class="table-responsive">
                    <table>
                        <thead><tr><th>#</th><th>Date</th><th>Fee Type</th><th>Amount</th><th>Method</th><th>Receipt #</th><th>Year</th><th>Recorded By</th></tr></thead>
                        <tbody>
                            <?php $pc=1;$tp=0;foreach($payment_history as $p):$tp+=$p['amount_paid'];
                                $mi=match(strtolower($p['payment_method']??'')){'cash'=>'fa-money-bill-wave','bank transfer','bank_transfer'=>'fa-university','mobile money','momo'=>'fa-mobile-alt','cheque','check'=>'fa-money-check',default=>'fa-credit-card'};
                            ?>
                            <tr>
                                <td><?= $pc++ ?></td>
                                <td><?= date('M j, Y',strtotime($p['payment_date'])) ?></td>
                                <td><?= htmlspecialchars($p['fee_type']??'Standalone') ?></td>
                                <td class="text-success"><strong>GH₵ <?= number_format($p['amount_paid'],2) ?></strong></td>
                                <td><span class="mbadge"><i class="fas <?= $mi ?>"></i> <?= htmlspecialchars($p['payment_method']??'N/A') ?></span></td>
                                <td><?= htmlspecialchars($p['receipt_number']??'-') ?></td>
                                <td><?= htmlspecialchars($p['academic_year']??'-') ?></td>
                                <td><?= htmlspecialchars($p['rb']??'System') ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot><tr><td colspan="3" class="text-end"><strong>Total Payments:</strong></td><td class="text-success"><strong>GH₵ <?= number_format($tp,2) ?></strong></td><td colspan="4"></td></tr></tfoot>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <?php $ftr = $_SERVER['DOCUMENT_ROOT'] . '/templates/admin_footer.php'; if (file_exists($ftr)) include $ftr; ?>
    
    <script>
        function changeYear(y){window.location.href=`fee_details.php?learner_id=<?= $learner_id ?>&academic_year=${encodeURIComponent(y)}&show_all=<?= $show_all?'1':'0' ?>`;}
    </script>
</body>
</html>