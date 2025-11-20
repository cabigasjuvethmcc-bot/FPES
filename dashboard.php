<?php
require_once 'config.php';
requireLogin();

// If user is flagged to change password, force redirect
if (!empty($_SESSION['must_change_password'])) {
    header('Location: force_change_password.php');
    exit();
}

// Redirect to appropriate dashboard based on role
switch ($_SESSION['role']) {
    case 'student':
        header('Location: student/student.php');
        break;
    case 'faculty':
        header('Location: faculty/faculty.php');
        break;
    case 'dean':
        header('Location: dean/dean.php');
        break;
    case 'admin':
        // Check if this is a department admin
        $department = $_SESSION['department'] ?? '';
        if (in_array($department, ['Technology', 'Education', 'Business'])) {
            header('Location: department_dashboard.php');
        } else {
            header('Location: admin/admin.php');
        }
        break;
    default:
        session_destroy();
        header('Location: index.php');
        break;
}
exit();
?>
