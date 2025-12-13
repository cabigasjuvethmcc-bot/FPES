<?php
require_once '../config.php';

// Debug: log session state on arrival
file_put_contents(__DIR__ . '/../signup_debug.log', "[" . date('Y-m-d H:i:s') . "] quick_evaluate.php arrived - session data: " . json_encode($_SESSION) . "\n", FILE_APPEND);

// Quick evaluation entry point for QR codes
// Expected query params: faculty_id, subject_code (optional), subject_name (fallback)

// First try to get context from query string
$facultyId = isset($_GET['faculty_id']) ? (int)$_GET['faculty_id'] : 0;
$subjectCode = isset($_GET['subject_code']) ? trim($_GET['subject_code']) : '';
$subjectName = isset($_GET['subject_name']) ? trim($_GET['subject_name']) : '';

// If missing, fall back to session quick_eval_target (set by qr_landing.php)
if (($facultyId <= 0 || ($subjectCode === '' && $subjectName === '')) && !empty($_SESSION['quick_eval_target'])) {
    $t = $_SESSION['quick_eval_target'];
    $facultyId = isset($t['faculty_id']) ? (int)$t['faculty_id'] : 0;
    $subjectCode = isset($t['subject_code']) ? trim((string)$t['subject_code']) : '';
    $subjectName = isset($t['subject_name']) ? trim((string)$t['subject_name']) : '';
}

if ($facultyId <= 0 || ($subjectCode === '' && $subjectName === '')) {
    http_response_code(400);
    echo 'Invalid QR link. Required parameters are missing.';
    exit;
}

// If not logged in, remember target and send to login
if (!isLoggedIn()) {
    $_SESSION['quick_eval_target'] = [
        'faculty_id'   => $facultyId,
        'subject_code' => $subjectCode,
        'subject_name' => $subjectName,
    ];
    $qs = http_build_query([
        'faculty_id'   => $facultyId,
        'subject_code' => $subjectCode,
        'subject_name' => $subjectName,
    ]);
    $signupUrl = '../student_signup.php' . ($qs !== '' ? ('?' . $qs) : '');
    header('Location: ' . $signupUrl);
    exit;
}

// Must be a student to use this entry point
if (!hasRole('student')) {
    http_response_code(403);
    echo 'Only students can access this evaluation link.';
    exit;
}

// Check if evaluation period is open
list($evalOpen, $evalState, $evalReason, $evalSchedule) = isEvaluationOpenForStudents($pdo);
if (!$evalOpen) {
    echo 'Evaluations are currently closed. Please wait for the schedule to open.';
    exit;
}

$activePeriod = getActiveSemesterYear($pdo);

// Validate that this student is actually enrolled under this faculty and subject.
// For newly self-registered students, we may not yet have enrollment mappings, so
// if no mapping is found but the faculty exists, we fall back to using the QR
// subject data instead of blocking the evaluation.
try {
    $sql = "SELECT 
                sfs.subject_code,
                sfs.subject_name,
                fu.id AS faculty_user_id,
                f.id AS faculty_id
            FROM student_faculty_subjects sfs
            JOIN users fu ON fu.id = sfs.faculty_user_id AND fu.role = 'faculty'
            JOIN faculty f ON f.user_id = fu.id
            WHERE sfs.student_user_id = ?
              AND f.id = ?
              AND (
                    (sfs.subject_code IS NOT NULL AND sfs.subject_code <> '' AND sfs.subject_code = ?) 
                 OR (sfs.subject_code IS NULL OR sfs.subject_code = '') AND sfs.subject_name = ?
              )
            LIMIT 1";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        $_SESSION['user_id'],
        $facultyId,
        $subjectCode,
        $subjectName,
    ]);
    $row = $stmt->fetch();
} catch (PDOException $e) {
    $row = false;
}

if (!$row) {
    // No enrollment mapping found. As long as the faculty exists, allow evaluation
    // using the QR-provided subject details.
    try {
        $check = $pdo->prepare("SELECT f.id, f.user_id AS faculty_user_id FROM faculty f WHERE f.id = ? LIMIT 1");
        $check->execute([$facultyId]);
        $fac = $check->fetch();
    } catch (PDOException $e) {
        $fac = false;
    }

    if (!$fac) {
        http_response_code(404);
        echo 'The faculty linked to this evaluation could not be found.';
        exit;
    }

    $row = [
        'subject_code'   => $subjectCode,
        'subject_name'   => ($subjectName !== '' ? $subjectName : $subjectCode),
        'faculty_user_id'=> (int)$fac['faculty_user_id'],
        'faculty_id'     => (int)$fac['id'],
    ];
}

// Store quick evaluation context in session for student dashboard
// Before proceeding, check if student already evaluated this faculty+subject for the active period
$subjectForEval = (string)$row['subject_name'];
$semester = $activePeriod['semester'] ?? '';
$academicYear = $activePeriod['academic_year'] ?? '';

if ($semester !== '' && $academicYear !== '') {
    try {
        $dupStmt = $pdo->prepare("SELECT id FROM evaluations WHERE student_id = ? AND faculty_id = ? AND subject = ? AND semester = ? AND academic_year = ? LIMIT 1");
        $dupStmt->execute([
            $_SESSION['student_id'] ?? null,
            (int)$row['faculty_id'],
            $subjectForEval,
            $semester,
            $academicYear,
        ]);
        if ($dupStmt->fetch()) {
            // Set flash message in session for student dashboard and send to history section
            $_SESSION['flash_message'] = 'You have already evaluated this faculty for this subject in the current semester.';
            $_SESSION['flash_type'] = 'error';
            $_SESSION['flash_section'] = 'history';
            unset($_SESSION['quick_eval_target']);
            header('Location: student.php');
            exit;
        }
    } catch (PDOException $e) {
        // On error, fall back to normal flow below
    }
}

// Mark that this evaluation originated from a QR quick-eval flow so that
// submit_evaluation.php can relax strict enrollment checks and optionally
// create a mapping after successful submission.
$_SESSION['quick_eval_source'] = 'qr';
$_SESSION['quick_eval_enrollment'] = [
    'faculty_user_id' => (int)$row['faculty_user_id'],
    'subject_code'    => (string)$row['subject_code'],
    'subject_name'    => (string)$row['subject_name'],
];

$_SESSION['quick_eval'] = [
    'faculty_id'   => (int)$row['faculty_id'],
    'subject_code' => (string)$row['subject_code'],
    'subject_name' => $subjectForEval,
    'semester'     => $semester,
    'academic_year'=> $academicYear,
];

// Clear original target (we now have a validated quick_eval)
unset($_SESSION['quick_eval_target']);

// Redirect to evaluation-only page for QR flow
header('Location: evaluation_only.php');
exit;
