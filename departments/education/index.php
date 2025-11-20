<?php
require_once '../../config.php';
requireRole('admin');

// Ensure this is Education department admin
if ($_SESSION['department'] !== 'Education') {
    header('Location: ../../dashboard.php');
    exit();
}

// Redirect to main department dashboard
header('Location: ../../department_dashboard.php');
exit();
?>
