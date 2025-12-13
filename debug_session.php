<?php
require_once 'config.php';

header('Content-Type: text/plain; charset=utf-8');

echo "=== Session Debug (Render) ===\n";
echo "Session ID: " . session_id() . "\n";
echo "Session status: " . session_status() . "\n";
echo "Is logged in: " . (isLoggedIn() ? 'YES' : 'NO') . "\n";
if (isLoggedIn()) {
    echo "User ID: " . ($_SESSION['user_id'] ?? 'none') . "\n";
    echo "Role: " . ($_SESSION['role'] ?? 'none') . "\n";
    echo "Full name: " . ($_SESSION['full_name'] ?? 'none') . "\n";
}
echo "\n=== Session data ===\n";
print_r($_SESSION);
echo "\n=== Cookies ===\n";
print_r($_COOKIE);
echo "\n=== Quick eval target (if any) ===\n";
echo (empty($_SESSION['quick_eval_target']) ? 'NOT SET' : json_encode($_SESSION['quick_eval_target'])) . "\n";
?>
