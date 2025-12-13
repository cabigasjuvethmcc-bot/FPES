<?php
require_once 'config.php';

// Simple file-based logger for debugging signup flow
function logmsg($msg) {
    $ts = date('Y-m-d H:i:s');
    file_put_contents(__DIR__ . '/signup_debug.log', "[$ts] $msg\n", FILE_APPEND);
}

logmsg("=== SIGNUP DEBUG START ===");
logmsg("REQUEST_METHOD: " . $_SERVER['REQUEST_METHOD']);
logmsg("POST data: " . json_encode($_POST));
logmsg("GET data: " . json_encode($_GET));
logmsg("Session before: " . json_encode($_SESSION));
logmsg("isLoggedIn before: " . (isLoggedIn() ? 'YES' : 'NO'));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = sanitizeInput($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    logmsg("Attempting to login newly created user: $email");
    
    // Try to locate the user by username (email) for QR signups
    $stmt = $pdo->prepare("SELECT u.*, s.id as student_id FROM users u LEFT JOIN students s ON u.id = s.user_id WHERE u.username = ? AND u.role = 'student'");
    $stmt->execute([$email]);
    $user = $stmt->fetch();
    if ($user && password_verify($password, $user['password'])) {
        logmsg("User found and password matches. Setting session.");
        $_SESSION['user_id'] = (int)$user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['role'] = $user['role'];
        $_SESSION['full_name'] = $user['full_name'];
        $_SESSION['department'] = $user['department'];
        $_SESSION['student_id'] = isset($user['student_id']) ? (int)$user['student_id'] : 0;
        logmsg("Session after login: " . json_encode($_SESSION));
        logmsg("isLoggedIn after: " . (isLoggedIn() ? 'YES' : 'NO'));
    } else {
        logmsg("User not found or password mismatch.");
    }
}

logmsg("=== SIGNUP DEBUG END ===");
echo "Debug logged. Check signup_debug.log in the app root.";
?>
