<?php
require_once '../config.php';

// Check if user is logged in and has faculty role
if (!isLoggedIn()) {
    header('Location: ../index.php');
    exit();
}

if (!hasRole('faculty')) {
    header('Location: ../dashboard.php');
    exit();
}

// If everything is good, redirect to faculty dashboard
header('Location: faculty.php');
exit();
?>
