<?php
// ============================================
// STUDENT ACADEMIC DIAGNOSIS SYSTEM
// students_diagnosis.php
// Version: 5.0
//  - Essential criteria only (driven by diagnosis_criteria.is_active)
//  - Single shared grading scale for PHP + JavaScript (they used to disagree)
//  - Server is now the authority on status/grade; client value is ignored
//  - One batched criteria lookup instead of a query per score
//  - Auto-save detection fixed (was reading the value attribute, not the value)
// ============================================

// ============================================
// ERROR REPORTING & DEBUGGING
// ============================================
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/error_log.txt');

// ============================================
// START SESSION
// ============================================
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ============================================
// RESTRICT ACCESS - TEACHERS ONLY
// ============================================
if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'teacher') {
    error_log("Unauthorized access attempt: user_id=" . ($_SESSION['user_id'] ?? 'unset') . ", role=" . ($_SESSION['user_role'] ?? 'unset'));
    $_SESSION['error_msg'] = "Please log in as a teacher.";
    header("Location: /login.php");
    exit();
}

// ============================================
// DATABASE CONNECTION
// ============================================
$db_paths = [
    $_SERVER['DOCUMENT_ROOT'] . '/config/db.php',
    __DIR__ . '/../config/db.php',
    __DIR__ . '/../../config/db.php',
    __DIR__ . '/config/db.php',
    __DIR__ . '/includes/db.php'
];

$conn = null;
$db_found = false;

foreach ($db_paths as $path) {
    if (file_exists($path)) {
        try {
            require_once $path;
            $db_found = true;
            break;
        } catch (Exception $e) {
            error_log("Failed to include db.php from $path: " . $e->getMessage());
        }
    }
}

// If db.php not found or connection not established, use direct connection
if (!$db_found || !isset($conn) || $conn === null) {
    require_once $_SERVER['DOCUMENT_ROOT'] . '/config/db.php';
}

// Verify connection
if (!$conn || $conn->connect_error) {
    die("Database Connection Failed: " . ($conn ? $conn->connect_error : 'No connection object'));
}

// ============================================
// SHARED GRADING SCALE
// --------------------------------------------
// One definition, used by the PHP save routine AND echoed into the
// JavaScript below. Previously the two had different cut-offs, so the
// grade a teacher saw while typing could differ from the grade that
// actually got saved. Edit the bands here only.
// ============================================
$GRADE_BANDS = [
    ['min' => 90, 'grade' => 'A',  'status' => 'excellent',         'icon' => '🌟', 'label' => 'EXCELLENT'],
    ['min' => 80, 'grade' => 'B+', 'status' => 'excellent',         'icon' => '🌟', 'label' => 'EXCELLENT'],
    ['min' => 70, 'grade' => 'B',  'status' => 'good',              'icon' => '👍', 'label' => 'GOOD'],
    ['min' => 60, 'grade' => 'C+', 'status' => 'good',              'icon' => '👍', 'label' => 'GOOD'],
    ['min' => 50, 'grade' => 'C',  'status' => 'average',           'icon' => '📝', 'label' => 'AVERAGE'],
    ['min' => 40, 'grade' => 'D',  'status' => 'average',           'icon' => '📝', 'label' => 'AVERAGE'],
    ['min' => 25, 'grade' => 'E',  'status' => 'needs_improvement', 'icon' => '⚠️', 'label' => 'NEEDS IMPROVEMENT'],
    ['min' => 0,  'grade' => 'F',  'status' => 'critical',          'icon' => '🔴', 'label' => 'CRITICAL'],
];

function diagnosis_band($pct) {
    global $GRADE_BANDS;
    foreach ($GRADE_BANDS as $band) {
        if ($pct >= $band['min']) {
            return $band;
        }
    }
    return end($GRADE_BANDS);
}

/**
 * Resolve the numeric academic_years.id for a year label, falling back to
 * whichever year is flagged active. This lookup used to be copy-pasted in
 * four places.
 */
function resolve_academic_year_id($conn, $year_label) {
    $year_id = 0;

    if ($year_label) {
        $stmt = $conn->prepare("SELECT id FROM academic_years WHERE year_label = ? AND is_active = 1 LIMIT 1");
        if ($stmt) {
            $stmt->bind_param("s", $year_label);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($res && $res->num_rows > 0) {
                $row = $res->fetch_assoc();
                $year_id = (int)$row['id'];
            }
            $stmt->close();
        }
    }

    if (!$year_id) {
        $res = $conn->query("SELECT id FROM academic_years WHERE is_active = 1 LIMIT 1");
        if ($res && $res->num_rows > 0) {
            $row = $res->fetch_assoc();
            $year_id = (int)$row['id'];
        }
    }

    return $year_id;
}

// ============================================
// AUTHENTICATION CHECK
// ============================================
$teacher_id = isset($_SESSION['user_id']) ? intval($_SESSION['user_id']) : 0;

if (!$teacher_id) {
    header('Location: ../login.php?redirect=' . urlencode($_SERVER['REQUEST_URI']));
    exit();
}

// Check if user exists and is active
$user_role = 'teacher';
$teacher_name = 'Teacher';
$teacher_department_id = null;

try {
    $role_query = "SELECT id, role, name, department_id FROM users WHERE id = ? AND status = 'active'";
    $stmt = $conn->prepare($role_query);
    if ($stmt) {
        $stmt->bind_param("i", $teacher_id);
        $stmt->execute();
        $user_result = $stmt->get_result();

        if ($user_result->num_rows === 0) {
            session_destroy();
            header('Location: ../login.php?error=account_inactive');
            exit();
        }

        $user_data = $user_result->fetch_assoc();
        $user_role = $user_data['role'] ?? 'teacher';
        $teacher_name = $user_data['name'] ?? 'Teacher';
        $teacher_department_id = $user_data['department_id'] ?? null;
        $stmt->close();
    }
} catch (Exception $e) {
    error_log("Auth check error: " . $e->getMessage());
}

// Check if user is teacher or admin
$is_admin = ($user_role === 'admin');
$admin_flag = $is_admin ? 1 : 0; // bind_param needs a real int, not a bool
if ($user_role !== 'teacher' && !$is_admin) {
    header('Location: ../dashboard.php?error=unauthorized');
    exit();
}

// ============================================
// GET CURRENT ACADEMIC YEAR & TERM FROM ACADEMIC_CALENDAR
// ============================================
$current_date = date('Y-m-d');
$academic_year = null;
$current_term_name = null;
$current_term_id = null;
$term_start_date = null;
$term_end_date = null;

try {
    // First try: Find active term based on current date
    $academic_info_query = "SELECT `year`, `term_name`, `start_date`, `end_date` 
                            FROM academic_calendar 
                            WHERE ? BETWEEN start_date AND end_date 
                            ORDER BY start_date ASC 
                            LIMIT 1";
    $academic_info_stmt = $conn->prepare($academic_info_query);
    if ($academic_info_stmt) {
        $academic_info_stmt->bind_param("s", $current_date);
        $academic_info_stmt->execute();
        $academic_info_result = $academic_info_stmt->get_result();

        if ($academic_info_result->num_rows > 0) {
            $academic_info = $academic_info_result->fetch_assoc();
            $academic_year = $academic_info['year'];
            $current_term_name = $academic_info['term_name'];
            $term_start_date = $academic_info['start_date'];
            $term_end_date = $academic_info['end_date'];

            // Extract term number from term_name (e.g., "Term 1" -> 1)
            preg_match('/\d+/', $current_term_name, $matches);
            $current_term_id = !empty($matches) ? (int)$matches[0] : null;
        }
        $academic_info_stmt->close();
    }

    // Second try: If no active term, find the most recent or upcoming term
    if (!$academic_year) {
        $fallback_query = "SELECT `year`, `term_name`, `start_date`, `end_date` 
                           FROM academic_calendar 
                           WHERE start_date <= DATE_ADD(?, INTERVAL 60 DAY) 
                           ORDER BY ABS(DATEDIFF(start_date, ?)) ASC 
                           LIMIT 1";
        $fallback_stmt = $conn->prepare($fallback_query);
        if ($fallback_stmt) {
            $fallback_stmt->bind_param("ss", $current_date, $current_date);
            $fallback_stmt->execute();
            $fallback_result = $fallback_stmt->get_result();

            if ($fallback_result->num_rows > 0) {
                $academic_info = $fallback_result->fetch_assoc();
                $academic_year = $academic_info['year'];
                $current_term_name = $academic_info['term_name'] . ' (Upcoming)';
                $term_start_date = $academic_info['start_date'];
                $term_end_date = $academic_info['end_date'];

                preg_match('/\d+/', $academic_info['term_name'], $matches);
                $current_term_id = !empty($matches) ? (int)$matches[0] : 1;
            }
            $fallback_stmt->close();
        }
    }

    // Ultimate fallback: Estimate based on current date
    if (!$academic_year) {
        $current_year = date('Y');
        $current_month = date('n');

        if ($current_month >= 9) {
            $academic_year = $current_year . '/' . ($current_year + 1);
            $current_term_id = 1;
            $current_term_name = 'Term 1 (Estimated)';
        } elseif ($current_month >= 1 && $current_month <= 4) {
            $academic_year = ($current_year - 1) . '/' . $current_year;
            $current_term_id = 2;
            $current_term_name = 'Term 2 (Estimated)';
        } elseif ($current_month >= 5 && $current_month <= 7) {
            $academic_year = ($current_year - 1) . '/' . $current_year;
            $current_term_id = 3;
            $current_term_name = 'Term 3 (Estimated)';
        } else {
            $academic_year = $current_year . '/' . ($current_year + 1);
            $current_term_id = 1;
            $current_term_name = 'Term 1 (Estimated)';
        }
    }

    error_log("Academic Year: $academic_year, Term: $current_term_name (ID: $current_term_id)");

} catch (Exception $e) {
    error_log("Academic calendar error: " . $e->getMessage());
}

