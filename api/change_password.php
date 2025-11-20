<?php
require_once __DIR__ . '/../config.php';
requireLogin();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit();
}

$token = $_POST['csrf_token'] ?? '';
if (!validateCSRFToken($token)) {
    echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
    exit();
}

$current_password = $_POST['current_password'] ?? '';
$new_password = $_POST['new_password'] ?? '';
$confirm_password = $_POST['confirm_password'] ?? '';

if (empty($new_password) || empty($confirm_password)) {
    echo json_encode(['success' => false, 'message' => 'All fields are required']);
    exit();
}

if ($new_password !== $confirm_password) {
    echo json_encode(['success' => false, 'message' => 'New password and confirmation do not match']);
    exit();
}

// Basic strength validation: min 8 chars, include letters and numbers
if (strlen($new_password) < 8 || !preg_match('/[A-Za-z]/', $new_password) || !preg_match('/\d/', $new_password)) {
    echo json_encode(['success' => false, 'message' => 'Password must be at least 8 characters and include letters and numbers']);
    exit();
}

$user_id = (int)($_SESSION['user_id'] ?? 0);
if (!$user_id) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit();
}

try {
    // Ensure must_change_password column exists
    try {
        $pdo->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS must_change_password TINYINT(1) NOT NULL DEFAULT 0");
    } catch (PDOException $e) { /* ignore if not supported or already exists */ }

    // Ensure password audit table exists
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS password_audit (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            action VARCHAR(50) NOT NULL,
            performed_by INT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_user (user_id),
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (performed_by) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    } catch (PDOException $e) { /* ignore */ }

    // Fetch current hash and must_change flag
    $stmt = $pdo->prepare('SELECT password, COALESCE(must_change_password, 0) AS must_change_password FROM users WHERE id = ?');
    $stmt->execute([$user_id]);
    $row = $stmt->fetch();
    if (!$row) {
        echo json_encode(['success' => false, 'message' => 'User not found']);
        exit();
    }

    $mustChange = (int)($row['must_change_password'] ?? 0);
    if (!$mustChange) {
        if (empty($current_password)) {
            echo json_encode(['success' => false, 'message' => 'Current password is required']);
            exit();
        }
        if (!password_verify($current_password, $row['password'])) {
            echo json_encode(['success' => false, 'message' => 'Current password is incorrect']);
            exit();
        }
    }

    // Update password
    $new_hash = password_hash($new_password, PASSWORD_DEFAULT);
    $stmt = $pdo->prepare('UPDATE users SET password = ?, must_change_password = 0 WHERE id = ?');
    $stmt->execute([$new_hash, $user_id]);

    // Audit log
    try {
        $aud = $pdo->prepare('INSERT INTO password_audit (user_id, action, performed_by) VALUES (?, ?, ?)');
        $aud->execute([$user_id, 'self_change', $user_id]);
    } catch (PDOException $e) { /* ignore */ }

    // Log out current session for security
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();

    echo json_encode(['success' => true, 'message' => 'Password changed successfully. Please log in again.', 'redirect' => 'index.php']);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error']);
}
