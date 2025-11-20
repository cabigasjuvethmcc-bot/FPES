<?php
require_once __DIR__ . '/../config.php';
requireRole('admin');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: manage_password_resets.php');
    exit();
}

$token = $_POST['csrf_token'] ?? '';
if (!validateCSRFToken($token)) {
    header('Location: manage_password_resets.php');
    exit();
}

$request_id = (int)($_POST['request_id'] ?? 0);
if (!$request_id) {
    header('Location: manage_password_resets.php');
    exit();
}

try {
    $stmt = $pdo->prepare('UPDATE password_reset_requests SET status = "Resolved" WHERE id = ?');
    $stmt->execute([$request_id]);
} catch (PDOException $e) {
    // ignore, redirect back
}

header('Location: manage_password_resets.php');
exit();
