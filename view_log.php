<?php
$logFile = __DIR__ . '/signup_debug.log';
if (file_exists($logFile)) {
    echo nl2br(htmlspecialchars(file_get_contents($logFile)));
} else {
    echo "Log file not found.";
}
?>
