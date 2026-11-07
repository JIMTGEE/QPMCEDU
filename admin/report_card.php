<?php
// ============================================
// CONFIGURATION & INITIALIZATION
// ============================================
ob_start();
session_start();
require_once '../config/db.php';

// ============================================
// SCHOOL DAYS CONFIGURATION - ADJUST THIS FOR EACH TERM
// ============================================
// ⚠️ UPDATE THIS VALUE MANUALLY FOR EACH TERM ⚠️
$TOTAL_SCHOOL_DAYS = 45; // ← CHANGE THIS NUMBER TO MATCH YOUR TERM'S SCHOOL DAYS
// ============================================

// Set error reporting - DISPLAY ERRORS SO WE CAN SEE THEM
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
ini_set('log_errors', 1);
set_time_limit(300);
ini_set('max_execution_time', 300);

// ============================================
// DATABASE VALIDATION
// ============================================
if (!$conn || $conn->connect_error) {
    error_log("Database connection failed: " . ($conn->connect_error ?? 'Connection is null'));
    die("System error: Database connection failed. Please contact the administrator.");
}

// ============================================
// AUTHENTICATION CHECK
// ============================================
if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    $_SESSION['error_msg'] = "Please log in as an admin to access this page.";
    header('Location: ../login.php');
    exit;
}

$admin_id = $_SESSION['user_id'];

// ============================================
// CLASS NAME MAPPING
// ============================================
$class_mapping = [
    'Esther Foundation' => 'Creche',
    'Sheba One' => 'Nursery 1',
    'Sheba 2' => 'Nursery 2',
    'YaaSant One' => 'KG 1',
    'YaaSant 2' => 'KG 2',
    'Cleopatra One' => 'Basic 1',
    'Cleopatra 2' => 'Basic 2',
    'Nefertiti One' => 'Basic 3',
    'Nefertiti 2' => 'Basic 4',
    'Malika One' => 'Basic 5',
    'Malika Two' => 'Basic 6',
    'Makeda One' => 'Basic 7',
    'Makeda 2' => 'Basic 8',
    'An Majesty' => 'Basic 9'
];

function getDisplayClassName($class_name) {
    global $class_mapping;
    return isset($class_mapping[$class_name]) ? $class_mapping[$class_name] : $class_name;
}

// ============================================
// HELPER FUNCTIONS
// ============================================
function normalizeProfilePicture($profile_picture) {
    $default_picture = '/assets/images/default_profile.png';
    
    if (empty($profile_picture)) {
        return $default_picture;
    }
    
    $clean_path = str_replace(['uploads/profiles/', 'Uploads/profiles/', '../'], '', $profile_picture);
    $clean_path = ltrim($clean_path, '/');
    $full_path = $_SERVER['DOCUMENT_ROOT'] . '/Uploads/profiles/' . $clean_path;
    
    if (file_exists($full_path) && filesize($full_path) > 100) {
        return '/Uploads/profiles/' . $clean_path;
    }
    
    return $default_picture;
}

function fetch_data($conn, $query, $params = [], $fetch_all = true) {
    $stmt = $conn->prepare($query);
    if (!$stmt) {
        error_log("Query preparation failed: " . $conn->error);
        return false;
    }
    
    if (!empty($params)) {
        $types = '';
        foreach ($params as $param) {
            if (is_int($param)) $types .= 'i';
            elseif (is_float($param)) $types .= 'd';
            else $types .= 's';
        }
        $stmt->bind_param($types, ...$params);
    }
    
    if (!$stmt->execute()) {
        error_log("Query execution failed: " . $stmt->error);
        $stmt->close();
        return false;
    }
    
    $result = $stmt->get_result();
    $data = $fetch_all ? $result->fetch_all(MYSQLI_ASSOC) : $result->fetch_assoc();
    
    $result->free();
    $stmt->close();
    
    return $data;
}

