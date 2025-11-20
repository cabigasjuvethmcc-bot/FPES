<?php
// Enable error display during debugging (set to 0 for production)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/config.php';

// Best-effort: ensure the password_reset_requests table exists
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS password_reset_requests (
        id INT AUTO_INCREMENT PRIMARY KEY,
        identifier VARCHAR(50) NOT NULL,
        role ENUM('Student','Faculty','Dean') NOT NULL,
        status ENUM('Pending','Resolved') DEFAULT 'Pending',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
} catch (PDOException $e) {
    // Log but do not expose details to user
    if (function_exists('error_log')) {
        @error_log('forgot_password_report: table ensure failed - ' . $e->getMessage());
    }
}

// If logged in, still allow reporting (no redirect)

$message = '';
$error = '';
 
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!validateCSRFToken($token)) {
        $error = 'Invalid request. Please refresh the page and try again.';
    } else {
        $identifier = sanitizeInput($_POST['identifier'] ?? '');
        $role = sanitizeInput($_POST['role'] ?? '');
        //
        // Normalize role to expected ENUM case: Student, Faculty, Dean
        $role_map = [
            'student' => 'Student',
            'faculty' => 'Faculty',
            'dean' => 'Dean',
            'Student' => 'Student',
            'Faculty' => 'Faculty',
            'Dean' => 'Dean',
        ];
        $role_enum = $role_map[$role] ?? '';

        if (empty($identifier) || empty($role_enum)) {
            $error = 'Identifier and Role are required.';
        } else {
            try {
                $stmt = $pdo->prepare("INSERT INTO password_reset_requests (identifier, role) VALUES (?, ?)");
                $stmt->execute([$identifier, $role_enum]);
                $message = 'Your password reset request has been submitted to the System Admin.';
            } catch (PDOException $e) {
                if (function_exists('error_log')) {
                    @error_log('forgot_password_report: insert failed - ' . $e->getMessage());
                }
                $error = 'Failed to submit request. Please try again later.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Forgot Password</title>
  <link rel="icon" href="img/loginlogo.png?v=2" type="image/png" />
  <link rel="stylesheet" href="styles.css" />
</head>
<body>
  <div class="container" id="login-container">
    <h1 class="app-title">Faculty Performance Evaluation System</h1>
    <div class="login-form" role="region" aria-labelledby="forgot-title">
      <div class="login-logo-wrap">
        <img src="img/loginlogo.png" alt="Institution Logo" class="login-logo" />
      </div>
      <h2 id="forgot-title" class="login-title">Forgot Password</h2>
      <hr class="divider" aria-hidden="true" />

      <p style="color:#4b5563; margin-bottom: .75rem;">Enter your Student ID or Employee ID and select your role. The System Admin will reset your password and notify you.</p>

      <?php if (!empty($message)): ?>
        <div class="success-message" style="display:block;"><?php echo htmlspecialchars($message); ?></div>
      <?php endif; ?>
      <?php if (!empty($error)): ?>
        <div class="error-message" style="display:block;"><?php echo htmlspecialchars($error); ?></div>
      <?php endif; ?>

      <form method="POST" action="">
        <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>" />

        <div class="form-group">
          <label for="role">Role:</label>
          <select id="role" name="role" required>
            <option value="">-- Select Role --</option>
            <option value="Student">Student</option>
            <option value="Faculty">Faculty</option>
            <option value="Dean">Dean</option>
          </select>
        </div>

        <div class="form-group">
          <label for="identifier">Student ID / Employee ID</label>
          <input type="text" id="identifier" name="identifier" placeholder="e.g., 222-001 or FAC-001" required />
        </div>

        <button type="submit" class="btn-primary btn-full">Submit Reset Request</button>
      </form>

      <div style="margin-top: .85rem;">
        <a href="index.php" class="btn-outline">Back to Login</a>
      </div>
    </div>
  </div>
</body>
</html>
