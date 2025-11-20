<?php
require_once __DIR__ . '/../config.php';
requireRole('admin');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: manage_password_resets.php?reset=invalid');
    exit();
}

$token = $_POST['csrf_token'] ?? '';
if (!validateCSRFToken($token)) {
    header('Location: manage_password_resets.php?reset=invalid');
    exit();
}

$request_id = (int)($_POST['request_id'] ?? 0);
$identifier = sanitizeInput($_POST['identifier'] ?? '');
$role = sanitizeInput($_POST['role'] ?? ''); // Expected: Student | Faculty | Dean (any case)

if (!$request_id || !$identifier || !$role) {
    header('Location: manage_password_resets.php?reset=invalid');
    exit();
}

try {
    // Ensure auxiliary schema BEFORE starting transaction to avoid implicit commits from DDL
    // Ensure users.must_change_password column exists (backward compatibility)
    try {
        $chk = $pdo->prepare("SELECT COUNT(*) AS c FROM information_schema.columns WHERE table_schema = ? AND table_name = 'users' AND column_name = 'must_change_password'");
        $chk->execute([DB_NAME]);
        $hasCol = (int)($chk->fetch()['c'] ?? 0) > 0;
        if (!$hasCol) {
            $pdo->exec("ALTER TABLE users ADD COLUMN must_change_password TINYINT(1) NOT NULL DEFAULT 0 AFTER password");
        }
    } catch (PDOException $e) { /* ignore; non-fatal */ }

    // Ensure password_audit table exists
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS password_audit (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            action VARCHAR(50) NOT NULL,
            performed_by INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_user (user_id),
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (performed_by) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    } catch (PDOException $e) { /* ignore */ }

    $pdo->beginTransaction();

    // Normalize role to canonical case and map to table/id field
    $role_map = [
        'student' => 'Student',
        'faculty' => 'Faculty',
        'dean' => 'Dean',
        'Student' => 'Student',
        'Faculty' => 'Faculty',
        'Dean' => 'Dean',
    ];
    $role_norm = $role_map[$role] ?? $role_map[strtolower($role)] ?? '';

    // Map role to table and id field
    $lookup = [
        'Student' => ['table' => 'students', 'id_field' => 'student_id'],
        'Faculty' => ['table' => 'faculty', 'id_field' => 'employee_id'],
        'Dean'    => ['table' => 'deans',    'id_field' => 'employee_id'],
    ];

    if (!isset($lookup[$role_norm])) {
        throw new Exception('Invalid role');
    }

    $info = $lookup[$role_norm];

    // Find the user_id via join on the role-specific table
    $sql = "SELECT u.id AS user_id
            FROM users u
            INNER JOIN {$info['table']} t ON u.id = t.user_id
            WHERE t.{$info['id_field']} = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$identifier]);
    $row = $stmt->fetch();

    if (!$row) {
        // Fallback: Some older reset requests may store the username as the identifier
        $stmt = $pdo->prepare('SELECT id AS user_id FROM users WHERE username = ? LIMIT 1');
        $stmt->execute([$identifier]);
        $row = $stmt->fetch();
        if (!$row) {
            throw new Exception('Identifier not found');
        }
    }

    $user_id = (int)$row['user_id'];

    // Reset password to '123' (hashed) and force change on next login
    $new_hash = password_hash('123', PASSWORD_DEFAULT);
    $stmt = $pdo->prepare('UPDATE users SET password = ?, must_change_password = 1 WHERE id = ?');
    $stmt->execute([$new_hash, $user_id]);

    // Audit: admin reset
    try {
        $aud = $pdo->prepare('INSERT INTO password_audit (user_id, action, performed_by) VALUES (?, ?, ?)');
        $performedBy = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
        $aud->execute([$user_id, 'admin_reset', $performedBy]);
    } catch (PDOException $e) { /* ignore */ }

    // Mark the request as Resolved
    // Idempotent resolve: set Resolved and do not error if already resolved
    try {
        $stmt = $pdo->prepare('UPDATE password_reset_requests SET status = "Resolved" WHERE id = ?');
        $stmt->execute([$request_id]);
    } catch (PDOException $e) { /* ignore */ }

    $pdo->commit();
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    // Log the error for backend debugging, but do not expose details to the Admin UI
    error_log('[reset_password.php] Password reset failed: ' . $e->getMessage());
    // Redirect with error flag
    header('Location: manage_password_resets.php?reset=error');
    exit();
}

header('Location: manage_password_resets.php?reset=success');
exit();