// ============================================
// PROCESS FORM SUBMISSION (POST)
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $response = ['success' => false, 'message' => 'Unknown error'];

    try {
        // Get POST data
        $student_id = isset($_POST['student_id']) ? intval($_POST['student_id']) : 0;
        $class_id = isset($_POST['class_id']) ? intval($_POST['class_id']) : 0;
        $term_id = isset($_POST['term_id']) ? intval($_POST['term_id']) : $current_term_id;
        $academic_year_id = isset($_POST['academic_year_id']) ? intval($_POST['academic_year_id']) : 0;
        $diagnosis_id = isset($_POST['diagnosis_id']) && $_POST['diagnosis_id'] ? intval($_POST['diagnosis_id']) : null;
        $scores = isset($_POST['scores']) && is_array($_POST['scores']) ? $_POST['scores'] : [];
        $comments = isset($_POST['comments']) ? trim($_POST['comments']) : '';
        $recommendations = isset($_POST['recommendations']) ? trim($_POST['recommendations']) : '';
        $is_auto_save = isset($_POST['auto_save']) && $_POST['auto_save'] == '1';

        // Teacher-selected subject mastery / struggling tags (from the tagging UI in the form)
        $mastery_subject_ids = isset($_POST['mastery_subjects']) ? array_map('intval', (array)$_POST['mastery_subjects']) : [];
        $struggling_subject_ids = isset($_POST['struggling_subjects']) ? array_map('intval', (array)$_POST['struggling_subjects']) : [];
        $mastery_subject_ids = array_values(array_unique(array_filter($mastery_subject_ids, function ($v) { return $v > 0; })));
        $struggling_subject_ids = array_values(array_unique(array_filter($struggling_subject_ids, function ($v) { return $v > 0; })));
        // A subject can't be both at once - if the client sends an overlap (e.g. JS was bypassed),
        // mastery wins and the subject is dropped from struggling.
        $struggling_subject_ids = array_values(array_diff($struggling_subject_ids, $mastery_subject_ids));

        if (!$student_id || !$class_id) {
            throw new Exception('Missing required student or class information');
        }

        if (!$academic_year_id) {
            $academic_year_id = resolve_academic_year_id($conn, $academic_year);
        }

        // Verify teacher teaches this class (admin bypass)
        if (!$is_admin) {
            $verify = $conn->prepare("SELECT id FROM class_schedules WHERE class_id = ? AND teacher_id = ? AND academic_year = ?");
            if ($verify) {
                $verify->bind_param("iis", $class_id, $teacher_id, $academic_year);
                $verify->execute();
                $verify_result = $verify->get_result();
                if ($verify_result->num_rows === 0) {
                    throw new Exception('You are not authorized to diagnose this class');
                }
                $verify->close();
            }
        }

        // ------------------------------------------------
        // Load the metadata for every submitted criterion in ONE query.
        // This also silently drops any criterion that has since been
        // deactivated, so retired criteria can't sneak back into a save.
        // ------------------------------------------------
        $criteria_meta = [];
        $criterion_ids = array_values(array_filter(array_map('intval', array_keys($scores)), function ($v) { return $v > 0; }));
        if (!empty($criterion_ids)) {
            $id_list = implode(',', $criterion_ids);
            $meta_result = $conn->query("SELECT id, weight, max_score FROM diagnosis_criteria WHERE is_active = 1 AND id IN ($id_list)");
            if ($meta_result) {
                while ($m = $meta_result->fetch_assoc()) {
                    $criteria_meta[(int)$m['id']] = [
                        'weight'    => (float)($m['weight'] ?? 1),
                        'max_score' => (float)($m['max_score'] ?? 20),
                    ];
                }
            }
        }

        // Calculate overall score
        $total_score = 0;
        $total_weight = 0;
        $filled_count = 0;
        $max_possible_score = 0;
        $clean_scores = [];

        foreach ($scores as $criterion_id => $raw_score) {
            $criterion_id = (int)$criterion_id;
            if (!isset($criteria_meta[$criterion_id])) continue;
            if ($raw_score === '' || $raw_score === null) continue;

            $weight = $criteria_meta[$criterion_id]['weight'] ?: 1;
            $max_score = $criteria_meta[$criterion_id]['max_score'] ?: 20;

            // Clamp so a hand-edited form can't post 999 for a /20 criterion
            $score = max(0, min((float)$raw_score, $max_score));

            $total_score += $score * $weight;
            $total_weight += $weight;
            $max_possible_score += $max_score * $weight;
            $filled_count++;
            $clean_scores[$criterion_id] = ['score' => $score, 'max_score' => $max_score];
        }

        $overall_score = $total_weight > 0 ? ($total_score / $total_weight) : 0;
        $overall_percentage = $max_possible_score > 0 ? ($total_score / $max_possible_score) * 100 : 0;

        // Status and grade are derived server-side from the shared bands.
        // The hidden form fields are display-only and deliberately ignored.
        $band = diagnosis_band($overall_percentage);
        $overall_status = $band['status'];
        $academic_grade = $band['grade'];

        $conn->begin_transaction();

        // Save diagnosis
        if ($diagnosis_id) {
            $update = $conn->prepare("UPDATE student_diagnosis SET 
                overall_score = ?, 
                overall_status = ?, 
                academic_grade = ?,
                comments = ?, 
                recommendations = ?,
                diagnosis_date = CURDATE() 
                WHERE id = ? AND (teacher_id = ? OR ? = 1)");
            if ($update) {
                $update->bind_param("dssssiii", $overall_score, $overall_status, $academic_grade,
                                   $comments, $recommendations, $diagnosis_id, $teacher_id, $admin_flag);
                $update->execute();
                $update->close();
            }
            $conn->query("DELETE FROM student_diagnosis_scores WHERE diagnosis_id = " . intval($diagnosis_id));
        } else {
            // Check if exists
            $check = $conn->prepare("SELECT id FROM student_diagnosis WHERE student_id = ? AND class_id = ? AND term_id = ? AND academic_year_id = ?");
            $existing_row = null;
            if ($check) {
                $check->bind_param("iiii", $student_id, $class_id, $term_id, $academic_year_id);
                $check->execute();
                $check_result = $check->get_result();
                if ($check_result && $check_result->num_rows > 0) {
                    $existing_row = $check_result->fetch_assoc();
                }
                $check->close();
            }

            if ($existing_row) {
                $diagnosis_id = (int)$existing_row['id'];
                $update = $conn->prepare("UPDATE student_diagnosis SET 
                    overall_score = ?, 
                    overall_status = ?, 
                    academic_grade = ?,
                    comments = ?, 
                    recommendations = ?,
                    diagnosis_date = CURDATE() 
                    WHERE id = ?");
                if ($update) {
                    $update->bind_param("dssssi", $overall_score, $overall_status, $academic_grade,
                                       $comments, $recommendations, $diagnosis_id);
                    $update->execute();
                    $update->close();
                }
                $conn->query("DELETE FROM student_diagnosis_scores WHERE diagnosis_id = " . intval($diagnosis_id));
            } else {
                $insert = $conn->prepare("INSERT INTO student_diagnosis 
                    (student_id, teacher_id, class_id, term_id, academic_year_id, 
                     diagnosis_date, overall_status, overall_score, academic_grade, comments, recommendations) 
                    VALUES (?, ?, ?, ?, ?, CURDATE(), ?, ?, ?, ?, ?)");
                if ($insert) {
                    $insert->bind_param("iiiiisdsss",
                        $student_id, $teacher_id, $class_id, $term_id, $academic_year_id,
                        $overall_status, $overall_score, $academic_grade, $comments, $recommendations
                    );
                    $insert->execute();
                    $diagnosis_id = $conn->insert_id;
                    $insert->close();
                }
            }
        }

        // Insert scores
        if (!empty($clean_scores) && $diagnosis_id) {
            $score_stmt = $conn->prepare("INSERT INTO student_diagnosis_scores (diagnosis_id, criterion_id, score, remarks) VALUES (?, ?, ?, ?)");
            if ($score_stmt) {
                foreach ($clean_scores as $criterion_id => $data) {
                    // Remarks are relative to that criterion's own max, not a hardcoded 20
                    $pct_score = $data['max_score'] > 0 ? ($data['score'] / $data['max_score']) * 100 : 0;
                    if ($pct_score >= 80) $remarks = 'Excellent performance';
                    elseif ($pct_score >= 60) $remarks = 'Good performance';
                    elseif ($pct_score >= 40) $remarks = 'Average performance';
                    elseif ($pct_score >= 25) $remarks = 'Below average, needs improvement';
                    else $remarks = 'Needs significant improvement';

                    $score_value = $data['score'];
                    $score_stmt->bind_param("iids", $diagnosis_id, $criterion_id, $score_value, $remarks);
                    $score_stmt->execute();
                }
                $score_stmt->close();
            }
        }

        // Save teacher-selected subject mastery / struggling tags.
        // Non-critical relative to the diagnosis itself: if the tagging table
        // hasn't been created yet, don't let it roll back the whole save.
        $tags_saved = false;
        if ($diagnosis_id) {
            try {
                $conn->query("DELETE FROM student_diagnosis_subject_tags WHERE diagnosis_id = " . intval($diagnosis_id));
                $tag_stmt = $conn->prepare("INSERT INTO student_diagnosis_subject_tags (diagnosis_id, subject_id, tag_type) VALUES (?, ?, ?)");
                if ($tag_stmt) {
                    $tag_type = 'mastery';
                    foreach ($mastery_subject_ids as $sid) {
                        $tag_stmt->bind_param("iis", $diagnosis_id, $sid, $tag_type);
                        $tag_stmt->execute();
                    }
                    $tag_type = 'struggling';
                    foreach ($struggling_subject_ids as $sid) {
                        $tag_stmt->bind_param("iis", $diagnosis_id, $sid, $tag_type);
                        $tag_stmt->execute();
                    }
                    $tag_stmt->close();
                }
                $tags_saved = true;
            } catch (Exception $e) {
                error_log("Subject tag save error (non-critical): " . $e->getMessage());
            }
        }

        $conn->commit();

        // Log activity
        try {
            $log = $conn->prepare("INSERT INTO activity_logs (user_id, action, description) VALUES (?, 'diagnosis_saved', ?)");
            if ($log) {
                $desc = ($is_auto_save ? 'Auto-saved' : 'Saved') . " diagnosis for student ID: $student_id with status: $overall_status, grade: $academic_grade ("
                        . round($overall_percentage, 1) . "%)";
                $log->bind_param("is", $teacher_id, $desc);
                $log->execute();
                $log->close();
            }
        } catch (Exception $e) {
            error_log("Activity log error: " . $e->getMessage());
        }

        $response = [
            'success' => true,
            'message' => $is_auto_save ? 'Auto-saved successfully' : 'Diagnosis saved successfully',
            'diagnosis_id' => $diagnosis_id,
            'overall_score' => round($overall_score, 1),
            'overall_percentage' => round($overall_percentage, 1),
            'overall_status' => $overall_status,
            'academic_grade' => $academic_grade,
            'filled_count' => $filled_count,
            'total_criteria' => count($criteria_meta),
            'mastery_subject_ids' => $mastery_subject_ids,
            'struggling_subject_ids' => $struggling_subject_ids,
            'tags_saved' => $tags_saved
        ];

    } catch (Exception $e) {
        if (isset($conn)) {
            try {
                $conn->rollback();
            } catch (Exception $rollback_e) {
                // Ignore rollback errors
            }
        }
        $response = ['success' => false, 'message' => $e->getMessage()];
    }

    echo json_encode($response);
    exit;
}

// ============================================
// GET DATA FOR DISPLAY (GET)
// ============================================
$selected_class = isset($_GET['class_id']) ? intval($_GET['class_id']) : null;
$selected_student = isset($_GET['student_id']) ? intval($_GET['student_id']) : null;
$show_history = isset($_GET['history']) ? intval($_GET['history']) : 0;

// Resolved once for the whole page
$year_id = resolve_academic_year_id($conn, $academic_year);

// Get teacher's classes
$classes = [];
try {
    if ($academic_year) {
        if ($is_admin) {
            $class_query = $conn->query("SELECT id, class_name, department FROM classes ORDER BY class_name");
            if ($class_query) {
                $classes = $class_query->fetch_all(MYSQLI_ASSOC);
            }
        } else {
            $class_query = $conn->prepare("
                SELECT DISTINCT c.id, c.class_name, c.department 
                FROM classes c
                JOIN class_schedules cs ON cs.class_id = c.id
                WHERE cs.teacher_id = ? AND cs.academic_year = ?
                ORDER BY c.class_name
            ");
            if ($class_query) {
                $class_query->bind_param("is", $teacher_id, $academic_year);
                $class_query->execute();
                $result = $class_query->get_result();
                if ($result) {
                    $classes = $result->fetch_all(MYSQLI_ASSOC);
                }
                $class_query->close();
            }
        }
    }
} catch (Exception $e) {
    error_log("Classes query error: " . $e->getMessage());
}

// ============================================
// Get students for selected class
// ------------------------------------------------
// Filters on sc.academic_year (the varchar column assign_class.php and
// promote_learners.php actually populate) rather than sc.academic_year_id,
// which is NULL on every real row.
// ============================================
$students = [];
$students_query_error = '';
try {
    if ($selected_class && $academic_year) {
        $student_query = $conn->prepare("
            SELECT DISTINCT u.id, u.name, u.email, u.profile_picture, u.admission_date
            FROM users u
            JOIN student_classes sc ON sc.user_id = u.id
            WHERE sc.class_id = ? AND sc.academic_year = ? AND u.role = 'student' AND u.status = 'active'
            ORDER BY u.name
        ");
        if ($student_query) {
            $student_query->bind_param("is", $selected_class, $academic_year);
            $student_query->execute();
            $result = $student_query->get_result();
            if ($result) {
                $students = $result->fetch_all(MYSQLI_ASSOC);
            }
            $student_query->close();
        } else {
            $students_query_error = 'Failed to prepare students query: ' . $conn->error;
            error_log($students_query_error);
        }
    }
} catch (Exception $e) {
    $students_query_error = $e->getMessage();
    error_log("Students query error: " . $e->getMessage());
}

// ============================================
// DIAGNOSIS CRITERIA (ESSENTIAL SET ONLY)
// ------------------------------------------------
// Only criteria with is_active = 1 are shown or scored. Trimming the
// checklist is therefore a data change, not a code change - run
// essential_criteria.sql to retire the long list and keep the core set.
// Retired criteria stay in the table so old diagnoses keep their history.
// ============================================
$criteria_by_category = [];
$total_criteria = 0;
try {
    $crit_query = $conn->query("
        SELECT c.*, cat.name as category_name, cat.weight as category_weight
        FROM diagnosis_criteria c
        JOIN diagnosis_criteria_categories cat ON cat.id = c.category_id
        WHERE c.is_active = 1
        ORDER BY cat.sort_order, c.sort_order
    ");
    if ($crit_query) {
        while ($row = $crit_query->fetch_assoc()) {
            $criteria_by_category[$row['category_name']][] = $row;
            $total_criteria++;
        }
    }
} catch (Exception $e) {
    error_log("Criteria query error: " . $e->getMessage());
}

// Get existing diagnosis
$existing_diagnosis = null;
$existing_scores = [];
try {
    if ($selected_student && $selected_class && $current_term_id && $year_id) {
        $diag_query = $conn->prepare("
            SELECT d.*, t.term_name, y.year_label
            FROM student_diagnosis d
            JOIN academic_terms t ON t.id = d.term_id
            JOIN academic_years y ON y.id = d.academic_year_id
            WHERE d.student_id = ? AND d.class_id = ? AND d.term_id = ? AND d.academic_year_id = ?
            ORDER BY d.created_at DESC LIMIT 1
        ");
        if ($diag_query) {
            $diag_query->bind_param("iiii", $selected_student, $selected_class, $current_term_id, $year_id);
            $diag_query->execute();
            $result = $diag_query->get_result();
            if ($result) {
                $existing_diagnosis = $result->fetch_assoc();
            }
            $diag_query->close();
        }

        if ($existing_diagnosis) {
            $scores_query = $conn->prepare("
                SELECT s.*, c.name as criterion_name 
                FROM student_diagnosis_scores s
                JOIN diagnosis_criteria c ON c.id = s.criterion_id
                WHERE s.diagnosis_id = ?
            ");
            if ($scores_query) {
                $scores_query->bind_param("i", $existing_diagnosis['id']);
                $scores_query->execute();
                $result = $scores_query->get_result();
                if ($result) {
                    while ($row = $result->fetch_assoc()) {
                        $existing_scores[$row['criterion_id']] = $row;
                    }
                }
                $scores_query->close();
            }
        }
    }
} catch (Exception $e) {
    error_log("Diagnosis query error: " . $e->getMessage());
}

// Get diagnosis history
$history = [];
try {
    if ($selected_student) {
        $history_query = $conn->prepare("
            SELECT d.*, t.term_name, y.year_label,
            COUNT(s.id) as criteria_count,
            ROUND(AVG(s.score), 1) as avg_score
            FROM student_diagnosis d
            JOIN academic_terms t ON t.id = d.term_id
            JOIN academic_years y ON y.id = d.academic_year_id
            LEFT JOIN student_diagnosis_scores s ON s.diagnosis_id = d.id
            WHERE d.student_id = ?
            GROUP BY d.id
            ORDER BY d.created_at DESC
            LIMIT 10
        ");
        if ($history_query) {
            $history_query->bind_param("i", $selected_student);
            $history_query->execute();
            $result = $history_query->get_result();
            if ($result) {
                $history = $result->fetch_all(MYSQLI_ASSOC);
            }
            $history_query->close();
        }
    }
} catch (Exception $e) {
    error_log("History query error: " . $e->getMessage());
}

// ============================================
// SUBJECT LIST FOR THE MASTERY TAGGING PICKER
// ============================================
$all_subjects = [];
try {
    if ($teacher_department_id && !$is_admin) {
        $subj_list_stmt = $conn->prepare("SELECT id, subject_name, subject_code FROM subjects WHERE status = 'Active' AND (department_id = ? OR department_id IS NULL) ORDER BY subject_name");
        if ($subj_list_stmt) {
            $subj_list_stmt->bind_param("i", $teacher_department_id);
            $subj_list_stmt->execute();
            $result = $subj_list_stmt->get_result();
            if ($result) {
                $all_subjects = $result->fetch_all(MYSQLI_ASSOC);
            }
            $subj_list_stmt->close();
        }
    }
    if (empty($all_subjects)) {
        $subj_list_query = $conn->query("SELECT id, subject_name, subject_code FROM subjects WHERE status = 'Active' ORDER BY subject_name");
        if ($subj_list_query) {
            $all_subjects = $subj_list_query->fetch_all(MYSQLI_ASSOC);
        }
    }
} catch (Exception $e) {
    error_log("Subjects list query error: " . $e->getMessage());
}

// ============================================
// EXISTING TEACHER-SELECTED MASTERY / STRUGGLING TAGS
// ============================================
$existing_mastery_tags = [];
$existing_struggling_tags = [];
try {
    if ($existing_diagnosis) {
        $tag_query = $conn->prepare("SELECT subject_id, tag_type FROM student_diagnosis_subject_tags WHERE diagnosis_id = ?");
        if ($tag_query) {
            $tag_query->bind_param("i", $existing_diagnosis['id']);
            $tag_query->execute();
            $result = $tag_query->get_result();
            if ($result) {
                while ($row = $result->fetch_assoc()) {
                    if ($row['tag_type'] === 'mastery') {
                        $existing_mastery_tags[] = (int)$row['subject_id'];
                    } else {
                        $existing_struggling_tags[] = (int)$row['subject_id'];
                    }
                }
            }
            $tag_query->close();
        }
    }
} catch (Exception $e) {
    // Table may not exist yet if the migration hasn't been run - fail quietly
    error_log("Subject tag query error: " . $e->getMessage());
}

// ============================================
// GET SUBJECT MASTERY & STRUGGLING AREAS
// ============================================
define('SUBJECT_MASTERY_THRESHOLD', 75);   // avg >= this  -> mastery (strength)
define('SUBJECT_STRUGGLE_THRESHOLD', 50);  // avg <  this  -> struggling

$subject_raw = [];
$subject_mastery_error = '';
try {
    if ($selected_student && $selected_class) {
        $subj_query = $conn->prepare("
            SELECT s.id AS subject_id, s.subject_name, s.subject_code,
                   g.mid_term_exam, g.end_term_exam, g.total, g.term_id,
                   t.term_name, t.term_number, y.year_label
            FROM grades g
            JOIN subjects s ON s.id = g.subject_id
            LEFT JOIN academic_terms t ON t.id = g.term_id
            LEFT JOIN academic_years y ON y.id = t.academic_year_id
            WHERE g.user_id = ? AND g.class_id = ?
            ORDER BY s.subject_name ASC, t.term_number ASC
        ");
        if ($subj_query) {
            $subj_query->bind_param("ii", $selected_student, $selected_class);
            $subj_query->execute();
            $result = $subj_query->get_result();
            if ($result) {
                while ($row = $result->fetch_assoc()) {
                    $subject_raw[] = $row;
                }
            }
            $subj_query->close();
        } else {
            $subject_mastery_error = 'Failed to prepare subject mastery query: ' . $conn->error;
            error_log($subject_mastery_error);
        }
    }
} catch (Exception $e) {
    $subject_mastery_error = $e->getMessage();
    error_log("Subject mastery query error: " . $e->getMessage());
}

// Aggregate per subject
$subject_agg = [];
foreach ($subject_raw as $row) {
    if ($row['total'] === null) continue; // skip ungraded rows
    $sid = $row['subject_id'];
    if (!isset($subject_agg[$sid])) {
        $subject_agg[$sid] = [
            'subject_id'   => $sid,
            'subject_name' => $row['subject_name'],
            'subject_code' => $row['subject_code'],
            'records'      => [],
            'sum'          => 0,
            'count'        => 0,
            'min'          => null,
            'max'          => null,
        ];
    }
    $total = (float)$row['total'];
    $subject_agg[$sid]['records'][] = [
        'term_name'      => $row['term_name'] ?? 'Unknown Term',
        'year_label'     => $row['year_label'] ?? '',
        'total'          => $total,
        'mid_term_exam'  => $row['mid_term_exam'],
        'end_term_exam'  => $row['end_term_exam'],
    ];
    $subject_agg[$sid]['sum'] += $total;
    $subject_agg[$sid]['count']++;
    $subject_agg[$sid]['min'] = $subject_agg[$sid]['min'] === null ? $total : min($subject_agg[$sid]['min'], $total);
    $subject_agg[$sid]['max'] = $subject_agg[$sid]['max'] === null ? $total : max($subject_agg[$sid]['max'], $total);
}

// Build the summary list: average, latest term, trend, category
$subject_summary = [];
foreach ($subject_agg as $sid => $data) {
    $avg = $data['count'] > 0 ? ($data['sum'] / $data['count']) : 0;
    $latest = end($data['records']);
    $first = reset($data['records']);

    $category = 'developing';
    if ($avg >= SUBJECT_MASTERY_THRESHOLD) {
        $category = 'mastery';
    } elseif ($avg < SUBJECT_STRUGGLE_THRESHOLD) {
        $category = 'struggling';
    }

    // Simple trend: compare latest recorded score to the earliest one on file
    $trend = 'flat';
    if ($data['count'] > 1) {
        if ($latest['total'] > $first['total'] + 2) $trend = 'up';
        elseif ($latest['total'] < $first['total'] - 2) $trend = 'down';
    }

    $subject_summary[] = [
        'subject_id'    => $sid,
        'subject_name'  => $data['subject_name'],
        'subject_code'  => $data['subject_code'],
        'average'       => round($avg, 1),
        'min'           => round($data['min'], 1),
        'max'           => round($data['max'], 1),
        'latest_score'  => round($latest['total'], 1),
        'latest_term'   => $latest['term_name'],
        'records_count' => $data['count'],
        'category'      => $category,
        'trend'         => $trend,
    ];
}

// Split into buckets, each sorted so the most notable subject shows first
$mastery_subjects = array_values(array_filter($subject_summary, function ($s) { return $s['category'] === 'mastery'; }));
$struggling_subjects = array_values(array_filter($subject_summary, function ($s) { return $s['category'] === 'struggling'; }));
$developing_subjects = array_values(array_filter($subject_summary, function ($s) { return $s['category'] === 'developing'; }));

usort($mastery_subjects, function ($a, $b) { return $b['average'] <=> $a['average']; });      // strongest first
usort($struggling_subjects, function ($a, $b) { return $a['average'] <=> $b['average']; });   // weakest first
usort($developing_subjects, function ($a, $b) { return $a['average'] <=> $b['average']; });

// Full list, weakest to strongest, for the detailed breakdown table
$subject_summary_all = array_merge($struggling_subjects, $developing_subjects, $mastery_subjects);

$subject_overall_avg = 0;
if (count($subject_summary) > 0) {
    $subject_overall_avg = array_sum(array_column($subject_summary, 'average')) / count($subject_summary);
}

// Get statistics
$stats = [
    'total_diagnosed' => 0,
    'excellent' => 0,
    'good' => 0,
    'average' => 0,
    'needs_improvement' => 0,
    'critical' => 0
];
try {
    if ($selected_class && $current_term_id && $year_id) {
        $stats_query = $conn->prepare("
            SELECT 
                COUNT(DISTINCT student_id) as total_diagnosed,
                SUM(CASE WHEN overall_status = 'excellent' THEN 1 ELSE 0 END) as excellent,
                SUM(CASE WHEN overall_status = 'good' THEN 1 ELSE 0 END) as good,
                SUM(CASE WHEN overall_status = 'average' THEN 1 ELSE 0 END) as average,
                SUM(CASE WHEN overall_status = 'needs_improvement' THEN 1 ELSE 0 END) as needs_improvement,
                SUM(CASE WHEN overall_status = 'critical' THEN 1 ELSE 0 END) as critical
            FROM student_diagnosis
            WHERE teacher_id = ? AND class_id = ? AND term_id = ? AND academic_year_id = ?
        ");
        if ($stats_query) {
            $stats_query->bind_param("iiii", $teacher_id, $selected_class, $current_term_id, $year_id);
            $stats_query->execute();
            $result = $stats_query->get_result();
            if ($result) {
                $fetched = $result->fetch_assoc();
                if ($fetched) {
                    $stats = array_map(function ($v) { return (int)$v; }, $fetched);
                }
            }
            $stats_query->close();
        }
    }
} catch (Exception $e) {
    error_log("Stats query error: " . $e->getMessage());
}

// Class name mapping for display
$class_mapping = [
    'Esther Foundation' => 'Creche',
    'Sheba One' => 'Nursery 1',
    'Sheba Two' => 'Nursery 2',
    'Yaasant One' => 'KG 1',
    'Yaasant Two' => 'KG 2',
    'Cleopatra One' => 'Basic 1',
    'Cleopatra Two' => 'Basic 2',
    'Nefertiti One' => 'Basic 3',
    'Nefertiti Two' => 'Basic 4',
    'Malika One' => 'Basic 5',
    'Malika Two' => 'Basic 6',
    'Makeda One' => 'Basic 7',
    'Makeda Two' => 'Basic 8',
    'An Majesty' => 'Basic 9'
];

function getDisplayClassName($class_name) {
    global $class_mapping;
    return $class_mapping[$class_name] ?? $class_name;
}

function getTermDisplayName($term_name) {
    return $term_name;
}

// ============================================
// INCLUDE HEADER TEMPLATE
// ============================================
$header_paths = [
    $_SERVER['DOCUMENT_ROOT'] . '/templates/teacher_header.php',
    __DIR__ . '/../templates/teacher_header.php',
    __DIR__ . '/../../templates/teacher_header.php',
    __DIR__ . '/templates/teacher_header.php',
    __DIR__ . '/includes/templates/teacher_header.php'
];

$header_included = false;
foreach ($header_paths as $path) {
    if (file_exists($path)) {
        require_once $path;
        $header_included = true;
        break;
    }
}

if (!$header_included) {
    error_log("Warning: teacher_header.php not found in any expected location");
    if (!function_exists('render_teacher_header')) {
        function render_teacher_header($title, $teacher_name, $academic_year = null, $current_term_name = null) {
            ?>
            <nav class="navbar navbar-expand-lg navbar-dark bg-primary">
                <div class="container-fluid">
                    <a class="navbar-brand" href="dashboard.php">Teacher Portal</a>
                    <div class="navbar-nav ms-auto">
                        <span class="navbar-text text-white me-3">
                            <i class="fas fa-user me-1"></i><?php echo htmlspecialchars($teacher_name); ?>
                        </span>
                        <a class="nav-link" href="logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a>
                    </div>
                </div>
            </nav>
            <?php
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Academic Diagnosis - Teacher Portal</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #4A6CF7;
            --primary-dark: #3a5bd9;
            --secondary: #6C63FF;
            --success: #28A745;
            --warning: #FFC107;
            --danger: #DC3545;
            --info: #17A2B8;
            --dark: #2D3748;
            --light: #F8F9FA;
            --border: #E2E8F0;
            --radius: 16px;
            --shadow: 0 2px 12px rgba(0,0,0,0.06);
        }

        * { box-sizing: border-box; }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #f0f2f5;
            color: var(--dark);
            padding: 20px;
        }

        .container-fluid { max-width: 1400px; margin: 0 auto; }

        .header {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: white;
            padding: 25px 30px;
            border-radius: var(--radius);
            margin-bottom: 25px;
            box-shadow: 0 4px 20px rgba(74,108,247,0.3);
        }
        .header h2 { margin: 0; font-weight: 700; font-size: 1.8rem; }
        .header p { margin: 5px 0 0; opacity: 0.9; }
        .header .badge-custom {
            background: rgba(255,255,255,0.2);
            padding: 6px 16px;
            border-radius: 20px;
            font-size: 0.85rem;
            display: inline-block;
            margin: 2px;
        }

        .card-custom {
            background: white;
            border-radius: var(--radius);
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: var(--shadow);
            border: 1px solid rgba(0,0,0,0.04);
            transition: all 0.3s ease;
        }
        .card-custom:hover { box-shadow: 0 4px 20px rgba(0,0,0,0.08); }
        .card-custom .card-title {
            font-weight: 600;
            font-size: 1.05rem;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }
        .card-custom .card-title i { color: var(--primary); }

        .selector-group {
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
            align-items: center;
        }
        .selector-group select {
            padding: 10px 16px;
            border-radius: 12px;
            border: 2px solid var(--border);
            font-size: 0.95rem;
            background: white;
            min-width: 200px;
            transition: all 0.3s;
        }
        .selector-group select:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(74,108,247,0.15);
            outline: none;
        }
        .selector-group .btn-primary {
            border-radius: 12px;
            padding: 10px 25px;
            font-weight: 500;
            background: var(--primary);
            border: none;
            transition: all 0.3s;
            color: white;
            text-decoration: none;
        }
        .selector-group .btn-primary:hover {
            background: var(--primary-dark);
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(74,108,247,0.3);
            color: white;
        }
        .selector-group .btn-outline {
            border-radius: 12px;
            padding: 10px 25px;
            font-weight: 500;
            background: transparent;
            border: 2px solid var(--border);
            color: var(--dark);
            transition: all 0.3s;
            text-decoration: none;
        }
        .selector-group .btn-outline:hover {
            background: var(--light);
            border-color: #c0c8d8;
            color: var(--dark);
        }

        .student-profile {
            display: flex;
            align-items: center;
            gap: 20px;
            padding: 15px 20px;
            background: var(--light);
            border-radius: 12px;
            flex-wrap: wrap;
        }
        .student-profile .avatar {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            font-weight: 700;
            flex-shrink: 0;
        }
        .student-profile .avatar img {
            width: 100%;
            height: 100%;
            border-radius: 50%;
            object-fit: cover;
        }
        .student-profile .info { flex: 1; }
        .student-profile .info h5 { margin: 0; font-weight: 600; }
        .student-profile .info small { color: #888; font-size: 0.85rem; display: block; }
        .student-profile .status-badge {
            padding: 6px 16px;
            border-radius: 20px;
            font-weight: 600;
            font-size: 0.85rem;
            display: inline-block;
        }

        .stat-card {
            text-align: center;
            padding: 12px 10px;
            border-radius: 12px;
            background: var(--light);
            transition: all 0.3s;
        }
        .stat-card:hover { transform: translateY(-2px); }
        .stat-card .number { font-size: 1.5rem; font-weight: 700; }
        .stat-card .label { font-size: 0.75rem; color: #888; }

        .criteria-group {
            border: 1px solid var(--border);
            border-radius: 12px;
            overflow: hidden;
            margin-bottom: 15px;
        }
        .criteria-group-header {
            background: var(--light);
            padding: 10px 20px;
            font-weight: 600;
            border-bottom: 1px solid var(--border);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
        }
        .criteria-group-header .badge-count {
            background: var(--primary);
            color: white;
            padding: 2px 12px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 500;
        }
        .criteria-item {
            padding: 10px 20px;
            border-bottom: 1px solid #f0f0f0;
            display: flex;
            align-items: center;
            gap: 15px;
            transition: background 0.2s;
            flex-wrap: wrap;
        }
        .criteria-item:hover { background: #fafafa; }
        .criteria-item:last-child { border-bottom: none; }
        .criteria-item .label { flex: 1; font-size: 0.95rem; min-width: 150px; }
        .criteria-item .label small { display: block; color: #888; font-size: 0.8rem; }
        .criteria-item .range { font-size: 0.8rem; color: #888; min-width: 70px; text-align: right; }
        .criteria-item .score-input {
            width: 80px;
            text-align: center;
            border: 2px solid var(--border);
            border-radius: 8px;
            padding: 6px 10px;
            font-size: 0.95rem;
            transition: all 0.3s;
        }
        .criteria-item .score-input:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(74,108,247,0.15);
            outline: none;
        }
        .criteria-item .score-input.excellent { border-color: var(--success); background: rgba(40,167,69,0.05); }
        .criteria-item .score-input.good { border-color: var(--secondary); background: rgba(108,99,255,0.05); }
        .criteria-item .score-input.average { border-color: var(--warning); background: rgba(255,193,7,0.05); }
        .criteria-item .score-input.poor { border-color: var(--danger); background: rgba(220,53,69,0.05); }

        .overall-status {
            padding: 25px;
            border-radius: 12px;
            text-align: center;
            margin-top: 20px;
            transition: all 0.5s ease;
        }
        .overall-status.excellent { background: rgba(40,167,69,0.1); border: 2px solid var(--success); }
        .overall-status.good { background: rgba(108,99,255,0.1); border: 2px solid var(--secondary); }
        .overall-status.average { background: rgba(255,193,7,0.1); border: 2px solid var(--warning); }
        .overall-status.needs_improvement { background: rgba(255,152,0,0.1); border: 2px solid #FF9800; }
        .overall-status.critical { background: rgba(220,53,69,0.1); border: 2px solid var(--danger); }
        .overall-status .icon { font-size: 3rem; display: block; margin-bottom: 5px; }
        .overall-status .label { font-size: 1.5rem; font-weight: 700; }
        .overall-status .score { font-size: 1rem; opacity: 0.8; }
        .overall-status .grade { font-size: 2rem; font-weight: 700; margin-top: 5px; }

        .history-item {
            padding: 12px 15px;
            border-left: 3px solid var(--primary);
            background: var(--light);
            border-radius: 0 10px 10px 0;
            margin-bottom: 10px;
            transition: all 0.2s;
        }
        .history-item:hover { background: #f0f2f5; }
        .history-item .date { font-size: 0.8rem; color: #888; }
        .history-item .progress { height: 4px; margin-top: 5px; }

        .btn-save {
            background: var(--primary);
            color: white;
            border: none;
            border-radius: 12px;
            padding: 10px 30px;
            font-weight: 500;
            transition: all 0.3s;
        }
        .btn-save:hover { background: var(--primary-dark); color: white; transform: translateY(-2px); }
        .btn-save:disabled { opacity: 0.6; cursor: not-allowed; transform: none; }
        .btn-print { background: #6C757D; color: white; border: none; border-radius: 12px; padding: 10px 30px; transition: all 0.3s; }
        .btn-print:hover { background: #5a6268; color: white; }
        .btn-reset { background: var(--border); color: var(--dark); border: none; border-radius: 12px; padding: 10px 30px; transition: all 0.3s; }
        .btn-reset:hover { background: #d1d7e0; }

        .toast-container { position: fixed; top: 20px; right: 20px; z-index: 9999; max-width: 400px; }
        .toast-custom {
            padding: 12px 20px;
            border-radius: 12px;
            color: white;
            margin-bottom: 10px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.15);
            animation: slideInRight 0.3s ease;
        }
        .toast-custom.success { background: var(--success); }
        .toast-custom.error { background: var(--danger); }
        .toast-custom.info { background: var(--info); }

        @keyframes slideInRight {
            from { transform: translateX(100%); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }

        .loading-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(255,255,255,0.8);
            z-index: 9998;
            justify-content: center;
            align-items: center;
        }
        .loading-overlay.active { display: flex; }

        .academic-info-banner {
            background: linear-gradient(135deg, #f97316, #ea580c);
            color: white;
            padding: 12px 20px;
            border-radius: 12px;
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
        }

        .empty-state {
            text-align: center;
            padding: 40px 20px;
        }
        .empty-state i { opacity: 0.25; }

        .template-header-wrapper { margin-bottom: 20px; }
        .template-header-wrapper .navbar {
            border-radius: var(--radius);
            box-shadow: var(--shadow);
        }

        .score-scale-indicator {
            font-size: 0.75rem;
            color: #888;
            padding: 4px 12px;
            background: var(--light);
            border-radius: 20px;
            display: inline-block;
        }

        /* ============================================
           SUBJECT MASTERY & STRUGGLING AREAS
        ============================================ */
        .mastery-summary-row {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            margin-bottom: 18px;
        }
        .mastery-summary-pill {
            flex: 1;
            min-width: 140px;
            padding: 12px 16px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .mastery-summary-pill.mastery { background: rgba(40,167,69,0.08); border: 1px solid rgba(40,167,69,0.25); }
        .mastery-summary-pill.developing { background: rgba(255,193,7,0.08); border: 1px solid rgba(255,193,7,0.3); }
        .mastery-summary-pill.struggling { background: rgba(220,53,69,0.08); border: 1px solid rgba(220,53,69,0.25); }
        .mastery-summary-pill .icon { font-size: 1.4rem; }
        .mastery-summary-pill .count { font-size: 1.3rem; font-weight: 700; line-height: 1; }
        .mastery-summary-pill .text-label { font-size: 0.75rem; color: #666; }

        .mastery-columns {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
            margin-bottom: 20px;
        }
        .mastery-column-header {
            font-weight: 600;
            font-size: 0.95rem;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .mastery-column-header.strengths { color: var(--success); }
        .mastery-column-header.struggles { color: var(--danger); }

        .subject-mini-card {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            padding: 12px 14px;
            border-radius: 10px;
            margin-bottom: 8px;
            border: 1px solid var(--border);
            background: white;
        }
        .subject-mini-card.mastery { border-left: 4px solid var(--success); }
        .subject-mini-card.struggling { border-left: 4px solid var(--danger); }
        .subject-mini-card .subject-name { font-weight: 600; font-size: 0.9rem; }
        .subject-mini-card .subject-meta { font-size: 0.75rem; color: #888; }
        .subject-mini-card .subject-score-badge {
            font-weight: 700;
            font-size: 0.9rem;
            padding: 4px 12px;
            border-radius: 20px;
            white-space: nowrap;
        }
        .subject-mini-card.mastery .subject-score-badge { background: rgba(40,167,69,0.12); color: var(--success); }
        .subject-mini-card.struggling .subject-score-badge { background: rgba(220,53,69,0.12); color: var(--danger); }
        .subject-mini-card .trend-icon { margin-left: 4px; font-size: 0.75rem; }
        .subject-mini-card .trend-icon.up { color: var(--success); }
        .subject-mini-card .trend-icon.down { color: var(--danger); }
        .subject-mini-card .trend-icon.flat { color: #aaa; }

        .subject-empty-col {
            padding: 16px;
            text-align: center;
            color: #aaa;
            font-size: 0.85rem;
            border: 1px dashed var(--border);
            border-radius: 10px;
        }

        .subject-breakdown-table { margin-top: 10px; }
        .subject-breakdown-row {
            display: flex;
            align-items: center;
            gap: 14px;
            padding: 10px 6px;
            border-bottom: 1px solid #f0f0f0;
            flex-wrap: wrap;
        }
        .subject-breakdown-row:last-child { border-bottom: none; }
        .subject-breakdown-row .name-col { flex: 1; min-width: 140px; font-weight: 600; font-size: 0.9rem; }
        .subject-breakdown-row .name-col small { display: block; font-weight: 400; color: #888; font-size: 0.75rem; }
        .subject-progress-track {
            flex: 2;
            min-width: 150px;
            height: 8px;
            border-radius: 6px;
            background: #eef0f4;
            overflow: hidden;
        }
        .subject-progress-fill { height: 100%; border-radius: 6px; transition: width 0.4s ease; }
        .subject-progress-fill.mastery { background: var(--success); }
        .subject-progress-fill.developing { background: var(--warning); }
        .subject-progress-fill.struggling { background: var(--danger); }
        .subject-breakdown-row .score-col { width: 90px; text-align: right; font-weight: 700; font-size: 0.9rem; }
        .subject-breakdown-row .status-pill {
            font-size: 0.7rem;
            font-weight: 600;
            padding: 3px 10px;
            border-radius: 20px;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }
        .status-pill.mastery { background: rgba(40,167,69,0.12); color: var(--success); }
        .status-pill.developing { background: rgba(255,193,7,0.15); color: #8a6d00; }
        .status-pill.struggling { background: rgba(220,53,69,0.12); color: var(--danger); }

        @media (max-width: 768px) {
            .mastery-columns { grid-template-columns: 1fr; }
        }

        /* Subject mastery tagging (checkbox chips inside the diagnosis form) */
        .subject-tag-grid {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            padding: 12px;
            border: 1px solid var(--border);
            border-radius: 12px;
            background: var(--light);
            max-height: 220px;
            overflow-y: auto;
        }
        .subject-tag-chip {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 14px;
            border-radius: 20px;
            border: 2px solid var(--border);
            background: white;
            font-size: 0.85rem;
            cursor: pointer;
            transition: all 0.2s;
            user-select: none;
        }
        .subject-tag-chip:hover { border-color: #c0c8d8; }
        .subject-tag-chip input[type="checkbox"] { display: none; }
        .subject-tag-chip.mastery-chip.active {
            background: rgba(40,167,69,0.12);
            border-color: var(--success);
            color: #1d7a34;
            font-weight: 600;
        }
        .subject-tag-chip.struggling-chip.active {
            background: rgba(220,53,69,0.12);
            border-color: var(--danger);
            color: #a71d2a;
            font-weight: 600;
        }

        /* Print-only tag summary, built by JS just before printing */
        .print-only { display: none; }

        @media print {
            .no-print { display: none !important; }
            .print-only { display: block !important; }
            .header { background: #333 !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            .overall-status { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            .student-profile .avatar { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            body { padding: 0; background: white; }
            .card-custom { box-shadow: none; border: 1px solid #ddd; }
            .template-header-wrapper { display: none; }
            .subject-tag-grid { display: none !important; }
        }

        @media (max-width: 768px) {
            .selector-group { flex-direction: column; align-items: stretch; }
            .selector-group select { min-width: auto; }
            .student-profile { flex-direction: column; text-align: center; }
            .criteria-item { flex-wrap: wrap; }
            .criteria-item .score-input { width: 100%; margin-top: 5px; }
            .criteria-item .range { text-align: left; min-width: auto; }
            .header h2 { font-size: 1.3rem; }
            .header { padding: 15px 20px; }
            .toast-container { max-width: 90%; right: 5%; }
            .academic-info-banner { flex-direction: column; text-align: center; gap: 5px; }
        }
    </style>
</head>
<body>
    <!-- Template Header -->
    <div class="template-header-wrapper no-print">
        <?php
        if (function_exists('render_teacher_header')) {
            render_teacher_header('Student Academic Diagnosis', $teacher_name, $academic_year, $current_term_name);
        }
        ?>
    </div>

    <!-- Loading Overlay -->
    <div class="loading-overlay" id="loadingOverlay">
        <div class="text-center">
            <div class="spinner-border text-primary" style="width: 3rem; height: 3rem;" role="status">
                <span class="visually-hidden">Loading...</span>
            </div>
            <p class="mt-2">Saving...</p>
        </div>
    </div>

    <div class="container-fluid">
        <!-- Toast Container -->
        <div class="toast-container" id="toastContainer"></div>

        <!-- ============================================ -->
        <!-- HEADER -->
        <!-- ============================================ -->
        <div class="header d-flex justify-content-between align-items-center flex-wrap">
            <div>
                <h2><i class="fas fa-stethoscope me-2"></i>Student Academic Diagnosis</h2>
                <p>Core assessment and performance tracking</p>
            </div>
            <div class="d-flex gap-2 flex-wrap">
                <span class="badge-custom"><i class="fas fa-calendar-alt me-1"></i><?php echo htmlspecialchars(getTermDisplayName($current_term_name ?? 'Current Term')); ?></span>
                <span class="badge-custom"><i class="fas fa-calendar me-1"></i><?php echo htmlspecialchars($academic_year ?? 'Current Year'); ?></span>
                <span class="badge-custom"><i class="fas fa-user me-1"></i><?php echo htmlspecialchars($teacher_name); ?></span>
                <?php if ($is_admin): ?>
                <span class="badge-custom"><i class="fas fa-shield-alt me-1"></i>Admin</span>
                <?php endif; ?>
            </div>
        </div>

        <!-- Academic Info Banner -->
        <div class="academic-info-banner no-print">
            <span><i class="fas fa-graduation-cap me-2"></i>Academic Year: <strong><?php echo htmlspecialchars($academic_year ?? 'Not Set'); ?></strong></span>
            <span><i class="fas fa-book-open me-2"></i>Current Term: <strong><?php echo htmlspecialchars(getTermDisplayName($current_term_name ?? 'Not Set')); ?></strong></span>
            <?php if ($term_start_date && $term_end_date): ?>
            <span><i class="fas fa-calendar-range me-2"></i><?php echo htmlspecialchars($term_start_date); ?> - <?php echo htmlspecialchars($term_end_date); ?></span>
            <?php endif; ?>
            <span class="score-scale-indicator"><i class="fas fa-ruler me-1"></i>Score Scale: 0 - 20</span>
        </div>

        <!-- ============================================ -->
        <!-- STUDENT SELECTOR -->
        <!-- ============================================ -->
        <div class="card-custom no-print">
            <div class="card-title"><i class="fas fa-user-graduate"></i>Select Student</div>
            <form method="GET" action="" class="selector-group">
                <select name="class_id" onchange="this.form.submit()" class="form-select">
                    <option value="">Select Class</option>
                    <?php foreach ($classes as $class): ?>
                        <option value="<?php echo htmlspecialchars($class['id']); ?>" <?php echo $selected_class == $class['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars(getDisplayClassName($class['class_name'])); ?>
                            <?php if ($class['department']): ?>(<?php echo htmlspecialchars($class['department']); ?>)<?php endif; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <select name="student_id" class="form-select" <?php echo empty($students) ? 'disabled' : ''; ?>>
                    <option value="">
                        <?php echo $selected_class ? (empty($students) ? 'No students in this class' : 'Select Student (' . count($students) . ')') : 'Select Student'; ?>
                    </option>
                    <?php foreach ($students as $student): ?>
                        <option value="<?php echo htmlspecialchars($student['id']); ?>" <?php echo $selected_student == $student['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($student['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="btn btn-primary"><i class="fas fa-search me-2"></i>Load</button>
                <a href="students_diagnosis.php" class="btn btn-outline"><i class="fas fa-redo me-2"></i>Reset</a>
            </form>
            <?php if ($selected_class && empty($students)): ?>
                <div class="alert alert-warning mt-3 mb-0">
                    <i class="fas fa-exclamation-triangle me-2"></i>
                    No students are assigned to this class for <?php echo htmlspecialchars($academic_year ?? 'the current academic year'); ?>.
                    Check <strong>Assign Classes</strong> or <strong>Promote Learners</strong> to confirm enrollment for this year.
                </div>
            <?php endif; ?>
        </div>

        <?php if ($selected_student && $selected_class): ?>
        <!-- ============================================ -->
        <!-- STUDENT PROFILE & STATS -->
        <!-- ============================================ -->
        <div class="card-custom">
            <?php
            $student = null;
            foreach ($students as $s) {
                if ($s['id'] == $selected_student) {
                    $student = $s;
                    break;
                }
            }
            $initial = $student ? strtoupper(substr($student['name'], 0, 1)) : '?';
            ?>
            <div class="student-profile">
                <div class="avatar">
                    <?php if ($student && !empty($student['profile_picture'])): ?>
                        <img src="<?php echo htmlspecialchars($student['profile_picture']); ?>" alt="Student">
                    <?php else: ?>
                        <?php echo $initial; ?>
                    <?php endif; ?>
                </div>
                <div class="info">
                    <h5><?php echo htmlspecialchars($student['name'] ?? 'Student'); ?></h5>
                    <small><i class="fas fa-envelope me-1"></i><?php echo htmlspecialchars($student['email'] ?? 'N/A'); ?></small>
                    <?php if (!empty($student['admission_date'])): ?>
                        <small><i class="fas fa-calendar-plus me-1"></i>Admitted: <?php echo date('M d, Y', strtotime($student['admission_date'])); ?></small>
                    <?php endif; ?>
                </div>
                <?php if ($existing_diagnosis): ?>
                    <div class="ms-auto text-end">
                        <span class="status-badge bg-<?php
                            echo $existing_diagnosis['overall_status'] == 'excellent' ? 'success' :
                                ($existing_diagnosis['overall_status'] == 'good' ? 'primary' :
                                ($existing_diagnosis['overall_status'] == 'average' ? 'warning' :
                                ($existing_diagnosis['overall_status'] == 'needs_improvement' ? 'warning' : 'danger')));
                        ?>">
                            <?php echo ucfirst(str_replace('_', ' ', $existing_diagnosis['overall_status'])); ?>
                            <?php if ($existing_diagnosis['academic_grade']): ?>
                                (Grade: <?php echo htmlspecialchars($existing_diagnosis['academic_grade']); ?>)
                            <?php endif; ?>
                        </span>
                        <small class="d-block text-muted mt-1">Score: <?php echo round($existing_diagnosis['overall_score'], 1); ?>/20</small>
                        <small class="d-block text-muted">Last: <?php echo date('M d, Y', strtotime($existing_diagnosis['created_at'] ?? 'now')); ?></small>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Quick Stats -->
            <div class="row mt-3 g-2">
                <div class="col-4 col-md-2">
                    <div class="stat-card"><div class="number text-success"><?php echo $stats['excellent'] ?? 0; ?></div><div class="label">Excellent</div></div>
                </div>
                <div class="col-4 col-md-2">
                    <div class="stat-card"><div class="number text-primary"><?php echo $stats['good'] ?? 0; ?></div><div class="label">Good</div></div>
                </div>
                <div class="col-4 col-md-2">
                    <div class="stat-card"><div class="number text-warning"><?php echo $stats['average'] ?? 0; ?></div><div class="label">Average</div></div>
                </div>
                <div class="col-4 col-md-2">
                    <div class="stat-card"><div class="number text-warning"><?php echo $stats['needs_improvement'] ?? 0; ?></div><div class="label">Needs Improvement</div></div>
                </div>
                <div class="col-4 col-md-2">
                    <div class="stat-card"><div class="number text-danger"><?php echo $stats['critical'] ?? 0; ?></div><div class="label">Critical</div></div>
                </div>
                <div class="col-4 col-md-2">
                    <div class="stat-card"><div class="number text-info"><?php echo $stats['total_diagnosed'] ?? 0; ?></div><div class="label">Total Diagnosed</div></div>
                </div>
            </div>
        </div>

        <!-- ============================================ -->
        <!-- SUBJECT MASTERY & STRUGGLING AREAS -->
        <!-- ============================================ -->
        <div class="card-custom">
            <div class="card-title">
                <i class="fas fa-brain"></i> Subject Mastery &amp; Areas for Attention
                <?php if (!empty($subject_summary)): ?>
                <span class="badge bg-secondary ms-2"><?php echo count($subject_summary); ?> subjects on record</span>
                <span class="badge bg-info ms-2">Overall avg: <?php echo round($subject_overall_avg, 1); ?>%</span>
                <?php endif; ?>
            </div>

            <?php if (empty($subject_summary)): ?>
                <div class="alert alert-info mb-0">
                    <i class="fas fa-info-circle me-2"></i>
                    No graded subject records found yet for this student in this class. Once scores are entered in the
                    <strong>Grades</strong> module, mastery and struggling areas will appear here automatically.
                </div>
            <?php else: ?>
                <!-- Summary pills -->
                <div class="mastery-summary-row">
                    <div class="mastery-summary-pill mastery">
                        <span class="icon">🌟</span>
                        <div>
                            <div class="count"><?php echo count($mastery_subjects); ?></div>
                            <div class="text-label">Strong Subjects (≥ <?php echo SUBJECT_MASTERY_THRESHOLD; ?>%)</div>
                        </div>
                    </div>
                    <div class="mastery-summary-pill developing">
                        <span class="icon">📘</span>
                        <div>
                            <div class="count"><?php echo count($developing_subjects); ?></div>
                            <div class="text-label">Developing (<?php echo SUBJECT_STRUGGLE_THRESHOLD; ?>–<?php echo SUBJECT_MASTERY_THRESHOLD; ?>%)</div>
                        </div>
                    </div>
                    <div class="mastery-summary-pill struggling">
                        <span class="icon">⚠️</span>
                        <div>
                            <div class="count"><?php echo count($struggling_subjects); ?></div>
                            <div class="text-label">Struggling (&lt; <?php echo SUBJECT_STRUGGLE_THRESHOLD; ?>%)</div>
                        </div>
                    </div>
                </div>

                <!-- Strengths vs Struggles columns -->
                <div class="mastery-columns">
                    <div>
                        <div class="mastery-column-header strengths"><i class="fas fa-thumbs-up"></i> Strengths</div>
                        <?php if (empty($mastery_subjects)): ?>
                            <div class="subject-empty-col">No subjects at mastery level yet.</div>
                        <?php else: ?>
                            <?php foreach ($mastery_subjects as $subj): ?>
                            <div class="subject-mini-card mastery">
                                <div>
                                    <div class="subject-name"><?php echo htmlspecialchars($subj['subject_name']); ?></div>
                                    <div class="subject-meta">
                                        Latest: <?php echo htmlspecialchars($subj['latest_term']); ?> (<?php echo $subj['latest_score']; ?>%)
                                        <?php if ($subj['trend'] === 'up'): ?><i class="fas fa-arrow-up trend-icon up" title="Improving"></i>
                                        <?php elseif ($subj['trend'] === 'down'): ?><i class="fas fa-arrow-down trend-icon down" title="Declining"></i>
                                        <?php else: ?><i class="fas fa-minus trend-icon flat" title="Stable"></i>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <span class="subject-score-badge"><?php echo $subj['average']; ?>%</span>
                            </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                    <div>
                        <div class="mastery-column-header struggles"><i class="fas fa-triangle-exclamation"></i> Needs Attention</div>
                        <?php if (empty($struggling_subjects)): ?>
                            <div class="subject-empty-col">No subjects flagged as struggling. 🎉</div>
                        <?php else: ?>
                            <?php foreach ($struggling_subjects as $subj): ?>
                            <div class="subject-mini-card struggling">
                                <div>
                                    <div class="subject-name"><?php echo htmlspecialchars($subj['subject_name']); ?></div>
                                    <div class="subject-meta">
                                        Latest: <?php echo htmlspecialchars($subj['latest_term']); ?> (<?php echo $subj['latest_score']; ?>%)
                                        <?php if ($subj['trend'] === 'up'): ?><i class="fas fa-arrow-up trend-icon up" title="Improving"></i>
                                        <?php elseif ($subj['trend'] === 'down'): ?><i class="fas fa-arrow-down trend-icon down" title="Declining"></i>
                                        <?php else: ?><i class="fas fa-minus trend-icon flat" title="Stable"></i>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <span class="subject-score-badge"><?php echo $subj['average']; ?>%</span>
                            </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Full breakdown, weakest first -->
                <div class="mastery-column-header" style="color:var(--dark);"><i class="fas fa-list"></i> All Subjects (weakest to strongest)</div>
                <div class="subject-breakdown-table">
                    <?php foreach ($subject_summary_all as $subj): ?>
                    <div class="subject-breakdown-row">
                        <div class="name-col">
                            <?php echo htmlspecialchars($subj['subject_name']); ?>
                            <small><?php echo htmlspecialchars($subj['subject_code']); ?> &middot; <?php echo $subj['records_count']; ?> record<?php echo $subj['records_count'] == 1 ? '' : 's'; ?></small>
                        </div>
                        <div class="subject-progress-track">
                            <div class="subject-progress-fill <?php echo $subj['category']; ?>" style="width: <?php echo min($subj['average'], 100); ?>%;"></div>
                        </div>
                        <div class="score-col"><?php echo $subj['average']; ?>%</div>
                        <span class="status-pill <?php echo $subj['category']; ?>"><?php echo ucfirst($subj['category']); ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- ============================================ -->
        <!-- DIAGNOSIS FORM -->
        <!-- ============================================ -->
        <form id="diagnosisForm" method="POST" action="">
            <input type="hidden" name="student_id" value="<?php echo htmlspecialchars($selected_student); ?>">
            <input type="hidden" name="class_id" value="<?php echo htmlspecialchars($selected_class); ?>">
            <input type="hidden" name="term_id" value="<?php echo htmlspecialchars($current_term_id ?? ''); ?>">
            <input type="hidden" name="academic_year_id" id="academicYearId" value="<?php echo (int)$year_id; ?>">
            <input type="hidden" name="diagnosis_id" value="<?php echo htmlspecialchars($existing_diagnosis['id'] ?? ''); ?>">

            <div class="card-custom">
                <div class="card-title">
                    <i class="fas fa-clipboard-list"></i>
                    Core Diagnosis Criteria
                    <span class="badge bg-secondary ms-2"><?php echo $total_criteria; ?> criteria</span>
                    <span class="badge bg-info ms-2"><?php echo htmlspecialchars($academic_year ?? ''); ?> - <?php echo htmlspecialchars(getTermDisplayName($current_term_name ?? '')); ?></span>
                    <span class="badge bg-success ms-2">Score: 0 - 20</span>
                </div>

                <?php if (empty($criteria_by_category)): ?>
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        No active diagnosis criteria found. Run <strong>essential_criteria.sql</strong> to load the core set.
                    </div>
                <?php else: ?>
                    <?php foreach ($criteria_by_category as $category => $criteria_items): ?>
                    <div class="criteria-group">
                        <div class="criteria-group-header">
                            <span><?php echo htmlspecialchars($category); ?></span>
                            <span class="badge-count"><?php echo count($criteria_items); ?> criteria</span>
                        </div>
                        <?php foreach ($criteria_items as $criterion): ?>
                        <div class="criteria-item">
                            <div class="label">
                                <?php echo htmlspecialchars($criterion['name']); ?>
                                <small><?php echo htmlspecialchars($criterion['description'] ?? ''); ?></small>
                            </div>
                            <div class="range">0 - <?php echo htmlspecialchars($criterion['max_score'] ?? 20); ?></div>
                            <input type="number"
                                   class="score-input"
                                   name="scores[<?php echo htmlspecialchars($criterion['id']); ?>]"
                                   min="0"
                                   max="<?php echo htmlspecialchars($criterion['max_score'] ?? 20); ?>"
                                   value="<?php echo htmlspecialchars($existing_scores[$criterion['id']]['score'] ?? ''); ?>"
                                   step="0.5"
                                   data-weight="<?php echo htmlspecialchars($criterion['weight']); ?>"
                                   data-max="<?php echo htmlspecialchars($criterion['max_score'] ?? 20); ?>"
                                   oninput="updateOverallStatus()"
                                   placeholder="Score"
                                   autocomplete="off">
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>

                <!-- Overall Status -->
                <?php
                $init_status = $existing_diagnosis['overall_status'] ?? '';
                $init_icons = ['excellent' => '🌟', 'good' => '👍', 'average' => '📝', 'needs_improvement' => '⚠️', 'critical' => '🔴'];
                ?>
                <div class="overall-status <?php echo htmlspecialchars($init_status); ?>" id="overallStatus">
                    <span class="icon" id="statusIcon"><?php echo $existing_diagnosis ? ($init_icons[$init_status] ?? '📊') : '📊'; ?></span>
                    <div class="label" id="statusLabel"><?php echo $existing_diagnosis ? strtoupper(str_replace('_', ' ', $init_status)) : 'Not Diagnosed'; ?></div>
                    <div class="score" id="statusScore"><?php echo $existing_diagnosis ? "Score: " . round($existing_diagnosis['overall_score'], 1) . '/20' : 'Enter scores (0-20) to see overall performance'; ?></div>
                    <div class="grade" id="statusGrade"><?php echo ($existing_diagnosis && $existing_diagnosis['academic_grade']) ? 'Grade: ' . htmlspecialchars($existing_diagnosis['academic_grade']) : ''; ?></div>
                </div>

                <!-- Comments & Recommendations -->
                <div class="row mt-4 g-3">
                    <div class="col-md-6">
                        <label class="fw-bold mb-2"><i class="fas fa-comment me-2"></i>Teacher Comments</label>
                        <textarea name="comments" class="form-control" rows="3" style="border-radius:12px;border:2px solid var(--border);padding:12px 16px;"><?php echo htmlspecialchars($existing_diagnosis['comments'] ?? ''); ?></textarea>
                    </div>
                    <div class="col-md-6">
                        <label class="fw-bold mb-2"><i class="fas fa-lightbulb me-2"></i>Recommendations</label>
                        <textarea name="recommendations" class="form-control" rows="3" style="border-radius:12px;border:2px solid var(--border);padding:12px 16px;" placeholder="Suggest areas for improvement and next steps..."><?php echo htmlspecialchars($existing_diagnosis['recommendations'] ?? ''); ?></textarea>
                    </div>
                </div>

                <!-- ============================================ -->
                <!-- SUBJECT MASTERY TAGGING -->
                <!-- ============================================ -->
                <div class="mt-4">
                    <div class="card-title" style="margin-bottom:6px;">
                        <i class="fas fa-tags"></i> Subject Mastery
                        <span class="badge bg-secondary ms-2">Part of this diagnosis</span>
                    </div>
                    <p class="text-muted small mb-3 no-print">
                        Tag the specific subjects this student clearly excels in, and the ones they're struggling with.
                        Saved with this diagnosis and included in Print Report / history.
                    </p>

                    <!-- Rebuilt by JS before printing so unsaved ticks still appear on paper -->
                    <div class="print-only" id="printTagSummary"></div>

                    <?php if (empty($all_subjects)): ?>
                        <div class="alert alert-warning mb-0">
                            <i class="fas fa-exclamation-triangle me-2"></i>
                            No active subjects found to tag. Please add subjects in the Subjects module first.
                        </div>
                    <?php else: ?>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="fw-bold mb-2 text-success no-print"><i class="fas fa-star me-1"></i> Excels In (Mastery)</label>
                            <div class="subject-tag-grid" id="masteryTagGrid">
                                <?php foreach ($all_subjects as $subj): $checked = in_array((int)$subj['id'], $existing_mastery_tags); ?>
                                <label class="subject-tag-chip mastery-chip<?php echo $checked ? ' active' : ''; ?>">
                                    <input type="checkbox"
                                           name="mastery_subjects[]"
                                           value="<?php echo (int)$subj['id']; ?>"
                                           data-subject-name="<?php echo htmlspecialchars($subj['subject_name']); ?>"
                                           <?php echo $checked ? 'checked' : ''; ?>
                                           onchange="handleSubjectTagChange(this, 'mastery')">
                                    <span><?php echo htmlspecialchars($subj['subject_name']); ?></span>
                                </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="fw-bold mb-2 text-danger no-print"><i class="fas fa-triangle-exclamation me-1"></i> Struggling With</label>
                            <div class="subject-tag-grid" id="strugglingTagGrid">
                                <?php foreach ($all_subjects as $subj): $checked = in_array((int)$subj['id'], $existing_struggling_tags); ?>
                                <label class="subject-tag-chip struggling-chip<?php echo $checked ? ' active' : ''; ?>">
                                    <input type="checkbox"
                                           name="struggling_subjects[]"
                                           value="<?php echo (int)$subj['id']; ?>"
                                           data-subject-name="<?php echo htmlspecialchars($subj['subject_name']); ?>"
                                           <?php echo $checked ? 'checked' : ''; ?>
                                           onchange="handleSubjectTagChange(this, 'struggling')">
                                    <span><?php echo htmlspecialchars($subj['subject_name']); ?></span>
                                </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Actions -->
                <div class="d-flex gap-2 flex-wrap mt-4 no-print">
                    <button type="submit" class="btn btn-save" id="saveBtn">
                        <i class="fas fa-save me-2"></i> Save Diagnosis
                    </button>
                    <button type="button" class="btn btn-print" onclick="printReport()">
                        <i class="fas fa-print me-2"></i> Print Report
                    </button>
                    <button type="button" class="btn btn-reset" onclick="resetForm()">
                        <i class="fas fa-undo me-2"></i> Reset Form
                    </button>
                    <?php if (!empty($history)): ?>
                        <button type="button" class="btn btn-info text-white" onclick="toggleHistory()">
                            <i class="fas fa-history me-2"></i> View History
                        </button>
                    <?php endif; ?>
                </div>
            </div>
        </form>

        <!-- ============================================ -->
        <!-- DIAGNOSIS HISTORY -->
        <!-- ============================================ -->
        <?php if (!empty($history)): ?>
        <div class="card-custom" id="historySection" style="display: <?php echo $show_history ? 'block' : 'none'; ?>;">
            <div class="card-title">
                <i class="fas fa-history"></i> Diagnosis History
                <span class="badge bg-secondary ms-2"><?php echo count($history); ?> records</span>
            </div>
            <div id="historyContent">
                <?php foreach ($history as $record): ?>
                <div class="history-item">
                    <div class="d-flex justify-content-between align-items-center flex-wrap">
                        <div>
                            <strong><?php echo htmlspecialchars($record['term_name'] ?? 'N/A'); ?></strong>
                            <span class="text-muted">(<?php echo htmlspecialchars($record['year_label'] ?? 'N/A'); ?>)</span>
                            <span class="ms-3 text-muted small"><?php echo $record['criteria_count'] ?? 0; ?> criteria</span>
                            <?php if ($record['academic_grade']): ?>
                                <span class="ms-2 badge bg-secondary">Grade: <?php echo htmlspecialchars($record['academic_grade']); ?></span>
                            <?php endif; ?>
                            <span class="ms-2 badge bg-info">Score: <?php echo round($record['overall_score'], 1); ?>/20</span>
                        </div>
                        <div>
                            <span class="badge bg-<?php
                                echo $record['overall_status'] == 'excellent' ? 'success' :
                                    ($record['overall_status'] == 'good' ? 'primary' :
                                    ($record['overall_status'] == 'average' ? 'warning' : 'danger'));
                            ?>">
                                <?php echo ucfirst(str_replace('_', ' ', $record['overall_status'] ?? 'N/A')); ?>
                            </span>
                            <span class="ms-2 small text-muted"><?php echo date('M d, Y', strtotime($record['created_at'] ?? 'now')); ?></span>
                        </div>
                    </div>
                    <?php if ($record['comments']): ?>
                        <div class="mt-1 small text-muted"><i class="fas fa-quote-left me-1"></i><?php echo htmlspecialchars(substr($record['comments'], 0, 100)) . (strlen($record['comments']) > 100 ? '...' : ''); ?></div>
                    <?php endif; ?>
                    <?php if ($record['avg_score']): ?>
                        <div class="mt-1">
                            <div class="progress" style="height:4px;">
                                <div class="progress-bar bg-<?php
                                    $pct = ($record['avg_score'] / 20) * 100;
                                    echo $pct >= 80 ? 'success' : ($pct >= 60 ? 'primary' : ($pct >= 40 ? 'warning' : 'danger'));
                                ?>" style="width: <?php echo min(($record['avg_score'] / 20) * 100, 100); ?>%;"></div>
                            </div>
                            <small class="text-muted">Avg Score: <?php echo $record['avg_score']; ?>/20</small>
                        </div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <?php else: ?>
        <!-- ============================================ -->
        <!-- EMPTY STATE -->
        <!-- ============================================ -->
        <div class="card-custom empty-state">
            <i class="fas fa-user-graduate fa-4x text-muted mb-3"></i>
            <h4>Select a Student to Begin</h4>
            <p class="text-muted">Choose a class and student from the dropdown above to start the academic diagnosis process.</p>
            <?php if (empty($classes)): ?>
                <div class="alert alert-warning mt-3">
                    <i class="fas fa-exclamation-triangle me-2"></i>
                    No classes assigned to you for the current academic year (<?php echo htmlspecialchars($academic_year ?? 'Not Set'); ?>).
                    Please contact the administrator.
                </div>
            <?php endif; ?>
            <?php if ($academic_year): ?>
                <div class="alert alert-info mt-2">
                    <i class="fas fa-info-circle me-2"></i>
                    Active Academic Year: <strong><?php echo htmlspecialchars($academic_year); ?></strong>
                    <?php if ($current_term_name): ?>
                    - Term: <strong><?php echo htmlspecialchars(getTermDisplayName($current_term_name)); ?></strong>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>

    <!-- ============================================ -->
    <!-- JAVASCRIPT -->
    <!-- ============================================ -->
    <script>
    // ============================================
    // STATE
    // ============================================
    let saveTimeout = null;
    let isSaving = false;
    const form = document.getElementById('diagnosisForm');
    const MAX_SCORE = 20;

    // Same bands the PHP save routine uses - generated from one source
    const GRADE_BANDS = <?php echo json_encode($GRADE_BANDS); ?>;

    function bandFor(pct) {
        for (var i = 0; i < GRADE_BANDS.length; i++) {
            if (pct >= GRADE_BANDS[i].min) return GRADE_BANDS[i];
        }
        return GRADE_BANDS[GRADE_BANDS.length - 1];
    }

    // ============================================
    // UPDATE OVERALL STATUS
    // ============================================
    function updateOverallStatus() {
        const inputs = document.querySelectorAll('.score-input');
        let totalScore = 0, totalWeight = 0, filled = 0, maxPossibleScore = 0;
        const total = inputs.length;

        inputs.forEach(function (input) {
            const val = parseFloat(input.value);
            input.classList.remove('excellent', 'good', 'average', 'poor');

            if (!isNaN(val) && val >= 0) {
                const weight = parseFloat(input.dataset.weight) || 1;
                const maxScore = parseFloat(input.dataset.max) || MAX_SCORE;
                totalScore += val * weight;
                totalWeight += weight;
                maxPossibleScore += maxScore * weight;
                filled++;

                const pct = (val / maxScore) * 100;
                if (pct >= 80) input.classList.add('excellent');
                else if (pct >= 60) input.classList.add('good');
                else if (pct >= 40) input.classList.add('average');
                else input.classList.add('poor');
            }
        });

        const statusDiv = document.getElementById('overallStatus');
        const icon = document.getElementById('statusIcon');
        const label = document.getElementById('statusLabel');
        const score = document.getElementById('statusScore');
        const grade = document.getElementById('statusGrade');

        if (!statusDiv) return;

        if (filled === 0) {
            statusDiv.className = 'overall-status';
            icon.textContent = '📊';
            label.textContent = 'Not Diagnosed';
            score.textContent = 'Enter scores (0-20) to see overall performance';
            grade.textContent = '';
            return;
        }

        const avgScore = totalWeight > 0 ? (totalScore / totalWeight) : 0;
        const percentage = maxPossibleScore > 0 ? (totalScore / maxPossibleScore) * 100 : 0;
        const band = bandFor(percentage);

        statusDiv.className = 'overall-status ' + band.status;
        icon.textContent = band.icon;
        label.textContent = band.label;
        score.textContent = 'Score: ' + avgScore.toFixed(1) + '/20 (' + percentage.toFixed(1) + '%) - ' + filled + '/' + total + ' criteria';
        grade.textContent = 'Grade: ' + band.grade;
    }

    // ============================================
    // RESET FORM
    // ============================================
    function resetForm() {
        if (!confirm('Are you sure you want to reset all scores?')) return;

        document.querySelectorAll('.score-input').forEach(function (input) {
            input.value = '';
            input.classList.remove('excellent', 'good', 'average', 'poor');
        });
        const comments = document.querySelector('textarea[name="comments"]');
        const recommendations = document.querySelector('textarea[name="recommendations"]');
        if (comments) comments.value = '';
        if (recommendations) recommendations.value = '';

        document.querySelectorAll('.subject-tag-chip input[type="checkbox"]').forEach(function (cb) {
            cb.checked = false;
            cb.closest('.subject-tag-chip').classList.remove('active');
        });

        updateOverallStatus();
    }

    // ============================================
    // SUBJECT MASTERY TAGGING
    // A subject can only be one or the other, so ticking it in one grid
    // unticks it in the opposite grid.
    // ============================================
    function handleSubjectTagChange(checkbox, type) {
        const subjectId = checkbox.value;
        const chip = checkbox.closest('.subject-tag-chip');

        if (checkbox.checked) {
            chip.classList.add('active');
            const oppositeGridId = type === 'mastery' ? 'strugglingTagGrid' : 'masteryTagGrid';
            const oppositeGrid = document.getElementById(oppositeGridId);
            if (oppositeGrid) {
                const oppositeInput = oppositeGrid.querySelector('input[value="' + subjectId + '"]');
                if (oppositeInput && oppositeInput.checked) {
                    oppositeInput.checked = false;
                    oppositeInput.closest('.subject-tag-chip').classList.remove('active');
                }
            }
        } else {
            chip.classList.remove('active');
        }
    }

    // ============================================
    // PRINT
    // ============================================
    function collectTagNames(name) {
        return Array.prototype.map.call(
            document.querySelectorAll('input[name="' + name + '"]:checked'),
            function (cb) { return cb.dataset.subjectName || cb.value; }
        );
    }

    function printReport() {
        const target = document.getElementById('printTagSummary');
        if (target) {
            const mastery = collectTagNames('mastery_subjects[]');
            const struggling = collectTagNames('struggling_subjects[]');
            target.innerHTML =
                '<p style="margin:4px 0;"><strong>Excels in:</strong> ' + (mastery.length ? mastery.join(', ') : 'None recorded') + '</p>' +
                '<p style="margin:4px 0;"><strong>Struggling with:</strong> ' + (struggling.length ? struggling.join(', ') : 'None recorded') + '</p>';
        }
        window.print();
    }

    // ============================================
    // TOGGLE HISTORY
    // ============================================
    function toggleHistory() {
        const section = document.getElementById('historySection');
        if (section) {
            const isHidden = section.style.display === 'none';
            section.style.display = isHidden ? 'block' : 'none';
            if (isHidden) {
                section.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        }
    }

    // ============================================
    // TOAST NOTIFICATIONS
    // ============================================
    function showToast(message, type) {
        type = type || 'success';
        const container = document.getElementById('toastContainer');
        if (!container) return;

        const toast = document.createElement('div');
        toast.className = 'toast-custom ' + type;
        toast.textContent = message;
        container.appendChild(toast);

        setTimeout(function () {
            toast.style.opacity = '0';
            toast.style.transition = 'opacity 0.3s';
            setTimeout(function () {
                if (toast.parentNode) toast.remove();
            }, 300);
        }, 4000);
    }

    // ============================================
    // SAVE DIAGNOSIS
    // ============================================
    function saveDiagnosis(autoSave) {
        autoSave = autoSave || false;
        if (isSaving) return;
        if (!form) return;

        const formData = new FormData(form);
        if (autoSave) {
            formData.append('auto_save', '1');
        }

        isSaving = true;
        const btn = document.getElementById('saveBtn');
        const originalText = btn ? btn.innerHTML : '';
        const overlay = document.getElementById('loadingOverlay');

        if (!autoSave && btn) {
            btn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Saving...';
            btn.disabled = true;
            if (overlay) overlay.classList.add('active');
        }

        fetch(window.location.href, { method: 'POST', body: formData })
        .then(function (response) { return response.json(); })
        .then(function (data) {
            if (data.success) {
                if (!autoSave) {
                    showToast('✅ ' + data.message, 'success');
                    if (data.tags_saved === false) {
                        showToast('⚠️ Subject tags could not be saved - run the tags table migration.', 'info');
                    }
                }

                var diagInput = document.querySelector('input[name="diagnosis_id"]');
                if (diagInput && data.diagnosis_id) {
                    diagInput.value = data.diagnosis_id;
                }

                // Reflect exactly what the server stored
                var band = bandFor(data.overall_percentage || 0);
                var statusDiv = document.getElementById('overallStatus');
                if (statusDiv) statusDiv.className = 'overall-status ' + data.overall_status;
                var iconEl = document.getElementById('statusIcon');
                if (iconEl) iconEl.textContent = band.icon;
                var labelEl = document.getElementById('statusLabel');
                if (labelEl) labelEl.textContent = (data.overall_status || '').replace('_', ' ').toUpperCase();
                var scoreEl = document.getElementById('statusScore');
                if (scoreEl) {
                    scoreEl.textContent = 'Score: ' + data.overall_score + '/20 (' + (data.overall_percentage || 0).toFixed(1) + '%) - '
                        + (data.filled_count || 0) + '/' + (data.total_criteria || 0) + ' criteria';
                }
                var gradeEl = document.getElementById('statusGrade');
                if (gradeEl && data.academic_grade) gradeEl.textContent = 'Grade: ' + data.academic_grade;
            } else {
                if (!autoSave) {
                    showToast('❌ ' + data.message, 'error');
                }
            }
        })
        .catch(function (err) {
            if (!autoSave) {
                showToast('❌ Error saving diagnosis. Please try again.', 'error');
            }
            console.error('Save error:', err);
        })
        .finally(function () {
            isSaving = false;
            if (!autoSave && btn) {
                btn.innerHTML = originalText;
                btn.disabled = false;
                if (overlay) overlay.classList.remove('active');
            }
        });
    }

    // ============================================
    // AUTO-SAVE (debounced)
    // The old check used '.score-input[value]', which reads the HTML
    // attribute, not what the teacher typed - so a fresh diagnosis never
    // auto-saved. It now checks live input values.
    // ============================================
    function hasSomethingToSave() {
        var scored = Array.prototype.some.call(
            document.querySelectorAll('.score-input'),
            function (input) { return input.value !== ''; }
        );
        var tagged = !!document.querySelector('input[name="mastery_subjects[]"]:checked, input[name="struggling_subjects[]"]:checked');
        return scored || tagged;
    }

    function handleFormActivity(e) {
        if (e.target.type === 'button' || e.target.type === 'submit') return;

        clearTimeout(saveTimeout);
        saveTimeout = setTimeout(function () {
            if (hasSomethingToSave()) {
                saveDiagnosis(true);
            }
        }, 3000);
    }

    if (form) {
        form.addEventListener('input', handleFormActivity);
        form.addEventListener('change', handleFormActivity);
        form.addEventListener('submit', function (e) {
            e.preventDefault();
            saveDiagnosis(false);
        });
    }

    // ============================================
    // KEYBOARD SHORTCUTS
    // Ctrl+S saves. Escape no longer resets - it was wiping forms by accident.
    // ============================================
    document.addEventListener('keydown', function (e) {
        if ((e.ctrlKey || e.metaKey) && e.key === 's') {
            e.preventDefault();
            if (form) saveDiagnosis(false);
        }
    });

    // ============================================
    // INIT
    // ============================================
    document.addEventListener('DOMContentLoaded', function () {
        updateOverallStatus();

        var urlParams = new URLSearchParams(window.location.search);
        if (urlParams.get('history') === '1') {
            var section = document.getElementById('historySection');
            if (section) section.style.display = 'block';
        }
    });
    </script>
</body>
</html>