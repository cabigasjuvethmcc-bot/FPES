<?php
require_once '../config.php';
header('Content-Type: application/json');

$faculty_user_id = $_GET['faculty_user_id'] ?? '';
$faculty_user_id = (int)$faculty_user_id;
if ($faculty_user_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'faculty_user_id is required']);
    exit();
}

try {
    // Ensure table exists
    $pdo->exec("CREATE TABLE IF NOT EXISTS faculty_subjects (
        id INT AUTO_INCREMENT PRIMARY KEY,
        faculty_user_id INT NOT NULL,
        subject_code VARCHAR(50) DEFAULT NULL,
        subject_name VARCHAR(255) NOT NULL,
        UNIQUE KEY uniq_faculty_subject (faculty_user_id, subject_code, subject_name),
        INDEX idx_faculty_user_id (faculty_user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    // Fetch subjects handled by this faculty
    $stmt = $pdo->prepare("SELECT subject_code, subject_name FROM faculty_subjects WHERE faculty_user_id = ? ORDER BY subject_name");
    $stmt->execute([$faculty_user_id]);
    $rows = $stmt->fetchAll();

    echo json_encode(['success' => true, 'data' => $rows]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error']);
}
