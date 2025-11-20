<?php
require_once '../config.php';

// Check if user is logged in and has dean role
if (!isLoggedIn()) {
    header('Location: ../index.php');
    exit();
}

if (!hasRole('dean')) {
    header('Location: ../dashboard.php');
    exit();
}

// If everything is good, redirect to dean dashboard
header('Location: dean.php');
exit();
?>
