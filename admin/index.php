<?php
require_once '../config.php';

// Check if user is logged in and has admin role
if (!isLoggedIn()) {
    header('Location: ../index.php');
    exit();
}

if (!hasRole('admin')) {
    header('Location: ../dashboard.php');
    exit();
}

// If everything is good, redirect to admin dashboard
header('Location: admin.php');
exit();
?>
