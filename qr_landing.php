<?php
require_once 'config.php';

$facultyId = isset($_GET['faculty_id']) ? (int)$_GET['faculty_id'] : 0;
$subjectCode = isset($_GET['subject_code']) ? trim($_GET['subject_code']) : '';
$subjectName = isset($_GET['subject_name']) ? trim($_GET['subject_name']) : '';

if ($facultyId <= 0 || ($subjectCode === '' && $subjectName === '')) {
    http_response_code(400);
    echo 'Invalid QR link. Required parameters are missing.';
    exit;
}

$_SESSION['quick_eval_target'] = [
    'faculty_id'   => $facultyId,
    'subject_code' => $subjectCode,
    'subject_name' => $subjectName,
];

if (isLoggedIn()) {
    if (hasRole('student')) {
        header('Location: student/quick_evaluate.php');
        exit;
    }
}
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Start Evaluation</title>
    <link rel="icon" href="img/loginlogo.png?v=2" type="image/png">
    <link rel="stylesheet" href="styles.css">
</head>
<body>
    <div class="container" id="login-container">
        <h1 class="app-title">Faculty Performance Evaluation System</h1>
        <div class="login-form" role="region" aria-labelledby="qr-landing-title">
            <h2 id="qr-landing-title" class="login-title">Start Evaluation</h2>
            <hr class="divider" aria-hidden="true" />
            <p style="margin-bottom:1rem; text-align:center;">To continue with this evaluation, please login or sign up as a student.</p>
            <div style="display:flex; flex-direction:column; gap:0.75rem; width:100%; max-width:320px; margin:0 auto;">
                <a href="index.php" class="btn-primary" style="text-align:center;">Login</a>
                <a href="student_signup.php" class="btn-secondary" style="text-align:center;">Sign up as Student</a>
            </div>
        </div>
    </div>
</body>
</html>
