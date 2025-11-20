<?php
require_once 'config.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit();
}

// CSRF validation (form comes from index.php)
$csrf = $_POST['csrf_token'] ?? '';
if (!validateCSRFToken($csrf)) {
    echo json_encode(['success' => false, 'message' => 'Invalid security token']);
    exit();
}

$identifier = sanitizeInput($_POST['identifier'] ?? ''); // could be Student ID or Employee ID
if ($identifier === '') {
    echo json_encode(['success' => false, 'message' => 'Please enter your Student ID or Employee ID']);
    exit();
}

try {
    // Ensure table exists
    $pdo->exec("CREATE TABLE IF NOT EXISTS password_reset_requests (
        id INT AUTO_INCREMENT PRIMARY KEY,
        identifier VARCHAR(50) NOT NULL,
        status ENUM('Pending','Resolved') NOT NULL DEFAULT 'Pending',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        resolved_at TIMESTAMP NULL DEFAULT NULL,
        INDEX (identifier),
        INDEX (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    // Insert request
    $stmt = $pdo->prepare("INSERT INTO password_reset_requests (identifier, status) VALUES (?, 'Pending')");
    $stmt->execute([$identifier]);

    echo json_encode(['success' => true, 'message' => 'Your request has been submitted. An administrator will reset your password shortly.']);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error while submitting request']);
}