// ============================================
// FUNCTION: Get Student Performance Data
// ============================================
function getStudentPerformanceData($conn, $user_id, $term_id) {
    $grades = fetch_data($conn, "
        SELECT 
            g.mid_term_exam, 
            g.end_term_exam, 
            g.comment,
            s.subject_name,
            s.subject_code
        FROM grades g
        JOIN subjects s ON g.subject_id = s.id
        WHERE g.user_id = ? AND g.term_id = ?
        ORDER BY s.subject_name
    ", [$user_id, $term_id]) ?: [];
    
    $subject_scores = [];
    $total_score = 0;
    $subject_count = count($grades);
    $highest_score = 0;
    $lowest_score = 100;
    $strong_subjects = [];
    $weak_subjects = [];
    
    foreach ($grades as $grade) {
        $mid = isset($grade['mid_term_exam']) ? (float)$grade['mid_term_exam'] : 0;
        $end = isset($grade['end_term_exam']) ? (float)$grade['end_term_exam'] : 0;
        $total = ($mid * 0.5) + ($end * 0.5);
        
        $subject_scores[] = [
            'subject' => $grade['subject_name'],
            'score' => $total,
            'mid_term' => $mid,
            'end_term' => $end,
            'comment' => $grade['comment'] ?? ''
        ];
        
        $total_score += $total;
        if ($total > $highest_score) $highest_score = $total;
        if ($total < $lowest_score) $lowest_score = $total;
        
        if ($total >= 70) {
            $strong_subjects[] = $grade['subject_name'];
        } elseif ($total < 50) {
            $weak_subjects[] = $grade['subject_name'];
        }
    }
    
    $average = $subject_count > 0 ? ($total_score / $subject_count) : 0;
    
    if ($average >= 80) {
        $performance_level = 'Excellent';
        $performance_desc = 'outstanding academic performance';
        $emoji = '🌟';
    } elseif ($average >= 70) {
        $performance_level = 'Very Good';
        $performance_desc = 'very good academic performance';
        $emoji = '⭐';
    } elseif ($average >= 60) {
        $performance_level = 'Good';
        $performance_desc = 'good academic performance';
        $emoji = '📚';
    } elseif ($average >= 50) {
        $performance_level = 'Satisfactory';
        $performance_desc = 'satisfactory academic performance';
        $emoji = '📖';
    } elseif ($average >= 40) {
        $performance_level = 'Below Average';
        $performance_desc = 'below average performance';
        $emoji = '⚠️';
    } else {
        $performance_level = 'Needs Improvement';
        $performance_desc = 'performance that needs significant improvement';
        $emoji = '🔴';
    }
    
    $top_subject = 'N/A';
    $bottom_subject = 'N/A';
    
    if (!empty($subject_scores)) {
        $top_score = -1;
        $bottom_score = 101;
        foreach ($subject_scores as $subj) {
            if ($subj['score'] > $top_score) {
                $top_score = $subj['score'];
                $top_subject = $subj['subject'];
            }
            if ($subj['score'] < $bottom_score) {
                $bottom_score = $subj['score'];
                $bottom_subject = $subj['subject'];
            }
        }
    }
    
    return [
        'grades' => $grades,
        'subject_scores' => $subject_scores,
        'total_score' => $total_score,
        'subject_count' => $subject_count,
        'average' => $average,
        'highest_score' => $highest_score,
        'lowest_score' => $lowest_score,
        'performance_level' => $performance_level,
        'performance_desc' => $performance_desc,
        'emoji' => $emoji,
        'strong_subjects' => $strong_subjects,
        'weak_subjects' => $weak_subjects,
        'top_subject' => $top_subject,
        'bottom_subject' => $bottom_subject
    ];
}

// ============================================
// FUNCTION: Get Student Attendance
// ============================================
function getStudentAttendance($conn, $user_id, $class_id, $term_id, $term_start_date, $term_end_date) {
    global $TOTAL_SCHOOL_DAYS;
    
    // Count present days
    $present = fetch_data($conn, "
        SELECT COUNT(*) as present_count
        FROM attendance
        WHERE user_id = ? AND class_id = ? AND date BETWEEN ? AND ? AND status = 'present'
    ", [$user_id, $class_id, $term_start_date, $term_end_date], false);
    
    // Count late days
    $late = fetch_data($conn, "
        SELECT COUNT(*) as late_count
        FROM attendance
        WHERE user_id = ? AND class_id = ? AND date BETWEEN ? AND ? AND status = 'late'
    ", [$user_id, $class_id, $term_start_date, $term_end_date], false);
    
    // Count absent days
    $absent = fetch_data($conn, "
        SELECT COUNT(*) as absent_count
        FROM attendance
        WHERE user_id = ? AND class_id = ? AND date BETWEEN ? AND ? AND status = 'absent'
    ", [$user_id, $class_id, $term_start_date, $term_end_date], false);
    
    // Also get from student_reports if available (fallback)
    $report_attendance = fetch_data($conn, "
        SELECT attendance
        FROM student_reports
        WHERE user_id = ? AND class_id = ? AND term_id = ?
    ", [$user_id, $class_id, $term_id], false);
    
    $present_count = (int)($present['present_count'] ?? 0);
    $late_count = (int)($late['late_count'] ?? 0);
    $absent_count = (int)($absent['absent_count'] ?? 0);
    $total_marked = $present_count + $late_count + $absent_count;
    
    // Use report attendance as fallback if no attendance records found
    if ($total_marked === 0 && $report_attendance && isset($report_attendance['attendance'])) {
        $present_count = (int)$report_attendance['attendance'];
        $total_marked = $TOTAL_SCHOOL_DAYS;
        $absent_count = max(0, $total_marked - $present_count);
    }
    
    // If still no data, use total school days
    if ($total_marked === 0) {
        $total_marked = $TOTAL_SCHOOL_DAYS;
    }
    
    return [
        'present' => $present_count,
        'late' => $late_count,
        'absent' => $absent_count,
        'total_marked' => $total_marked,
        'attendance_percentage' => $total_marked > 0 ? round(($present_count / $total_marked) * 100, 1) : 0
    ];
}

// ============================================
// HELPER: Get Grade Letter
// ============================================
function getGradeLetter($score) {
    if ($score >= 90) return 'A+';
    if ($score >= 80) return 'A';
    if ($score >= 75) return 'B2';
    if ($score >= 65) return 'B3';
    if ($score >= 55) return 'C4';
    if ($score >= 50) return 'C5';
    if ($score >= 45) return 'C6';
    if ($score >= 35) return 'D7';
    if ($score >= 30) return 'E8';
    return 'F9';
}

// ============================================
// FUNCTION: Generate Personalized Remark using Gemini AI
// ============================================
function generatePersonalizedRemark($conn, $user_id, $term_id, $student_name, $class_name, $conduct, $attitude, $interest, $remark_type = 'teacher', $attendance_data = null) {
    $config = include $_SERVER['DOCUMENT_ROOT'] . '/config/gemini.php';
$api_key = $config['api_key'];
    $url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-flash-latest:generateContent';
    
    $performance = getStudentPerformanceData($conn, $user_id, $term_id);
    
    $subject_breakdown = [];
    foreach ($performance['subject_scores'] as $subj) {
        $grade_letter = getGradeLetter($subj['score']);
        $subject_breakdown[] = "{$subj['subject']}: {$subj['score']}% ({$grade_letter})";
    }
    $subject_details = implode(', ', $subject_breakdown);
    
    // Build improvement areas
    $improvement_areas = [];
    if (!empty($performance['weak_subjects'])) {
        $improvement_areas[] = "paying more attention to " . implode(' and ', $performance['weak_subjects']);
    }
    if ($performance['average'] < 50) {
        $improvement_areas[] = "developing better study habits and consistent practice";
    }
    if (strtolower($interest) === 'low' || strtolower($interest) === 'average') {
        $improvement_areas[] = "increasing engagement in class activities and asking questions";
    }
    $improvement_suggestion = !empty($improvement_areas) ? "Focus on " . implode(' and ', $improvement_areas) . "." : "Continue the great effort and maintain consistency in your studies.";
    
    // Build praise areas
    $praise_areas = [];
    if (!empty($performance['strong_subjects'])) {
        $praise_areas[] = "excel in " . implode(' and ', $performance['strong_subjects']);
    }
    if ($performance['average'] >= 70) {
        $praise_areas[] = "maintain excellent academic standards";
    } elseif ($performance['average'] >= 60) {
        $praise_areas[] = "show good understanding of most subjects";
    }
    if (strtolower($conduct) === 'excellent' || strtolower($conduct) === 'very good') {
        $praise_areas[] = "display exemplary conduct and respect for others";
    }
    if (strtolower($attitude) === 'positive' || strtolower($attitude) === 'enthusiastic') {
        $praise_areas[] = "show a positive and enthusiastic attitude towards learning";
    }
    
    // Attendance information
    $attendance_text = '';
    if ($attendance_data) {
        $attendance_text = "Attendance: {$attendance_data['present']} days present out of {$attendance_data['total_marked']} days (" . number_format($attendance_data['attendance_percentage'], 1) . "%). ";
        if ($attendance_data['attendance_percentage'] >= 90) {
            $attendance_text .= "Excellent attendance! ";
        } elseif ($attendance_data['attendance_percentage'] >= 75) {
            $attendance_text .= "Good attendance record. ";
        } elseif ($attendance_data['attendance_percentage'] >= 60) {
            $attendance_text .= "Attendance needs improvement. ";
        } else {
            $attendance_text .= "Poor attendance - this is affecting academic progress. ";
        }
    }
    
    $praise_suggestion = !empty($praise_areas) ? "We commend your ability to " . implode(' and ', $praise_areas) . "." : "Keep striving for excellence in all areas.";
    
    $class_display = getDisplayClassName($class_name);
    
    // Determine grade level
    $grade_level = 'primary';
    if (in_array($class_display, ['Creche', 'Nursery 1', 'Nursery 2', 'KG 1', 'KG 2'])) {
        $grade_level = 'early_years';
    } elseif (in_array($class_display, ['Basic 1', 'Basic 2', 'Basic 3', 'Basic 4', 'Basic 5', 'Basic 6'])) {
        $grade_level = 'primary';
    } elseif (in_array($class_display, ['Basic 7', 'Basic 8', 'Basic 9'])) {
        $grade_level = 'jhs';
    }
    
    // Age-appropriate guidance
    $age_guidance = '';
    if ($grade_level === 'early_years') {
        $age_guidance = "Continue to encourage participation in activities and social interaction with peers. Focus on building foundational skills through play-based learning.";
    } elseif ($grade_level === 'primary') {
        $age_guidance = "Continue building strong academic foundations. Encourage reading for pleasure and daily practice of core subjects.";
    } else {
        $age_guidance = "As you prepare for higher education, focus on developing independent study habits, critical thinking, and consistent revision.";
    }
    
    if ($remark_type === 'teacher') {
        $prompt = "You are a professional and experienced class teacher writing a personalized report card remark for a student. 
        
        STUDENT INFORMATION:
        - Name: {$student_name}
        - Class: {$class_display}
        - Performance Level: {$performance['performance_level']} ({$performance['emoji']})
        - Average Score: " . number_format($performance['average'], 1) . "%
        - Subject Scores: {$subject_details}
        
        BEHAVIORAL ASSESSMENT:
        - Conduct: {$conduct}
        - Attitude towards learning: {$attitude}
        - Interest in studies: {$interest}
        
        ACADEMIC ANALYSIS:
        - Strongest Subject: {$performance['top_subject']}
        - Weakest Subject: {$performance['bottom_subject']}
        - Number of Subjects: {$performance['subject_count']}
        
        ATTENDANCE INFORMATION:
        {$attendance_text}
        
        TASK: Write a professional, encouraging, and constructive remark (4-5 sentences) that:
        1. Opens with a warm, personalized greeting using the student's name
        2. Acknowledges the student's current performance level specifically
        3. Mentions at least two specific subject strengths with concrete examples
        4. Provides constructive feedback on areas for improvement
        5. Includes a motivational closing statement with encouragement
        
        GUIDELINES:
        - Be warm, encouraging, and professional
        - Be specific - mention actual subjects and scores
        - Consider the student's class level and age appropriately
        - DO NOT use markdown, bullet points, or special characters
        - Write in natural, flowing English prose";
    } else {
        $prompt = "You are the Headmaster/Headmistress writing an authoritative and inspirational report card remark for a student.
        
        STUDENT INFORMATION:
        - Name: {$student_name}
        - Class: {$class_display}
        - Performance Level: {$performance['performance_level']} ({$performance['emoji']})
        - Average Score: " . number_format($performance['average'], 1) . "%
        - Subject Scores: {$subject_details}
        
        BEHAVIORAL ASSESSMENT:
        - Conduct: {$conduct}
        - Attitude towards learning: {$attitude}
        - Interest in studies: {$interest}
        
        ACADEMIC ANALYSIS:
        - Strongest Subject: {$performance['top_subject']}
        - Weakest Subject: {$performance['bottom_subject']}
        - Number of Subjects: {$performance['subject_count']}
        
        ATTENDANCE INFORMATION:
        {$attendance_text}
        
        TASK: Write a professional, inspiring, and forward-looking remark (4-5 sentences) that:
        1. Opens with a formal but warm acknowledgment of the student
        2. Acknowledges the student's overall progress and potential
        3. Offers specific encouragement based on their performance
        4. Provides a vision for future growth and excellence
        5. Includes an inspirational closing message about their potential
        
        GUIDELINES:
        - Be authoritative yet warm and encouraging
        - Mention specific achievements or areas for growth
        - Emphasize the importance of continued effort and character development
        - Connect academic performance to future success
        - DO NOT use markdown, bullet points, or special characters
        - Write in natural, flowing English prose";
    }
    
    $payload = json_encode([
        'contents' => [
            [
                'parts' => [
                    ['text' => $prompt]
                ]
            ]
        ],
        'generationConfig' => [
            'temperature' => 0.7,
            'maxOutputTokens' => 200,
            'topP' => 0.95,
            'topK' => 40
        ]
    ]);
    
    $ch = curl_init($url . '?key=' . $api_key);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($http_code === 200 && $response) {
        $data = json_decode($response, true);
        if (isset($data['candidates'][0]['content']['parts'][0]['text'])) {
            $remark = trim($data['candidates'][0]['content']['parts'][0]['text']);
            $remark = preg_replace('/\*\*/', '', $remark);
            $remark = preg_replace('/\*/', '', $remark);
            $remark = preg_replace('/^["\']|["\']$/', '', $remark);
            return $remark;
        }
    }
    
    // Fallback remarks
    $avg = $performance['average'];
    $top_subj = $performance['top_subject'];
    $bottom_subj = $performance['bottom_subject'];
    $attendance_note = $attendance_data ? "Attendance: {$attendance_data['present']}/{$attendance_data['total_marked']} days." : "";
    
    if ($remark_type === 'teacher') {
        if ($avg >= 75) {
            return "{$student_name}, you have demonstrated excellent academic performance this term, particularly in {$top_subj}. Your consistent effort and high standards across all subjects are truly commendable. {$attendance_note} Continue this commendable work and strive for even greater heights. Well done!";
        } elseif ($avg >= 60) {
            return "{$student_name}, you have shown good academic performance this term with notable strength in {$top_subj}. Your progress in {$bottom_subj} has been encouraging. {$attendance_note} Keep up the positive attitude and consistent effort - you are on the right track!";
        } elseif ($avg >= 45) {
            return "{$student_name}, you have shown satisfactory performance this term. While there is room for improvement in {$bottom_subj}, we appreciate your effort in {$top_subj}. {$attendance_note} We encourage you to develop better study habits and seek help when needed.";
        } else {
            return "{$student_name}, we understand that this term has been challenging, particularly in {$bottom_subj}. However, we have noticed your effort in {$top_subj}. {$attendance_note} Let's work together to develop a plan for improvement. We believe in your ability to do better!";
        }
    } else {
        if ($avg >= 75) {
            return "Dear {$student_name}, you continue to exemplify academic excellence and serve as a role model for your peers. Your consistent performance in {$top_subj} demonstrates dedication and discipline. {$attendance_note} We look forward to seeing continued success and leadership in the future.";
        } elseif ($avg >= 60) {
            return "Dear {$student_name}, you have made commendable progress this term. The strength shown in {$top_subj} is noteworthy. {$attendance_note} With continued focus and effort, especially in {$bottom_subj}, we believe even greater achievements await. Keep pushing forward!";
        } elseif ($avg >= 45) {
            return "Dear {$student_name}, you have potential that can be unlocked with more focused effort. Your performance in {$top_subj} demonstrates capability. {$attendance_note} We encourage you to work on {$bottom_subj} and believe in your ability to improve.";
        } else {
            return "Dear {$student_name}, we believe in your potential to improve and succeed. While this term has been challenging, particularly in {$bottom_subj}, we see opportunities for growth. {$attendance_note} Let's work together to create a plan for success. We have faith in you.";
        }
    }
}

// ============================================
// FUNCTION: Generate/Update Remarks for a Student
// ============================================
function generateRemarksForStudent($conn, $user_id, $term_id, $report_id, $class_id, $student_name, $class_name, $attendance_data = null) {
    error_log("=== Starting personalized remark generation for $student_name ===");
    
    $existing = fetch_data($conn, "
        SELECT id, teacher_remark, headmaster_remark, teacher_remark_status, headmaster_remark_status
        FROM remarks 
        WHERE user_id = ? AND term_id = ? AND report_id = ?
    ", [$user_id, $term_id, $report_id], false);
    
    if ($existing && !empty($existing['teacher_remark']) && !empty($existing['headmaster_remark'])) {
        error_log("Skipping $student_name - remarks already exist with content");
        return [
            'teacher_status' => 'existing',
            'headmaster_status' => 'existing',
            'teacher_remark' => $existing['teacher_remark'],
            'headmaster_remark' => $existing['headmaster_remark'],
            'skipped' => true
        ];
    }
    
    $report = fetch_data($conn, "
        SELECT conduct, attitude, interest 
        FROM student_reports 
        WHERE id = ? AND user_id = ? AND term_id = ?
    ", [$report_id, $user_id, $term_id], false);
    
    $conduct = isset($report['conduct']) ? $report['conduct'] : 'Good';
    $attitude = isset($report['attitude']) ? $report['attitude'] : 'Positive';
    $interest = isset($report['interest']) ? $report['interest'] : 'Average';
    
    error_log("Generating personalized teacher remark for $student_name");
    $teacher_remark = generatePersonalizedRemark($conn, $user_id, $term_id, $student_name, $class_name, $conduct, $attitude, $interest, 'teacher', $attendance_data);
    error_log("Teacher remark generated: " . substr($teacher_remark, 0, 100) . "...");
    
    error_log("Generating personalized headmaster remark for $student_name");
    $headmaster_remark = generatePersonalizedRemark($conn, $user_id, $term_id, $student_name, $class_name, $conduct, $attitude, $interest, 'headmaster', $attendance_data);
    error_log("Headmaster remark generated: " . substr($headmaster_remark, 0, 100) . "...");
    
    if ($existing) {
        $stmt = $conn->prepare("
            UPDATE remarks SET 
                teacher_remark = ?,
                headmaster_remark = ?,
                teacher_remark_status = 'generated',
                headmaster_remark_status = 'generated',
                teacher_remark_generated_at = NOW(),
                headmaster_remark_generated_at = NOW()
            WHERE id = ?
        ");
        $stmt->bind_param("ssi", $teacher_remark, $headmaster_remark, $existing['id']);
        if ($stmt->execute()) {
            error_log("Updated remarks for $student_name (ID: {$existing['id']})");
        } else {
            error_log("Failed to update remarks for $student_name: " . $stmt->error);
        }
        $stmt->close();
    } else {
        $stmt = $conn->prepare("
            INSERT INTO remarks 
            (user_id, term_id, report_id, class_id, teacher_remark, headmaster_remark, 
             teacher_remark_status, headmaster_remark_status, teacher_remark_generated_at, headmaster_remark_generated_at) 
            VALUES (?, ?, ?, ?, ?, ?, 'generated', 'generated', NOW(), NOW())
        ");
        $stmt->bind_param("iiiiss", 
            $user_id, $term_id, $report_id, $class_id,
            $teacher_remark, $headmaster_remark
        );
        if ($stmt->execute()) {
            error_log("Inserted personalized remarks for $student_name (ID: " . $stmt->insert_id . ")");
        } else {
            error_log("Failed to insert remarks for $student_name: " . $stmt->error);
        }
        $stmt->close();
    }
    
    return [
        'teacher_status' => 'generated',
        'headmaster_status' => 'generated',
        'teacher_remark' => $teacher_remark,
        'headmaster_remark' => $headmaster_remark,
        'skipped' => false
    ];
}

// ============================================
// HANDLE BULK REMARK GENERATION
// ============================================
if (isset($_POST['generate_remarks']) && isset($_POST['student_ids'])) {
    error_log("=== AI Remark Generation Request Received ===");
    
    $student_ids_string = $_POST['student_ids'];
    $student_ids = explode(',', $student_ids_string);
    $student_ids = array_filter(array_map('trim', $student_ids));
    
    error_log("Processing " . count($student_ids) . " students");
    
    $results = [];
    $success_count = 0;
    $skipped_count = 0;
    $fail_count = 0;
    
    foreach ($student_ids as $student_data) {
        if (empty($student_data)) continue;
        
        $parts = explode('|', $student_data);
        if (count($parts) !== 4) {
            error_log("Invalid student data format: $student_data");
            $fail_count++;
            continue;
        }
        
        list($user_id, $report_id, $term_id, $class_id) = $parts;
        
        error_log("Processing student: user_id=$user_id, report_id=$report_id");
        
        $student = fetch_data($conn, "SELECT name FROM users WHERE id = ?", [$user_id], false);
        $class = fetch_data($conn, "SELECT class_name FROM classes WHERE id = ?", [$class_id], false);
        $term = fetch_data($conn, "SELECT start_date, end_date FROM academic_calendar WHERE id = ?", [$term_id], false);
        
        if ($student && $class) {
            try {
                $attendance_data = null;
                if ($term) {
                    $attendance_data = getStudentAttendance($conn, $user_id, $class_id, $term_id, $term['start_date'], $term['end_date']);
                }
                
                $result = generateRemarksForStudent(
                    $conn, 
                    $user_id, 
                    $term_id, 
                    $report_id, 
                    $class_id, 
                    $student['name'], 
                    $class['class_name'],
                    $attendance_data
                );
                
                if ($result['skipped']) {
                    $skipped_count++;
                    $results[] = [
                        'user_id' => $user_id,
                        'name' => $student['name'],
                        'teacher_status' => 'existing',
                        'headmaster_status' => 'existing',
                        'skipped' => true,
                        'teacher_remark_preview' => substr($result['teacher_remark'], 0, 50) . '...'
                    ];
                } else {
                    $success_count++;
                    $results[] = [
                        'user_id' => $user_id,
                        'name' => $student['name'],
                        'teacher_status' => 'generated',
                        'headmaster_status' => 'generated',
                        'skipped' => false,
                        'teacher_remark_preview' => substr($result['teacher_remark'], 0, 50) . '...',
                        'headmaster_remark_preview' => substr($result['headmaster_remark'], 0, 50) . '...'
                    ];
                }
            } catch (Exception $e) {
                error_log("Error generating remarks for {$student['name']}: " . $e->getMessage());
                $fail_count++;
            }
        } else {
            error_log("Could not find student or class for user_id: $user_id");
            $fail_count++;
        }
    }
    
    $_SESSION['remark_generation_results'] = $results;
    $_SESSION['success_msg'] = "Remarks: $success_count generated, $skipped_count already exist, $fail_count failed.";
    error_log("=== AI Remark Generation Complete: $success_count generated, $skipped_count skipped, $fail_count failed ===");
    
    header("Location: " . strtok($_SERVER["REQUEST_URI"], '?'));
    exit;
}

// ============================================
// GET CURRENT ACTIVE TERM
// ============================================
$today = date('Y-m-d');
$current_term = fetch_data($conn, "
    SELECT id, term_name, year, start_date, end_date 
    FROM academic_calendar 
    WHERE start_date <= ? AND end_date >= ? 
    ORDER BY start_date DESC LIMIT 1
", [$today, $today], false);

if (!$current_term) {
    $current_term = fetch_data($conn, "
        SELECT id, term_name, year, start_date, end_date 
        FROM academic_calendar 
        ORDER BY year DESC, start_date DESC LIMIT 1
    ", [], false);
}

$current_term_id = isset($current_term['id']) ? $current_term['id'] : 0;
$current_term_display = $current_term ? $current_term['term_name'] . ' (' . $current_term['year'] . ')' : 'No active term';
$current_academic_year = isset($current_term['year']) ? $current_term['year'] : '';

// ============================================
// FETCH FILTER DATA
// ============================================
$departments = fetch_data($conn, "SELECT id, name FROM departments ORDER BY name") ?: [];
$classes = fetch_data($conn, "SELECT id, class_name, department_id FROM classes ORDER BY class_name") ?: [];

$all_terms = fetch_data($conn, "
    SELECT id, term_name, year, CONCAT(term_name, ' (', year, ')') as display
    FROM academic_calendar 
    ORDER BY year DESC, start_date DESC
") ?: [];

$academic_years = fetch_data($conn, "
    SELECT DISTINCT year as year_label FROM academic_calendar ORDER BY year DESC
") ?: [];

// ============================================
// HANDLE FILTERS
// ============================================
$filter_values = [
    'search_term' => isset($_GET['search']) ? $_GET['search'] : '',
    'department_id' => isset($_GET['department_id']) ? $_GET['department_id'] : '',
    'class_id' => isset($_GET['class_id']) ? $_GET['class_id'] : '',
    'term_id' => isset($_GET['term_id']) ? $_GET['term_id'] : $current_term_id,
    'academic_year' => isset($_GET['academic_year']) ? $_GET['academic_year'] : $current_academic_year
];

$where_conditions = ["u.role = 'student'", "u.status = 'active'"];
$filter_params = [];

if (!empty($filter_values['search_term'])) {
    $where_conditions[] = "u.name LIKE ?";
    $filter_params[] = "%{$filter_values['search_term']}%";
}

if (!empty($filter_values['department_id'])) {
    $where_conditions[] = "c.department_id = ?";
    $filter_params[] = $filter_values['department_id'];
}

if (!empty($filter_values['class_id'])) {
    $where_conditions[] = "sc.class_id = ?";
    $filter_params[] = $filter_values['class_id'];
}

if (!empty($filter_values['term_id'])) {
    $where_conditions[] = "sr.term_id = ?";
    $filter_params[] = $filter_values['term_id'];
}

if (!empty($filter_values['academic_year'])) {
    $where_conditions[] = "sc.academic_year = ?";
    $filter_params[] = $filter_values['academic_year'];
}

// ============================================
// FETCH STUDENTS WITH REPORTS AND REMARK STATUS
// ============================================
$where_clause = implode(' AND ', $where_conditions);

$query = "
    SELECT 
        c.class_name, 
        c.id as class_id,
        u.id AS user_id, 
        u.name, 
        u.profile_picture, 
        sc.academic_year, 
        sr.id AS report_id, 
        sr.term_id, 
        ac.term_name, 
        ac.year as term_academic_year,
        ac.start_date,
        ac.end_date,
        r.id as remark_id,
        r.teacher_remark_status,
        r.headmaster_remark_status,
        r.teacher_remark_generated_at,
        r.headmaster_remark_generated_at,
        r.teacher_remark,
        r.headmaster_remark
    FROM users u
    INNER JOIN student_classes sc ON u.id = sc.user_id
    INNER JOIN classes c ON sc.class_id = c.id
    INNER JOIN student_reports sr ON u.id = sr.user_id AND sc.class_id = sr.class_id
    INNER JOIN academic_calendar ac ON sr.term_id = ac.id
    LEFT JOIN remarks r ON u.id = r.user_id AND sr.term_id = r.term_id AND sr.id = r.report_id
    WHERE $where_clause
    ORDER BY c.class_name, u.name
";

$students_data = fetch_data($conn, $query, $filter_params) ?: [];

// ============================================
// PROCESS STUDENT DATA WITH ATTENDANCE
// ============================================
$grouped_students = [];
foreach ($students_data as $student) {
    $class_name = isset($student['class_name']) ? $student['class_name'] : 'Unknown';
    
    // Get attendance for this student
    $attendance_data = getStudentAttendance(
        $conn, 
        $student['user_id'], 
        $student['class_id'], 
        $student['term_id'],
        isset($student['start_date']) ? $student['start_date'] : date('Y-m-d'),
        isset($student['end_date']) ? $student['end_date'] : date('Y-m-d')
    );
    
    $student['profile_picture'] = normalizeProfilePicture(isset($student['profile_picture']) ? $student['profile_picture'] : '');
    $student['term_display'] = (isset($student['term_name']) ? $student['term_name'] : '') . ' (' . (isset($student['term_academic_year']) ? $student['term_academic_year'] : '') . ')';
    $student['attendance'] = $attendance_data;
    
    // Check if remarks exist with actual content
    $has_teacher_remark = !empty($student['teacher_remark']) && strlen(trim($student['teacher_remark'])) > 10;
    $has_headmaster_remark = !empty($student['headmaster_remark']) && strlen(trim($student['headmaster_remark'])) > 10;
    
    // Status badges
    if ($has_teacher_remark && $has_headmaster_remark) {
        $status_badges = [
            'teacher' => '<span class="badge bg-success"><i class="fas fa-check-circle"></i> Generated</span>',
            'headmaster' => '<span class="badge bg-success"><i class="fas fa-check-circle"></i> Generated</span>'
        ];
        $has_complete_remarks = true;
    } else if ($has_teacher_remark) {
        $status_badges = [
            'teacher' => '<span class="badge bg-success"><i class="fas fa-check-circle"></i> Generated</span>',
            'headmaster' => '<span class="badge bg-warning"><i class="fas fa-clock"></i> Pending</span>'
        ];
        $has_complete_remarks = false;
    } else if ($has_headmaster_remark) {
        $status_badges = [
            'teacher' => '<span class="badge bg-warning"><i class="fas fa-clock"></i> Pending</span>',
            'headmaster' => '<span class="badge bg-success"><i class="fas fa-check-circle"></i> Generated</span>'
        ];
        $has_complete_remarks = false;
    } else {
        $status_badges = [
            'teacher' => '<span class="badge bg-secondary"><i class="fas fa-minus"></i> Not Set</span>',
            'headmaster' => '<span class="badge bg-secondary"><i class="fas fa-minus"></i> Not Set</span>'
        ];
        $has_complete_remarks = false;
    }
    
    $student['remark_status'] = $status_badges;
    $student['has_complete_remarks'] = $has_complete_remarks;
    $student['has_teacher_remark'] = $has_teacher_remark;
    $student['has_headmaster_remark'] = $has_headmaster_remark;
    $student['remark_id'] = isset($student['remark_id']) ? $student['remark_id'] : null;
    $student['teacher_remark_preview'] = $has_teacher_remark ? substr($student['teacher_remark'], 0, 60) . '...' : '';
    $student['headmaster_remark_preview'] = $has_headmaster_remark ? substr($student['headmaster_remark'], 0, 60) . '...' : '';
    $student['display_class_name'] = getDisplayClassName($class_name);
    
    if (!isset($grouped_students[$class_name])) {
        $grouped_students[$class_name] = [
            'class_id' => $student['class_id'],
            'display_name' => getDisplayClassName($class_name),
            'students' => []
        ];
    }
    $grouped_students[$class_name]['students'][] = $student;
}

$total_students = count($students_data);

// Get selected term display
$selected_term_display = $current_term_display;
if (!empty($filter_values['term_id'])) {
    foreach ($all_terms as $term) {
        if ($term['id'] == $filter_values['term_id']) {
            $selected_term_display = $term['display'];
            break;
        }
    }
}

// Display remark generation results if any
$remark_results = isset($_SESSION['remark_generation_results']) ? $_SESSION['remark_generation_results'] : [];
unset($_SESSION['remark_generation_results']);

// ============================================
// HANDLE AJAX SEARCH SUGGESTIONS
// ============================================
if (isset($_GET['action']) && $_GET['action'] === 'get_student_suggestions') {
    $search_term = isset($_GET['term']) ? $_GET['term'] : '';
    $dept_id = isset($_GET['department_id']) ? $_GET['department_id'] : '';
    $cls_id = isset($_GET['class_id']) ? $_GET['class_id'] : '';
    
    $suggest_query = "
        SELECT DISTINCT u.name 
        FROM users u 
        JOIN student_classes sc ON u.id = sc.user_id
        JOIN classes c ON sc.class_id = c.id
        WHERE u.role = 'student' AND u.status = 'active' AND u.name LIKE ?
    ";
    $suggest_params = ["%$search_term%"];
    
    if (!empty($dept_id)) {
        $suggest_query .= " AND c.department_id = ?";
        $suggest_params[] = $dept_id;
    }
    if (!empty($cls_id)) {
        $suggest_query .= " AND sc.class_id = ?";
        $suggest_params[] = $cls_id;
    }
    
    $suggest_query .= " ORDER BY u.name LIMIT 10";
    
    $suggestions = fetch_data($conn, $suggest_query, $suggest_params) ?: [];
    $names = array_column($suggestions, 'name');
    
    header('Content-Type: application/json');
    echo json_encode($names);
    exit;
}

// ============================================
// BUILD FILTER URL
// ============================================
function buildFilterUrl($overrides = []) {
    global $filter_values;
    $params = array_merge($filter_values, $overrides);
    $params = array_filter($params);
    return '?' . http_build_query($params);
}
?>

<!DOCTYPE html>
<html lang="en" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Report Cards - Queen's Paradise Montessori School</title>
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" rel="stylesheet">
    <link href="https://code.jquery.com/ui/1.12.1/themes/base/jquery-ui.css" rel="stylesheet">
    
    <style>
        :root {
            --primary-color: #ff6200;
            --secondary-color: #ff8c00;
            --shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
        }
        body { background-color: #f9fafb; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        .card { border-radius: 12px; box-shadow: var(--shadow); margin-bottom: 1.5rem; border: none; }
        .card-header { background: linear-gradient(135deg, var(--primary-color), var(--secondary-color)); color: white; border-radius: 12px 12px 0 0; padding: 1.2rem 1.5rem; border-bottom: none; }
        .btn-primary { background: linear-gradient(135deg, var(--primary-color), var(--secondary-color)); border: none; border-radius: 8px; padding: 0.5rem 1.5rem; font-weight: 500; }
        .btn-primary:hover { background: linear-gradient(135deg, #e55a00, #e57a00); transform: translateY(-2px); box-shadow: 0 6px 15px rgba(255, 98, 0, 0.3); }
        .btn-success { background-color: #28a745; border-color: #28a745; }
        .btn-warning { background-color: #ffc107; border-color: #ffc107; color: #212529; }
        .btn-info { background-color: #17a2b8; border-color: #17a2b8; color: white; }
        .btn-secondary { background-color: #6c757d; border-color: #6c757d; }
        .btn-danger { background-color: #dc3545; border-color: #dc3545; }
        .btn:hover { transform: translateY(-2px); box-shadow: 0 4px 8px rgba(0,0,0,0.1); }
        .btn-sm { padding: 0.25rem 0.5rem; font-size: 0.75rem; }
        .table-responsive { border-radius: 12px; overflow: hidden; border: 1px solid #dee2e6; }
        .table thead { background: linear-gradient(135deg, var(--primary-color), var(--secondary-color)); color: white; }
        .table th { border: none; padding: 1rem; font-weight: 600; }
        .table td { padding: 0.75rem 1rem; vertical-align: middle; }
        .table-hover tbody tr:hover { background-color: rgba(255, 98, 0, 0.05); }
        .profile-img { width: 45px; height: 45px; object-fit: cover; border-radius: 50%; border: 3px solid var(--primary-color); background-color: #f0f0f0; }
        .filter-section { background: white; padding: 1.5rem; border-radius: 12px; box-shadow: var(--shadow); margin-bottom: 2rem; }
        .form-control, .form-select { border-radius: 8px; border: 1px solid #ced4da; padding: 0.5rem 0.75rem; }
        .form-control:focus, .form-select:focus { border-color: var(--primary-color); box-shadow: 0 0 0 0.25rem rgba(255, 98, 0, 0.25); }
        .ui-autocomplete { max-height: 200px; overflow-y: auto; border-radius: 8px; box-shadow: var(--shadow); border: 1px solid var(--primary-color); }
        .badge { padding: 0.35em 0.65em; font-size: 0.75em; font-weight: 600; }
        .action-buttons { display: flex; gap: 5px; flex-wrap: wrap; }
        .action-buttons .btn { padding: 0.25rem 0.5rem; font-size: 0.75rem; }
        .bulk-print-bar { background: #e8f4fd; border-radius: 8px; padding: 12px 20px; margin-bottom: 20px; border-left: 4px solid var(--primary-color); }
        .student-checkbox { width: 20px; height: 20px; cursor: pointer; }
        .select-all-checkbox { width: 20px; height: 20px; cursor: pointer; }
        .class-bulk-actions { display: flex; gap: 10px; align-items: center; }
        .remark-cell { min-width: 130px; max-width: 200px; }
        .remark-preview { font-size: 0.8rem; color: #6c757d; display: block; max-width: 180px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; cursor: pointer; }
        .remark-preview:hover { color: var(--primary-color); }
        .ai-generation-btn { background: linear-gradient(135deg, #6f42c1, #8b5cf6); color: white; border: none; }
        .ai-generation-btn:hover { background: linear-gradient(135deg, #5a32a8, #7c3aed); color: white; }
        .status-complete { border-left: 4px solid #28a745; }
        .status-pending { border-left: 4px solid #ffc107; }
        .status-missing { border-left: 4px solid #dc3545; }
        .result-card { padding: 8px 12px; background: #f8f9fa; border-radius: 6px; margin-bottom: 4px; }
        .result-card .badge { font-size: 0.7rem; }
        
        .remark-toggle {
            cursor: pointer;
            padding: 4px 8px;
            border-radius: 4px;
            background: #f1f5f9;
            transition: all 0.2s;
            display: inline-block;
            font-size: 0.75rem;
            color: #475569;
        }
        .remark-toggle:hover {
            background: #e2e8f0;
        }
        .remark-toggle.active {
            background: #dbeafe;
            color: #1e40af;
        }
        .remark-full {
            display: none;
            padding: 8px 12px;
            background: #f8fafc;
            border-radius: 6px;
            margin-top: 4px;
            font-size: 0.85rem;
            line-height: 1.5;
            border-left: 3px solid var(--primary-color);
            max-width: 400px;
        }
        .remark-full.show {
            display: block;
            animation: slideDown 0.3s ease-out;
        }
        @keyframes slideDown {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .attendance-badge {
            font-size: 0.7rem;
            padding: 2px 8px;
            border-radius: 12px;
            font-weight: 500;
            display: inline-block;
        }
        .attendance-badge.high { background: #dcfce7; color: #166534; }
        .attendance-badge.medium { background: #fef3c7; color: #92400e; }
        .attendance-badge.low { background: #fee2e2; color: #991b1b; }
        
        .class-display-badge {
            background: #e0e7ff;
            color: #3730a3;
            font-size: 0.7rem;
            padding: 2px 10px;
            border-radius: 12px;
            font-weight: 500;
            margin-left: 6px;
        }
        
        .school-days-note {
            background: #f0fdf4;
            border: 1px solid #86efac;
            border-radius: 8px;
            padding: 8px 16px;
            margin-bottom: 16px;
            font-size: 0.9rem;
            color: #166534;
        }
        .school-days-note strong {
            color: #065f46;
        }
    </style>
</head>
<body>
    <?php include '../templates/admin_header.php'; ?>
    
    <div class="container-fluid py-4">
        <div class="card">
            <div class="card-header">
                <div class="d-flex justify-content-between align-items-center flex-wrap">
                    <h3 class="mb-0">
                        <i class="fas fa-graduation-cap me-2"></i>Student Report Cards
                    </h3>
                    <div class="mt-2 mt-sm-0">
                        <span class="badge bg-light text-dark fs-6">
                            <i class="fas fa-users me-1"></i>
                            <?php echo $total_students; ?> Reports
                        </span>
                        <span class="badge bg-info text-white fs-6 ms-2">
                            <i class="fas fa-calendar-alt me-1"></i>
                            <?php echo htmlspecialchars($selected_term_display); ?>
                        </span>
                    </div>
                </div>
            </div>
            
            <div class="card-body">
                <!-- School Days Configuration Notice -->
                <div class="school-days-note">
                    <i class="fas fa-info-circle me-2"></i>
                    <strong>School Days Configuration:</strong> 
                    Total school days for this term: <strong><?php echo $TOTAL_SCHOOL_DAYS; ?></strong> days
                    <span class="text-muted ms-3">
                        <i class="fas fa-edit"></i> To change, update <code>$TOTAL_SCHOOL_DAYS</code> at the top of this file.
                    </span>
                </div>
                
                <!-- Display remark generation results -->
                <?php if (!empty($remark_results)): ?>
                    <div class="alert alert-success alert-dismissible fade show">
                        <h5><i class="fas fa-robot me-2"></i>AI Remark Generation Complete</h5>
                        <div class="row mt-2">
                            <?php foreach ($remark_results as $result): ?>
                                <div class="col-md-6 col-lg-4 result-card">
                                    <small>
                                        <strong><?php echo htmlspecialchars($result['name']); ?></strong>
                                        <?php if (isset($result['skipped']) && $result['skipped']): ?>
                                            <span class="badge bg-info"><i class="fas fa-check"></i> Already Exists</span>
                                        <?php else: ?>
                                            <span class="badge bg-success"><i class="fas fa-check"></i> Generated</span>
                                        <?php endif; ?>
                                        <?php if (isset($result['teacher_remark_preview'])): ?>
                                            <br><span class="text-muted"><?php echo htmlspecialchars($result['teacher_remark_preview']); ?></span>
                                        <?php endif; ?>
                                    </small>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>
                
                <!-- Current Term Alert -->
                <div class="alert alert-info">
                    <i class="fas fa-info-circle me-2"></i>
                    <strong>Showing reports for:</strong> <?php echo htmlspecialchars($selected_term_display); ?>
                    <?php if ($filter_values['term_id'] == $current_term_id): ?>
                        <span class="badge bg-primary ms-2">Current Active Term</span>
                    <?php endif; ?>
                </div>
                
                <!-- Bulk Print Bar -->
                <div class="bulk-print-bar" id="bulkPrintBar" style="display: none;">
                    <div class="d-flex justify-content-between align-items-center flex-wrap">
                        <div>
                            <i class="fas fa-print me-2" style="color: var(--primary-color);"></i>
                            <strong>Bulk Print Mode</strong>
                            <span class="ms-3">
                                <span id="selectedCount">0</span> student(s) selected
                            </span>
                        </div>
                        <div class="mt-2 mt-sm-0">
                            <button class="btn btn-success me-2 btn-sm" onclick="bulkPrintSelected()">
                                <i class="fas fa-print me-1"></i>Print Selected
                            </button>
                            <button class="btn btn-outline-secondary btn-sm" onclick="clearAllSelections()">
                                <i class="fas fa-times me-1"></i>Cancel
                            </button>
                        </div>
                    </div>
                </div>
                
                <!-- Filter Form -->
                <div class="filter-section">
                    <form method="GET" id="filterForm">
                        <div class="row g-3">
                            <div class="col-md-3">
                                <label class="form-label fw-semibold">
                                    <i class="fas fa-search me-1"></i>Search Student
                                </label>
                                <input type="text" name="search" id="search_term" 
                                       class="form-control" 
                                       placeholder="Type student name..."
                                       value="<?php echo htmlspecialchars($filter_values['search_term']); ?>"
                                       autocomplete="off">
                            </div>
                            
                            <div class="col-md-2">
                                <label class="form-label fw-semibold">Department</label>
                                <select name="department_id" id="department_id" class="form-select">
                                    <option value="">All Departments</option>
                                    <?php foreach ($departments as $dept): ?>
                                        <option value="<?php echo $dept['id']; ?>"
                                            <?php echo $filter_values['department_id'] == $dept['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($dept['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="col-md-2">
                                <label class="form-label fw-semibold">Class</label>
                                <select name="class_id" id="class_id" class="form-select">
                                    <option value="">All Classes</option>
                                    <?php foreach ($classes as $class): ?>
                                        <option value="<?php echo $class['id']; ?>" 
                                                data-dept="<?php echo $class['department_id']; ?>"
                                                <?php echo $filter_values['class_id'] == $class['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($class['class_name']); ?>
                                            <span class="class-display-badge"><?php echo htmlspecialchars(getDisplayClassName($class['class_name'])); ?></span>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="col-md-2">
                                <label class="form-label fw-semibold">Term</label>
                                <select name="term_id" id="term_id" class="form-select">
                                    <option value="">All Terms</option>
                                    <?php foreach ($all_terms as $term): ?>
                                        <option value="<?php echo $term['id']; ?>"
                                            <?php echo $filter_values['term_id'] == $term['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($term['display']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="col-md-2">
                                <label class="form-label fw-semibold">Academic Year</label>
                                <select name="academic_year" class="form-select">
                                    <option value="">All Years</option>
                                    <?php foreach ($academic_years as $year): ?>
                                        <option value="<?php echo htmlspecialchars($year['year_label']); ?>"
                                            <?php echo $filter_values['academic_year'] == $year['year_label'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($year['year_label']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="col-md-1 d-flex align-items-end">
                                <button type="submit" class="btn btn-primary w-100">
                                    <i class="fas fa-filter"></i> Filter
                                </button>
                            </div>
                        </div>
                        
                        <div class="row mt-3">
                            <div class="col-md-12">
                                <a href="report_card.php" class="btn btn-outline-secondary btn-sm">
                                    <i class="fas fa-times me-1"></i>Clear All Filters
                                </a>
                                <a href="report_card.php?term_id=<?php echo $current_term_id; ?>" class="btn btn-outline-primary btn-sm ms-2">
                                    <i class="fas fa-calendar-check me-1"></i>Show Current Term Only
                                </a>
                                <button type="button" class="btn btn-danger btn-sm ms-2" onclick="toggleBulkPrintMode()">
                                    <i class="fas fa-layer-group me-1"></i>Bulk Print Mode
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
                
                <!-- AI Remark Generation Button -->
                <?php if (!empty($grouped_students)): ?>
                    <div class="mb-3">
                        <form method="POST" id="aiRemarkForm" class="d-flex flex-wrap gap-2 align-items-center">
                            <input type="hidden" name="generate_remarks" value="1">
                            <input type="hidden" name="student_ids" id="aiStudentIds" value="">
                            <button type="button" class="btn ai-generation-btn" onclick="generateAIForAll()">
                                <i class="fas fa-robot me-1"></i>Generate AI Remarks for All Students
                            </button>
                            <button type="button" class="btn ai-generation-btn" onclick="generateAIForSelected()">
                                <i class="fas fa-robot me-1"></i>Generate AI Remarks for Selected
                            </button>
                            <span class="text-muted small">
                                <i class="fas fa-info-circle me-1"></i>
                                AI generates personalized teacher and headmaster remarks based on individual performance
                            </span>
                        </form>
                    </div>
                <?php endif; ?>
                
                <!-- Results -->
                <?php if (empty($grouped_students)): ?>
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        No report cards found for the selected filters.
                    </div>
                <?php else: ?>
                    <?php foreach ($grouped_students as $class_name => $data): ?>
                        <div class="card mb-4 class-card" data-class-id="<?php echo $data['class_id']; ?>">
                            <div class="card-header">
                                <div class="d-flex justify-content-between align-items-center flex-wrap">
                                    <h4 class="mb-0">
                                        <i class="fas fa-chalkboard-teacher me-2"></i>
                                        <?php echo htmlspecialchars($data['display_name']); ?>
                                        <span class="badge bg-light text-dark ms-2">
                                            <?php echo count($data['students']); ?> student(s)
                                        </span>
                                    </h4>
                                    <div class="class-bulk-actions bulk-actions" style="display: none;">
                                        <button class="btn btn-sm btn-outline-light" onclick="selectAllInClass('<?php echo $data['class_id']; ?>')">
                                            <i class="fas fa-check-square me-1"></i>Select All
                                        </button>
                                        <button class="btn btn-sm btn-outline-light" onclick="printClass('<?php echo $data['class_id']; ?>')">
                                            <i class="fas fa-print me-1"></i>Print Class
                                        </button>
                                        <button class="btn btn-sm btn-outline-light" onclick="generateAIForClass('<?php echo $data['class_id']; ?>')">
                                            <i class="fas fa-robot me-1"></i>AI Remarks
                                        </button>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="card-body p-0">
                                <div class="table-responsive">
                                    <table class="table table-hover mb-0" id="class-table-<?php echo $data['class_id']; ?>">
                                        <thead>
                                            <tr>
                                                <th width="40" class="bulk-print-col" style="display: none;">
                                                    <input type="checkbox" class="select-all-checkbox" 
                                                           onchange="toggleSelectAllClass(this, '<?php echo $data['class_id']; ?>')">
                                                </th>
                                                <th width="60">Photo</th>
                                                <th>Student Name</th>
                                                <th>Attendance</th>
                                                <th>Term</th>
                                                <th class="remark-cell">Teacher Remark</th>
                                                <th class="remark-cell">Headmaster Remark</th>
                                                <th width="280">Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($data['students'] as $student): ?>
                                                <?php 
                                                $student_data_str = $student['user_id'] . '|' . $student['report_id'] . '|' . $student['term_id'] . '|' . $data['class_id'];
                                                $row_class = $student['has_complete_remarks'] ? 'status-complete' : ($student['has_teacher_remark'] || $student['has_headmaster_remark'] ? 'status-pending' : 'status-missing');
                                                
                                                $attendance_pct = isset($student['attendance']['attendance_percentage']) ? $student['attendance']['attendance_percentage'] : 0;
                                                $att_badge_class = 'high';
                                                if ($attendance_pct < 60) $att_badge_class = 'low';
                                                elseif ($attendance_pct < 75) $att_badge_class = 'medium';
                                                ?>
                                                <tr data-student-id="<?php echo $student['user_id']; ?>" 
                                                    data-report-id="<?php echo $student['report_id']; ?>"
                                                    data-term-id="<?php echo $student['term_id']; ?>"
                                                    data-class-id="<?php echo $data['class_id']; ?>"
                                                    data-student-data="<?php echo $student_data_str; ?>"
                                                    class="<?php echo $row_class; ?>">
                                                    <td class="bulk-print-col" style="display: none;">
                                                        <input type="checkbox" class="student-checkbox" 
                                                               value="<?php echo $student['report_id']; ?>"
                                                               data-user-id="<?php echo $student['user_id']; ?>"
                                                               data-term-id="<?php echo $student['term_id']; ?>"
                                                               onchange="updateSelectedCount()">
                                                    </td>
                                                    <td>
                                                        <img src="<?php echo htmlspecialchars($student['profile_picture']); ?>" 
                                                             alt="Profile" class="profile-img"
                                                             loading="lazy">
                                                    </td>
                                                    
                                                    <td>
                                                        <a href="view_report_card.php?report_id=<?php echo $student['report_id']; ?>&user_id=<?php echo $student['user_id']; ?>&term_id=<?php echo $student['term_id']; ?>" 
                                                           class="text-decoration-none fw-semibold text-primary">
                                                            <?php echo htmlspecialchars($student['name']); ?>
                                                        </a>
                                                        <?php if ($student['has_complete_remarks']): ?>
                                                            <i class="fas fa-check-circle text-success ms-1" title="Complete remarks generated"></i>
                                                        <?php elseif ($student['has_teacher_remark'] || $student['has_headmaster_remark']): ?>
                                                            <i class="fas fa-clock text-warning ms-1" title="Partial remarks"></i>
                                                        <?php else: ?>
                                                            <i class="fas fa-times-circle text-danger ms-1" title="No remarks"></i>
                                                        <?php endif; ?>
                                                    </td>
                                                    
                                                    <td>
                                                        <span class="attendance-badge <?php echo $att_badge_class; ?>">
                                                            <?php echo isset($student['attendance']['present']) ? $student['attendance']['present'] : 0; ?> / <?php echo isset($student['attendance']['total_marked']) ? $student['attendance']['total_marked'] : $TOTAL_SCHOOL_DAYS; ?>
                                                            (<?php echo number_format($attendance_pct, 1); ?>%)
                                                        </span>
                                                        <?php if (isset($student['attendance']['late']) && $student['attendance']['late'] > 0): ?>
                                                            <span class="text-muted small" title="Late days">+<?php echo $student['attendance']['late']; ?> late</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    
                                                    <td><?php echo htmlspecialchars($student['term_display']); ?></td>
                                                    
                                                    <td class="remark-cell">
                                                        <?php echo $student['remark_status']['teacher']; ?>
                                                        <?php if ($student['has_teacher_remark']): ?>
                                                            <span class="remark-preview" onclick="toggleRemark(this)" 
                                                                  data-remark="<?php echo htmlspecialchars($student['teacher_remark'] ?? ''); ?>"
                                                                  title="Click to expand">
                                                                <?php echo htmlspecialchars($student['teacher_remark_preview']); ?>
                                                                <i class="fas fa-chevron-down ms-1" style="font-size: 0.6rem;"></i>
                                                            </span>
                                                            <div class="remark-full"><?php echo nl2br(htmlspecialchars($student['teacher_remark'] ?? '')); ?></div>
                                                        <?php endif; ?>
                                                    </td>
                                                    
                                                    <td class="remark-cell">
                                                        <?php echo $student['remark_status']['headmaster']; ?>
                                                        <?php if ($student['has_headmaster_remark']): ?>
                                                            <span class="remark-preview" onclick="toggleRemark(this)" 
                                                                  data-remark="<?php echo htmlspecialchars($student['headmaster_remark'] ?? ''); ?>"
                                                                  title="Click to expand">
                                                                <?php echo htmlspecialchars($student['headmaster_remark_preview']); ?>
                                                                <i class="fas fa-chevron-down ms-1" style="font-size: 0.6rem;"></i>
                                                            </span>
                                                            <div class="remark-full"><?php echo nl2br(htmlspecialchars($student['headmaster_remark'] ?? '')); ?></div>
                                                        <?php endif; ?>
                                                    </td>
                                                    
                                                    <td>
                                                        <div class="action-buttons">
                                                            <a href="view_report_card.php?report_id=<?php echo $student['report_id']; ?>&user_id=<?php echo $student['user_id']; ?>&term_id=<?php echo $student['term_id']; ?>" 
                                                               class="btn btn-info btn-sm" title="View Report">
                                                                <i class="fas fa-eye"></i>
                                                            </a>
                                                            
                                                            <a href="print_report_grades.php?report_id=<?php echo $student['report_id']; ?>&user_id=<?php echo $student['user_id']; ?>&term_id=<?php echo $student['term_id']; ?>" 
                                                               class="btn btn-secondary btn-sm" title="Print Report">
                                                                <i class="fas fa-print"></i>
                                                            </a>
                                                            
                                                            <button type="button" class="btn ai-generation-btn btn-sm" 
                                                                    onclick="generateSingleAI('<?php echo $student_data_str; ?>')"
                                                                    title="Generate AI Remarks">
                                                                <i class="fas fa-robot"></i>
                                                            </button>
                                                            
                                                            <a href="edit_remarks.php?report_id=<?php echo $student['report_id']; ?>" 
                                                               class="btn btn-primary btn-sm" title="Edit Remarks">
                                                                <i class="fas fa-edit"></i>
                                                            </a>
                                                        </div>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            
            <div class="card-footer text-muted">
                <div class="row">
                    <div class="col-md-6">
                        <small>
                            <i class="fas fa-info-circle me-1"></i>
                            Showing <?php echo $total_students; ?> report(s) across <?php echo count($grouped_students); ?> class(es)
                        </small>
                    </div>
                    <div class="col-md-6 text-end">
                        <small>Last updated: <?php echo date('F j, Y, g:i a'); ?></small>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <?php include '../templates/admin_footer.php'; ?>
    
    <!-- Bulk Print Form -->
    <form id="bulkPrintForm" method="POST" action="print_report_grades.php" target="_blank" style="display: none;">
        <input type="hidden" name="bulk_print" value="1">
        <input type="hidden" name="report_ids" id="bulkReportIds" value="">
        <input type="hidden" name="user_ids" id="bulkUserIds" value="">
        <input type="hidden" name="term_ids" id="bulkTermIds" value="">
    </form>
    
    <!-- AI Remark Form -->
    <form id="aiRemarkFormHidden" method="POST" style="display: none;">
        <input type="hidden" name="generate_remarks" value="1">
        <input type="hidden" name="student_ids" id="aiStudentIdsHidden" value="">
    </form>
    
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://code.jquery.com/ui/1.12.1/jquery-ui.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
    let bulkPrintMode = false;
    
    $(document).ready(function() {
        $("#search_term").autocomplete({
            source: function(request, response) {
                $.ajax({
                    url: window.location.pathname,
                    dataType: 'json',
                    data: {
                        action: 'get_student_suggestions',
                        term: request.term,
                        department_id: $('#department_id').val(),
                        class_id: $('#class_id').val()
                    },
                    success: function(data) {
                        response(data);
                    }
                });
            },
            minLength: 2
        });
        
        function filterClasses() {
            var deptId = $('#department_id').val();
            $('#class_id option').each(function() {
                var $option = $(this);
                if ($option.val() === '' || deptId === '') {
                    $option.show();
                } else {
                    $option.toggle($option.data('dept') == deptId);
                }
            });
            
            if ($('#class_id option:selected').is(':hidden')) {
                $('#class_id').val('');
            }
        }
        
        $('#department_id').on('change', filterClasses);
        filterClasses();
    });
    
    // ============================================
    // Toggle Remark Function
    // ============================================
    function toggleRemark(element) {
        const parent = element.closest('.remark-cell');
        const fullRemark = parent.querySelector('.remark-full');
        const preview = element;
        
        if (fullRemark) {
            fullRemark.classList.toggle('show');
            const icon = preview.querySelector('i');
            if (icon) {
                icon.classList.toggle('fa-chevron-down');
                icon.classList.toggle('fa-chevron-up');
            }
        }
    }
    
    // ============================================
    // Bulk Print Functions
    // ============================================
    function toggleBulkPrintMode() {
        bulkPrintMode = !bulkPrintMode;
        
        if (bulkPrintMode) {
            $('.bulk-print-col').show();
            $('.bulk-actions').show();
            $('#bulkPrintBar').show();
        } else {
            $('.bulk-print-col').hide();
            $('.bulk-actions').hide();
            $('#bulkPrintBar').hide();
            clearAllSelections();
        }
    }
    
    function selectAllInClass(classId) {
        const table = $(`#class-table-${classId}`);
        const checkboxes = table.find('.student-checkbox');
        const selectAllCheckbox = table.find('.select-all-checkbox');
        const isChecked = selectAllCheckbox.prop('checked');
        
        checkboxes.prop('checked', !isChecked);
        selectAllCheckbox.prop('checked', !isChecked);
        
        updateSelectedCount();
    }
    
    function toggleSelectAllClass(checkbox, classId) {
        const table = $(`#class-table-${classId}`);
        const checkboxes = table.find('.student-checkbox');
        checkboxes.prop('checked', checkbox.checked);
        
        updateSelectedCount();
    }
    
    function updateSelectedCount() {
        const selected = $('.student-checkbox:checked').length;
        $('#selectedCount').text(selected);
        
        $('.class-card').each(function() {
            const classId = $(this).data('class-id');
            const table = $(`#class-table-${classId}`);
            const total = table.find('.student-checkbox').length;
            const checked = table.find('.student-checkbox:checked').length;
            const selectAll = table.find('.select-all-checkbox');
            
            if (total > 0) {
                selectAll.prop('checked', checked === total);
                selectAll.prop('indeterminate', checked > 0 && checked < total);
            }
        });
    }
    
    function clearAllSelections() {
        $('.student-checkbox').prop('checked', false);
        $('.select-all-checkbox').prop('checked', false).prop('indeterminate', false);
        $('#selectedCount').text('0');
    }
    
    function printClass(classId) {
        const table = $(`#class-table-${classId}`);
        const checkboxes = table.find('.student-checkbox');
        
        const reportIds = [];
        const userIds = [];
        const termIds = [];
        
        checkboxes.each(function() {
            const $row = $(this).closest('tr');
            reportIds.push($(this).val());
            userIds.push($(this).data('user-id'));
            termIds.push($(this).data('term-id'));
        });
        
        if (reportIds.length === 0) {
            alert('No students in this class to print.');
            return;
        }
        
        submitBulkPrint(reportIds, userIds, termIds);
    }
    
    function bulkPrintSelected() {
        const selectedCheckboxes = $('.student-checkbox:checked');
        
        if (selectedCheckboxes.length === 0) {
            alert('Please select at least one student to print.');
            return;
        }
        
        const reportIds = [];
        const userIds = [];
        const termIds = [];
        
        selectedCheckboxes.each(function() {
            reportIds.push($(this).val());
            userIds.push($(this).data('user-id'));
            termIds.push($(this).data('term-id'));
        });
        
        submitBulkPrint(reportIds, userIds, termIds);
    }
    
    function submitBulkPrint(reportIds, userIds, termIds) {
        $('#bulkReportIds').val(reportIds.join(','));
        $('#bulkUserIds').val(userIds.join(','));
        $('#bulkTermIds').val(termIds.join(','));
        $('#bulkPrintForm').submit();
        
        setTimeout(() => {
            toggleBulkPrintMode();
        }, 500);
    }
    
    // ============================================
    // AI Remark Generation Functions
    // ============================================
    
    function getSelectedStudentData() {
        const selected = $('.student-checkbox:checked');
        const data = [];
        selected.each(function() {
            const $row = $(this).closest('tr');
            const studentData = $row.data('student-data');
            if (studentData) {
                data.push(studentData);
            }
        });
        return data;
    }
    
    function getAllStudentData() {
        const data = [];
        $('tr[data-student-data]').each(function() {
            const studentData = $(this).data('student-data');
            if (studentData) {
                data.push(studentData);
            }
        });
        return data;
    }
    
    function getClassStudentData(classId) {
        const table = $(`#class-table-${classId}`);
        const data = [];
        table.find('tr[data-student-data]').each(function() {
            const studentData = $(this).data('student-data');
            if (studentData) {
                data.push(studentData);
            }
        });
        return data;
    }
    
    function submitAIRequest(studentData) {
        if (studentData.length === 0) {
            alert('No students selected.');
            return;
        }
        
        console.log('Submitting AI request for', studentData.length, 'students');
        
        $('#aiStudentIdsHidden').val(studentData.join(','));
        $('#aiRemarkFormHidden').submit();
    }
    
    function generateAIForAll() {
        const allData = getAllStudentData();
        if (allData.length === 0) {
            alert('No students found to generate remarks for.');
            return;
        }
        
        if (confirm(`Generate personalized AI remarks for all ${allData.length} student(s)? This may take a few moments.`)) {
            submitAIRequest(allData);
        }
    }
    
    function generateAIForSelected() {
        const selectedData = getSelectedStudentData();
        if (selectedData.length === 0) {
            alert('Please select at least one student using the checkboxes.');
            return;
        }
        
        if (confirm(`Generate personalized AI remarks for ${selectedData.length} selected student(s)?`)) {
            submitAIRequest(selectedData);
        }
    }
    
    function generateAIForClass(classId) {
        const classData = getClassStudentData(classId);
        if (classData.length === 0) {
            alert('No students in this class.');
            return;
        }
        
        if (confirm(`Generate personalized AI remarks for all ${classData.length} student(s) in this class?`)) {
            submitAIRequest(classData);
        }
    }
    
    function generateSingleAI(studentData) {
        if (confirm('Generate personalized AI remarks for this student?')) {
            submitAIRequest([studentData]);
        }
    }
    </script>
</body>
</html>
<?php
$conn->close();
ob_end_flush();
?>