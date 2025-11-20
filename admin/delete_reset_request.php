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
    $stmt = $pdo->prepare('DELETE FROM password_reset_requests WHERE id = ?');
    $stmt->execute([$request_id]);
} catch (PDOException $e) {
    // ignore and return to list
}

header('Location: manage_password_resets.php');
exit();
