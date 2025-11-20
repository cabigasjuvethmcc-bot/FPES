<?php
require_once '../config.php';

// Check if user is logged in and has student role
if (!isLoggedIn()) {
    header('Location: ../index.php');
    exit();
}

if (!hasRole('student')) {
    header('Location: ../dashboard.php');
    exit();
}

// If everything is good, redirect to student dashboard
header('Location: student.php');
exit();
?>
