<?php
ob_start();
if (session_status() === PHP_SESSION_NONE) { session_start(); }

/* =================================================
   SECURITY & SESSION VALIDATION
   ================================================= */
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    $_SESSION['error_msg'] = 'Please log in as an admin.';
    header('Location: /login.php'); 
    exit();
}

$admin_id = (int)$_SESSION['user_id'];

/* =================================================
   DATABASE CONNECTION
   ================================================= */
require $_SERVER['DOCUMENT_ROOT'] . '/config/db.php';

if (!$conn || $conn->connect_error) {
    error_log('DB connection failed: ' . $conn->connect_error);
    $_SESSION['error_msg'] = 'Database error.';
    header('Location: /login.php'); 
    exit();
}

/* =================================================
   CSRF PROTECTION
   ================================================= */
function generateCsrfToken($conn, $user_id): string {
    $token = bin2hex(random_bytes(32));
    $stmt = $conn->prepare("INSERT INTO csrf_tokens (user_id, token, created_at, expires_at)
        VALUES (?, ?, NOW(), DATE_ADD(NOW(), INTERVAL 1 HOUR))
        ON DUPLICATE KEY UPDATE token = VALUES(token), created_at = NOW(), expires_at = VALUES(expires_at)");
    $stmt->bind_param("is", $user_id, $token);
    $stmt->execute();
    $stmt->close();
    $conn->query("DELETE FROM csrf_tokens WHERE expires_at < NOW()");
    return $token;
}

function validateCsrfToken($conn, $user_id, $token): bool {
    $stmt = $conn->prepare("SELECT 1 FROM csrf_tokens WHERE user_id = ? AND token = ? AND expires_at > NOW()");
    $stmt->bind_param("is", $user_id, $token);
    $stmt->execute();
    $result = $stmt->get_result();
    $valid = $result->num_rows === 1;
    $stmt->close();
    return $valid;
}

$csrf_token = generateCsrfToken($conn, $admin_id);

/* =================================================
   ACADEMIC TERM MANAGEMENT
   ================================================= */
$today = new DateTime();
$current_academic_year = $today->format('Y') . '/' . ($today->format('Y') + 1);
$term_session_key = "current_term_$admin_id";

if (!isset($_SESSION[$term_session_key])) {
    $query = $conn->query("SELECT id FROM academic_calendar WHERE start_date <= CURDATE() AND end_date >= CURDATE() LIMIT 1");
    $_SESSION[$term_session_key] = ($query && $query->num_rows) ? $query->fetch_assoc() : ['id' => 1];
}

$current_term_id = $_SESSION[$term_session_key]['id'] ?? 1;

$stmt = $conn->prepare("SELECT term_name FROM academic_calendar WHERE id = ?");
$stmt->bind_param("i", $current_term_id);
$stmt->execute();
$current_term = $stmt->get_result()->fetch_assoc() ?? ['term_name' => 'Current Term'];
$stmt->close();

/* =================================================
   MAJESTY CLASS CONFIGURATION
   ================================================= */
$MAJESTY_CLASS_ID = null;
$MAJESTY_DEPT_ID = null;

$stmt = $conn->prepare("SELECT id, department_id FROM classes WHERE LOWER(class_name) LIKE '%majesty%' LIMIT 1");
$stmt->execute();
$result = $stmt->get_result();

if ($row = $result->fetch_assoc()) {
    $MAJESTY_CLASS_ID = (int)$row['id'];
    $MAJESTY_DEPT_ID = (int)$row['department_id'];
}
$stmt->close();

if (!$MAJESTY_CLASS_ID || !$MAJESTY_DEPT_ID) {
    echo '<div class="bg-orange-50 border-l-4 border-orange-500 p-4 rounded-lg">';
    echo '<h2 class="text-lg font-semibold text-orange-800">Configuration Error</h2>';
    echo '<p class="text-orange-700">Majesty class or department not found in the database.</p>';
    echo '</div>';
    exit();
}

/* =================================================
   SUBJECT CONFIGURATION
   ================================================= */
$core_subject_ids = [13, 9, 7, 3];
$elective_subject_ids = [4, 5, 8, 10, 11, 14];
$blocked_subjects = ['Music', 'Chinese'];

/* =================================================
   GRADE CALCULATION FUNCTIONS
   ================================================= */
function calculateGradeFromScore(?float $score): ?int {
    if ($score === null) return null;
    
    return match(true) {
        $score >= 95 => 1,
        $score >= 85 => 2,
        $score >= 75 => 3,
        $score >= 65 => 4,
        $score >= 55 => 5,
        $score >= 45 => 6,
        $score >= 36 => 7,
        $score >= 31 => 8,
        default => 9
    };
}

function getGradeRemark(int $grade): string {
    $remarks = [
        1 => 'Highest',
        2 => 'Higher',
        3 => 'High',
        4 => 'High Average',
        5 => 'Average',
        6 => 'Low Average',
        7 => 'Low',
        8 => 'Lower',
        9 => 'Lowest'
    ];
    return $remarks[$grade] ?? '-';
}

/* =================================================
   AI SUMMARY GENERATION
   ================================================= */
function generatePerformanceSummary(float $average_score, int $total_subjects, int $scored_subjects, array $subject_scores): string {
    $strengths = [];
    $weaknesses = [];
    
    foreach ($subject_scores as $subject) {
        if ($subject['score'] >= 70) $strengths[] = $subject['subject'];
        if ($subject['score'] < 50) $weaknesses[] = $subject['subject'];
    }
    
    $summary = "Overall, the student achieved an average of {$average_score}% across {$scored_subjects} out of {$total_subjects} subjects. ";
    
    $summary .= match(true) {
        $average_score >= 75 => "This reflects strong academic performance with consistent high marks. ",
        $average_score >= 60 => "The student demonstrates solid competence in most areas. ",
        $average_score >= 45 => "Performance is moderate, showing room for improvement. ",
        default => "The results indicate significant challenges that require immediate support. "
    };
    
    if (!empty($strengths)) {
        $top_strengths = array_slice($strengths, 0, 3);
        $summary .= "Notable strengths include " . implode(', ', $top_strengths);
        $summary .= count($strengths) > 3 ? ', and others' : '';
        $summary .= ". ";
    }
    
    if (!empty($weaknesses)) {
        $top_weaknesses = array_slice($weaknesses, 0, 3);
        $summary .= "Areas needing attention are " . implode(', ', $top_weaknesses);
        $summary .= count($weaknesses) > 3 ? ', and others' : '';
        $summary .= ". ";
    }
    
    $summary .= "Continued effort and targeted revision will help elevate future outcomes.";
    return $summary;
}

/* =================================================
   AJAX REQUEST HANDLERS
   ================================================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    ob_clean();
    header('Content-Type: application/json');
    
    $csrf = $_POST['csrf_token'] ?? '';
    if (!validateCsrfToken($conn, $admin_id, $csrf)) {
        echo json_encode(['success' => false, 'message' => 'Invalid or expired security token']);
        exit;
    }

    switch ($_POST['action']) {
        case 'get_students':
            handleGetStudents($conn, $MAJESTY_CLASS_ID, $current_academic_year);
            break;
        case 'load_student_report':
            handleLoadStudentReport(
                $conn, 
                $MAJESTY_CLASS_ID, 
                $MAJESTY_DEPT_ID, 
                $current_academic_year,
                $current_term,
                $core_subject_ids,
                $elective_subject_ids,
                $blocked_subjects
            );
            break;
        default:
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
    }
    exit;
}

function handleGetStudents($conn, $class_id, $academic_year) {
    $term_id = (int)$_POST['term_id'];
    $mock_number = (int)$_POST['mock_number'];
    $year_prefix = substr($academic_year, 0, 4);
    
    $sql = "SELECT DISTINCT u.id, u.name, u.profile_picture
            FROM users u
            JOIN student_classes sc ON u.id = sc.user_id
            WHERE sc.class_id = ?
              AND (sc.academic_year = ? OR sc.academic_year = ?)
              AND u.role = 'student'
            ORDER BY u.name";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("iss", $class_id, $academic_year, $year_prefix);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $students = [];
    while ($row = $result->fetch_assoc()) {
        $students[] = $row;
    }
    $stmt->close();
    
    echo json_encode([
        'success' => !empty($students),
        'students' => $students
    ]);
}

function handleLoadStudentReport($conn, $class_id, $dept_id, $academic_year, $current_term, $core_ids, $elective_ids, $blocked) {
    $student_id = (int)$_POST['student_id'];
    $mock_number = (int)$_POST['mock_number'];
    $term_id = (int)$_POST['term_id'];
    $year_prefix = substr($academic_year, 0, 4);
    
    // Get student info
    $stmt = $conn->prepare("SELECT name, profile_picture FROM users WHERE id = ? AND role = 'student'");
    $stmt->bind_param("i", $student_id);
    $stmt->execute();
    $student = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    if (!$student) {
        echo json_encode(['success' => false, 'message' => 'Student not found']);
        return;
    }
    
    $student_name = $student['name'];
    $profile_picture = $student['profile_picture'] ?: '/assets/images/default-avatar.png';
    
    // Get all subjects
    $placeholders = implode(',', array_fill(0, count($blocked), '?'));
    $sql = "SELECT id, subject_name 
            FROM subjects 
            WHERE department_id = ? 
              AND subject_name NOT IN ($placeholders) 
            ORDER BY FIELD(id, " . implode(',', $core_ids) . "), subject_name";
    
    $types = 'i' . str_repeat('s', count($blocked));
    $params = array_merge([$dept_id], $blocked);
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $all_subjects = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    // Get scores
    $placeholders = implode(',', array_fill(0, count($blocked), '?'));
    $sql = "
        SELECT s.id AS subject_id, s.subject_name AS subject, 
               me.mock_raw AS score, me.mock_grade AS grade_num
        FROM mock_entries me
        JOIN subjects s ON me.subject_id = s.id
        WHERE me.student_id = ?
          AND me.class_id = ?
          AND me.term_id = ?
          AND me.mock_number = ?
          AND (me.academic_year = ? OR me.academic_year = ?)
          AND s.subject_name NOT IN ($placeholders)
    ";
    
    $types = 'iiiiss' . str_repeat('s', count($blocked));
    $params = [$student_id, $class_id, $term_id, $mock_number, $academic_year, $year_prefix];
    $params = array_merge($params, $blocked);
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $scores_result = $stmt->get_result();
    
    $scores = [];
    while ($row = $scores_result->fetch_assoc()) {
        $scores[$row['subject_id']] = $row;
    }
    $stmt->close();
    
    // Process scores
    $total_score = 0;
    $scored_count = 0;
    $subject_data = [];
    $core_grades = [];
    $elective_grades = [];
    $table_rows = '';
    
    foreach ($all_subjects as $subject) {
        $subject_id = (int)$subject['id'];
        $subject_name = $subject['subject_name'];
        $entry = $scores[$subject_id] ?? null;
        
        $score = $entry['score'] ?? null;
        $grade_num = $entry['grade_num'] ?? ($score !== null ? calculateGradeFromScore($score) : null);
        $remark = $grade_num ? getGradeRemark($grade_num) : '-';
        
        if ($score !== null) {
            $total_score += (float)$score;
            $scored_count++;
            $subject_data[] = ['subject' => $subject_name, 'score' => $score];
        }
        
        if ($grade_num !== null) {
            if (in_array($subject_id, $core_ids)) {
                $core_grades[$subject_id] = $grade_num;
            } elseif (in_array($subject_id, $elective_ids)) {
                $elective_grades[$subject_id] = $grade_num;
            }
        }
        
        // Score color based on performance
        $score_color = 'text-gray-900';
        $bg_color = '';
        if ($score !== null) {
            if ($score >= 75) {
                $score_color = 'text-emerald-700';
                $bg_color = 'bg-emerald-50';
            } elseif ($score >= 60) {
                $score_color = 'text-orange-700';
                $bg_color = 'bg-orange-50';
            } elseif ($score >= 45) {
                $score_color = 'text-amber-700';
                $bg_color = 'bg-amber-50';
            } else {
                $score_color = 'text-rose-700';
                $bg_color = 'bg-rose-50';
            }
        }
        
        $table_rows .= "<tr class=\"border-b border-orange-100 hover:{$bg_color} transition-colors\">
            <td class=\"p-3 text-sm font-medium text-gray-800\">" . htmlspecialchars($subject_name) . "</td>
            <td class=\"p-3 text-center text-sm font-semibold {$score_color}\">" . ($score !== null ? round($score, 1) : '-') . "</td>
            <td class=\"p-3 text-center text-sm text-gray-700\">" . ($grade_num ?? '-') . "</td>
            <td class=\"p-3 text-sm text-gray-600\">" . htmlspecialchars($remark) . "</td>
        </tr>";
    }
    
    // Calculate aggregate
    $aggregate = 0;
    foreach ($core_ids as $core_id) {
        $grade = $core_grades[$core_id] ?? null;
        if ($grade === null && isset($scores[$core_id])) {
            $grade = calculateGradeFromScore($scores[$core_id]['score']);
        }
        $aggregate += $grade ?? 9;
    }
    
    $elective_values = [];
    foreach ($elective_ids as $elective_id) {
        if (isset($elective_grades[$elective_id])) {
            $elective_values[] = $elective_grades[$elective_id];
        } elseif (isset($scores[$elective_id])) {
            $elective_values[] = calculateGradeFromScore($scores[$elective_id]['score']);
        }
    }
    
    sort($elective_values);
    for ($i = 0; $i < min(2, count($elective_values)); $i++) {
        $aggregate += $elective_values[$i];
    }
    
    $average_score = $scored_count ? round($total_score / $scored_count, 2) : 0;
    $ai_summary = generatePerformanceSummary($average_score, count($all_subjects), $scored_count, $subject_data);
    
    // Performance level
    $performance_level = match(true) {
        $aggregate <= 18 => 'Outstanding',
        $aggregate <= 24 => 'Excellent',
        $aggregate <= 30 => 'Very Good',
        $aggregate <= 36 => 'Good',
        $aggregate <= 42 => 'Fair',
        default => 'Needs Support'
    };
    
    $report_html = generateReportHTML(
        $student_name,
        $profile_picture,
        $academic_year,
        $mock_number,
        $current_term['term_name'],
        $table_rows,
        $total_score,
        $aggregate,
        $performance_level,
        $average_score,
        $ai_summary,
        $scored_count
    );
    
    echo json_encode([
        'success' => true,
        'report' => $report_html
    ]);
}

function generateReportHTML($student_name, $profile_picture, $academic_year, $mock_number, $term_name, $table_rows, $total_score, $aggregate, $performance_level, $average_score, $ai_summary, $scored_count) {
    $logo_version = time();
    
    $html = '
    <div class="bg-white rounded-2xl shadow-2xl overflow-hidden border border-orange-100">
        <!-- Orange Header -->
        <div class="bg-gradient-to-r from-orange-600 to-orange-500 px-8 py-6">
            <div class="flex justify-between items-center">
                <div>
                    <h1 class="text-3xl font-bold text-white tracking-tight">QUEENEST PARADISE</h1>
                    <p class="text-orange-100 text-lg font-medium">MONTESSORI COMPLEX</p>
                </div>
                <div class="text-right">
                    <div class="bg-white/20 backdrop-blur-sm rounded-lg px-4 py-2">
                        <p class="text-white text-sm font-medium">Academic Year</p>
                        <p class="text-white text-xl font-bold">' . htmlspecialchars($academic_year) . '</p>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Student Profile Section -->
        <div class="px-8 py-6 bg-gradient-to-r from-orange-50 to-amber-50 border-b border-orange-100">
            <div class="flex items-center gap-6">
                <div class="relative">
                    <img src="' . htmlspecialchars($profile_picture) . '" 
                         alt="Student" 
                         class="w-28 h-28 rounded-2xl object-cover border-4 border-white shadow-lg"
                         onerror="this.src=\'/assets/images/default-avatar.png\'">
                    <div class="absolute -bottom-2 -right-2 bg-orange-500 text-white text-xs px-3 py-1 rounded-full shadow-lg">
                        Mock ' . $mock_number . '
                    </div>
                </div>
                <div class="flex-1">
                    <h2 class="text-3xl font-bold text-gray-800 mb-2">' . htmlspecialchars($student_name) . '</h2>
                    <div class="flex flex-wrap gap-4">
                        <div class="bg-white px-4 py-2 rounded-lg shadow-sm">
                            <span class="text-sm text-gray-500">Class</span>
                            <p class="font-semibold text-gray-800">Majesty</p>
                        </div>
                        <div class="bg-white px-4 py-2 rounded-lg shadow-sm">
                            <span class="text-sm text-gray-500">Term</span>
                            <p class="font-semibold text-gray-800">' . htmlspecialchars($term_name) . '</p>
                        </div>
                        <div class="bg-white px-4 py-2 rounded-lg shadow-sm">
                            <span class="text-sm text-gray-500">Date</span>
                            <p class="font-semibold text-gray-800">' . date('d M Y') . '</p>
                        </div>
                    </div>
                </div>
                <div class="text-center">
                    <div class="bg-orange-100 rounded-2xl px-6 py-3">
                        <span class="text-sm text-orange-600">Performance</span>
                        <p class="text-2xl font-bold text-orange-700">' . $performance_level . '</p>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Stats Cards -->
        <div class="grid grid-cols-4 gap-4 px-8 py-6 bg-white">
            <div class="bg-orange-50 rounded-xl p-4 text-center border border-orange-100">
                <span class="text-sm text-orange-600 font-medium">Total Score</span>
                <p class="text-3xl font-bold text-orange-700">' . round($total_score, 1) . '</p>
                <span class="text-xs text-gray-500">out of 1000</span>
            </div>
            <div class="bg-orange-50 rounded-xl p-4 text-center border border-orange-100">
                <span class="text-sm text-orange-600 font-medium">Average</span>
                <p class="text-3xl font-bold text-orange-700">' . $average_score . '%</p>
                <span class="text-xs text-gray-500">' . $scored_count . ' subjects</span>
            </div>
            <div class="bg-orange-50 rounded-xl p-4 text-center border border-orange-100">
                <span class="text-sm text-orange-600 font-medium">Aggregate</span>
                <p class="text-3xl font-bold text-orange-700">' . $aggregate . '</p>
                <span class="text-xs text-gray-500">overall score</span>
            </div>
            <div class="bg-orange-50 rounded-xl p-4 text-center border border-orange-100">
                <span class="text-sm text-orange-600 font-medium">Class Rank</span>
                <p class="text-3xl font-bold text-orange-700">' . ($aggregate <= 24 ? '🏆' : '📚') . '</p>
                <span class="text-xs text-gray-500">' . ($aggregate <= 24 ? 'Top Performer' : 'On Track') . '</span>
            </div>
        </div>
        
        <!-- Results Table -->
        <div class="px-8 py-6">
            <h3 class="text-lg font-semibold text-gray-800 mb-4 flex items-center gap-2">
                <span class="w-1 h-6 bg-orange-500 rounded-full"></span>
                Subject Results
            </h3>
            <div class="overflow-x-auto rounded-xl border border-orange-100">
                <table class="w-full">
                    <thead>
                        <tr class="bg-orange-500 text-white">
                            <th class="px-4 py-3 text-left text-sm font-semibold">Subject</th>
                            <th class="px-4 py-3 text-center text-sm font-semibold">Score (%)</th>
                            <th class="px-4 py-3 text-center text-sm font-semibold">Grade</th>
                            <th class="px-4 py-3 text-left text-sm font-semibold">Remarks</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-orange-100">
                        ' . $table_rows . '
                    </tbody>
                </table>
            </div>
        </div>
        
        <!-- AI Summary Card -->
        <div class="px-8 py-6 bg-gradient-to-br from-orange-50 to-white border-t border-orange-100">
            <div class="flex gap-4">
                <div class="w-10 h-10 rounded-full bg-orange-100 flex items-center justify-center flex-shrink-0">
                    <span class="text-orange-500 text-xl">🤖</span>
                </div>
                <div>
                    <h4 class="font-semibold text-gray-800 mb-2">Performance Analysis</h4>
                    <p class="text-gray-700 text-sm leading-relaxed">' . nl2br(htmlspecialchars($ai_summary)) . '</p>
                </div>
            </div>
        </div>
        
        <!-- Footer with Signature -->
        <div class="px-8 py-6 bg-gray-50 border-t border-orange-100">
            <div class="flex justify-between items-end">
                <div>
                    <p class="text-sm text-gray-500">Class Teacher</p>
                    <div class="mt-2 w-40 h-12 bg-white rounded border border-orange-200"></div>
                    <p class="text-xs text-gray-400 mt-1">Signature</p>
                </div>
                <div class="text-center">
                    <p class="text-sm text-gray-500">Head of Academics</p>
                    <img src="/assets/images/academics.png?v=' . time() . '" alt="Signature" class="h-12 mx-auto">
                    <p class="text-xs font-medium text-gray-700 mt-1">Francis Kofi Adjei</p>
                </div>
                <div class="text-right">
                    <p class="text-sm text-gray-500">Issue Date</p>
                    <p class="text-lg font-semibold text-gray-800">' . date('d/m/Y') . '</p>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Action Buttons -->
    <div class="flex justify-center gap-4 mt-8">
        <button onclick="window.print()" class="px-6 py-3 bg-orange-500 hover:bg-orange-600 text-white rounded-xl font-medium transition-all shadow-lg hover:shadow-xl flex items-center gap-2">
            <i class="fas fa-print"></i>
            Print Report
        </button>
        <button onclick="downloadPDF()" class="px-6 py-3 bg-white hover:bg-gray-50 text-orange-600 rounded-xl font-medium transition-all shadow-lg hover:shadow-xl border-2 border-orange-500 flex items-center gap-2">
            <i class="fas fa-download"></i>
            Download PDF
        </button>
        <button onclick="resetForm()" class="px-6 py-3 bg-gray-100 hover:bg-gray-200 text-gray-700 rounded-xl font-medium transition-all shadow-lg hover:shadow-xl flex items-center gap-2">
            <i class="fas fa-redo"></i>
            New Report
        </button>
    </div>';
    
    return $html;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mock Report Card – Majesty Class</title>
    
    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>
    
    <!-- Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"/>
    
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: {
                        sans: ['Inter', 'sans-serif']
                    },
                    colors: {
                        orange: {
                            50: '#fff7ed',
                            100: '#ffedd5',
                            200: '#fed7aa',
                            300: '#fdba74',
                            400: '#fb923c',
                            500: '#f97316',
                            600: '#ea580c',
                            700: '#c2410c',
                            800: '#9a3412',
                            900: '#7c2d12'
                        }
                    }
                }
            }
        };
    </script>
    
    <style>
        @media print {
            body * {
                visibility: hidden;
            }
            #print-container,
            #print-container * {
                visibility: visible;
            }
            #print-container {
                position: absolute;
                left: 0;
                top: 0;
                width: 100%;
            }
            .no-print {
                display: none !important;
            }
            @page {
                size: A4;
                margin: 0.5cm;
            }
        }
        
        .glass-card {
            background: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(249, 115, 22, 0.2);
        }
        
        .student-card {
            transition: all 0.2s ease;
            border: 2px solid transparent;
        }
        
        .student-card:hover {
            transform: translateY(-2px);
            border-color: #f97316;
            box-shadow: 0 20px 25px -5px rgba(249, 115, 22, 0.2);
        }
        
        .student-card.selected {
            border-color: #f97316;
            background: linear-gradient(to right, #fff7ed, white);
        }
        
        .loading {
            position: relative;
            pointer-events: none;
            opacity: 0.7;
        }
        
        .loading::after {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            width: 20px;
            height: 20px;
            margin: -10px 0 0 -10px;
            border: 2px solid #f97316;
            border-top-color: transparent;
            border-radius: 50%;
            animation: spinner 0.6s linear infinite;
        }
        
        @keyframes spinner {
            to { transform: rotate(360deg); }
        }
        
        .fade-in {
            animation: fadeIn 0.3s ease-out;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        /* Custom scrollbar */
        ::-webkit-scrollbar {
            width: 8px;
            height: 8px;
        }
        
        ::-webkit-scrollbar-track {
            background: #fff7ed;
        }
        
        ::-webkit-scrollbar-thumb {
            background: #f97316;
            border-radius: 4px;
        }
        
        ::-webkit-scrollbar-thumb:hover {
            background: #ea580c;
        }
    </style>
</head>
<body class="min-h-screen bg-gradient-to-br from-orange-50 via-white to-orange-100">
    
    <!-- Main Container -->
    <div class="max-w-7xl mx-auto px-4 py-8">
        
        <!-- Header -->
        <div class="mb-8">
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-4xl font-extrabold text-transparent bg-clip-text bg-gradient-to-r from-orange-600 to-orange-400">
                        Majesty Class
                    </h1>
                    <p class="text-gray-600 mt-2 flex items-center gap-2">
                        <i class="fas fa-calendar-alt text-orange-500"></i>
                        <?= htmlspecialchars($current_term['term_name']) ?> | <?= htmlspecialchars($current_academic_year) ?>
                    </p>
                </div>
                <div class="flex items-center gap-3">
                    <div class="h-12 w-12 rounded-full bg-orange-100 flex items-center justify-center">
                        <i class="fas fa-user-graduate text-orange-500 text-xl"></i>
                    </div>
                    <div>
                        <p class="text-sm text-gray-500">Welcome,</p>
                        <p class="font-semibold text-gray-800">Admin</p>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Filter Section -->
        <div class="bg-white rounded-2xl shadow-xl p-6 mb-8 border border-orange-100">
            <h2 class="text-lg font-semibold text-gray-800 mb-4 flex items-center gap-2">
                <span class="w-1 h-5 bg-orange-500 rounded-full"></span>
                Report Filters
            </h2>
            <div class="grid md:grid-cols-4 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">
                        <i class="fas fa-calendar mr-2 text-orange-500"></i>Term
                    </label>
                    <select id="term-filter" class="w-full px-4 py-3 rounded-xl border border-orange-200 focus:ring-2 focus:ring-orange-500 focus:border-transparent bg-white">
                        <option value="<?= $current_term_id ?>"><?= htmlspecialchars($current_term['term_name']) ?></option>
                        <?php
                        $terms = $conn->query("SELECT id, term_name FROM academic_calendar ORDER BY id DESC");
                        while ($term = $terms->fetch_assoc()) {
                            if ($term['id'] != $current_term_id) {
                                echo "<option value=\"{$term['id']}\">" . htmlspecialchars($term['term_name']) . "</option>";
                            }
                        }
                        ?>
                    </select>
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">
                        <i class="fas fa-hashtag mr-2 text-orange-500"></i>Mock Number
                    </label>
                    <input type="number" id="mock-filter" min="1" value="1" class="w-full px-4 py-3 rounded-xl border border-orange-200 focus:ring-2 focus:ring-orange-500 focus:border-transparent">
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">
                        <i class="fas fa-search mr-2 text-orange-500"></i>Search Student
                    </label>
                    <input type="text" id="student-search" placeholder="Type to filter..." class="w-full px-4 py-3 rounded-xl border border-orange-200 focus:ring-2 focus:ring-orange-500 focus:border-transparent" disabled>
                </div>
                
                <div class="flex items-end">
                    <button type="button" id="load-students" class="w-full px-6 py-3 bg-gradient-to-r from-orange-500 to-orange-600 hover:from-orange-600 hover:to-orange-700 text-white rounded-xl font-medium transition-all shadow-lg hover:shadow-xl flex items-center justify-center gap-2">
                        <i class="fas fa-sync-alt"></i>
                        Load Students
                    </button>
                </div>
            </div>
        </div>
        
        <!-- Student Grid -->
        <div id="student-grid" class="hidden mb-8">
            <div class="flex justify-between items-center mb-4">
                <h2 class="text-lg font-semibold text-gray-800 flex items-center gap-2">
                    <span class="w-1 h-5 bg-orange-500 rounded-full"></span>
                    Select Student
                </h2>
                <span class="text-sm bg-orange-100 text-orange-700 px-3 py-1 rounded-full" id="student-count"></span>
            </div>
            <div id="student-cards" class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5 gap-4">
                <!-- Student cards will be inserted here -->
            </div>
        </div>
        
        <!-- Report Card Container -->
        <div id="report-card" class="hidden fade-in"></div>
        
        <!-- Toast Notification -->
        <div id="toast" class="fixed bottom-4 right-4 bg-orange-600 text-white px-6 py-3 rounded-xl shadow-2xl transform transition-transform duration-300 translate-y-20 flex items-center gap-2 z-50">
            <i class="fas fa-info-circle"></i>
            <span id="toast-message"></span>
        </div>
    </div>
    
    <script>
        const csrfToken = '<?= addslashes($csrf_token) ?>';
        
        // DOM Elements
        const loadStudentsBtn = document.getElementById('load-students');
        const termFilter = document.getElementById('term-filter');
        const mockFilter = document.getElementById('mock-filter');
        const studentSearch = document.getElementById('student-search');
        const studentGrid = document.getElementById('student-grid');
        const studentCards = document.getElementById('student-cards');
        const studentCount = document.getElementById('student-count');
        const reportCard = document.getElementById('report-card');
        const toast = document.getElementById('toast');
        const toastMessage = document.getElementById('toast-message');
        
        let currentStudents = [];
        let selectedStudentId = null;
        
        // Show toast notification
        function showToast(message, type = 'success') {
            toastMessage.textContent = message;
            toast.className = `fixed bottom-4 right-4 px-6 py-3 rounded-xl shadow-2xl transform transition-transform duration-300 translate-y-0 flex items-center gap-2 z-50 ${
                type === 'error' ? 'bg-red-500' : 'bg-orange-600'
            }`;
            
            setTimeout(() => {
                toast.classList.add('translate-y-20');
            }, 3000);
        }
        
        // Set loading state
        function setLoading(element, isLoading) {
            if (isLoading) {
                element.classList.add('loading');
                element.disabled = true;
            } else {
                element.classList.remove('loading');
                element.disabled = false;
            }
        }
        
        // Create student card
        function createStudentCard(student) {
            const card = document.createElement('div');
            card.className = `student-card bg-white rounded-xl p-4 cursor-pointer transition-all shadow-md hover:shadow-xl ${selectedStudentId === student.id ? 'selected' : ''}`;
            card.innerHTML = `
                <div class="text-center">
                    <div class="relative inline-block">
                        <img src="${student.profile_picture || '/assets/images/default-avatar.png'}" 
                             alt="${student.name}" 
                             class="w-20 h-20 rounded-full object-cover border-3 border-orange-200 mx-auto"
                             onerror="this.src='/assets/images/default-avatar.png'">
                        <div class="absolute -bottom-1 -right-1 w-5 h-5 bg-orange-500 rounded-full border-2 border-white"></div>
                    </div>
                    <h3 class="font-semibold text-gray-800 mt-3">${student.name}</h3>
                    <p class="text-xs text-gray-500">ID: ${student.id}</p>
                </div>
            `;
            
            card.addEventListener('click', () => {
                // Remove selected class from all cards
                document.querySelectorAll('.student-card').forEach(c => c.classList.remove('selected'));
                card.classList.add('selected');
                selectedStudentId = student.id;
                generateReport(student.id);
            });
            
            return card;
        }
        
        // Filter students
        function filterStudents(searchTerm) {
            const filtered = currentStudents.filter(s => 
                s.name.toLowerCase().includes(searchTerm.toLowerCase())
            );
            
            studentCards.innerHTML = '';
            filtered.forEach(student => {
                studentCards.appendChild(createStudentCard(student));
            });
            
            studentCount.textContent = `${filtered.length} student${filtered.length !== 1 ? 's' : ''}`;
        }
        
        // Load students
        loadStudentsBtn.addEventListener('click', async () => {
            const term = termFilter.value;
            const mock = parseInt(mockFilter.value);
            
            if (!mock || mock < 1) {
                showToast('Please enter a valid mock number', 'error');
                return;
            }
            
            setLoading(loadStudentsBtn, true);
            
            try {
                const formData = new FormData();
                formData.append('action', 'get_students');
                formData.append('term_id', term);
                formData.append('mock_number', mock);
                formData.append('csrf_token', csrfToken);
                
                const response = await fetch('', {
                    method: 'POST',
                    body: formData
                });
                
                const data = await response.json();
                
                if (!data.success) {
                    showToast('No students found', 'error');
                    studentGrid.classList.add('hidden');
                    return;
                }
                
                currentStudents = data.students;
                
                // Populate grid
                studentCards.innerHTML = '';
                currentStudents.forEach(student => {
                    studentCards.appendChild(createStudentCard(student));
                });
                
                studentCount.textContent = `${currentStudents.length} students`;
                studentGrid.classList.remove('hidden');
                studentSearch.disabled = false;
                studentSearch.value = '';
                
                showToast(`Loaded ${currentStudents.length} students`);
                
            } catch (error) {
                showToast('Failed to load students', 'error');
                console.error('Error:', error);
            } finally {
                setLoading(loadStudentsBtn, false);
            }
        });
        
        // Search functionality
        studentSearch.addEventListener('input', (e) => {
            filterStudents(e.target.value);
        });
        
        // Generate report
        async function generateReport(studentId) {
            const mock = parseInt(mockFilter.value);
            const term = termFilter.value;
            
            setLoading(document.body, true);
            
            try {
                const formData = new FormData();
                formData.append('action', 'load_student_report');
                formData.append('student_id', studentId);
                formData.append('mock_number', mock);
                formData.append('term_id', term);
                formData.append('csrf_token', csrfToken);
                
                const response = await fetch('', {
                    method: 'POST',
                    body: formData
                });
                
                const data = await response.json();
                
                if (!data.success) {
                    showToast('Failed to generate report', 'error');
                    return;
                }
                
                reportCard.innerHTML = data.report;
                reportCard.classList.remove('hidden');
                
                // Scroll to report
                setTimeout(() => {
                    window.scrollTo({
                        top: reportCard.offsetTop - 20,
                        behavior: 'smooth'
                    });
                }, 100);
                
                showToast('Report generated successfully');
                
            } catch (error) {
                showToast('Failed to generate report', 'error');
                console.error('Error:', error);
            } finally {
                setLoading(document.body, false);
            }
        }
        
        // Reset form
        window.resetForm = function() {
            reportCard.classList.add('hidden');
            studentGrid.classList.remove('hidden');
            studentSearch.value = '';
            selectedStudentId = null;
            filterStudents('');
            window.scrollTo({ top: 0, behavior: 'smooth' });
        };
        
        // Download PDF (placeholder)
        window.downloadPDF = function() {
            showToast('PDF download feature coming soon!', 'info');
        };
        
        // Enter key shortcut
        mockFilter.addEventListener('keypress', (e) => {
            if (e.key === 'Enter') {
                e.preventDefault();
                loadStudentsBtn.click();
            }
        });
    </script>
</body>
</html>
<?php
$conn->close();
ob_end_flush();
?>