<?php
require_once 'config.php';

$loginError = (string)($_SESSION['login_error'] ?? '');
$loginPrefillUsername = (string)($_SESSION['login_prefill_username'] ?? '');
unset($_SESSION['login_error'], $_SESSION['login_prefill_username']);

// If user is already logged in, redirect to dashboard
if (isLoggedIn()) {
    // If a student scanned a quick evaluation QR before reaching login/signup,
    // continue directly to the evaluation flow.
    if (hasRole('student') && !empty($_SESSION['quick_eval_target'])) {
        header('Location: student/quick_evaluate.php');
        exit();
    }
    header('Location: dashboard.php');
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Faculty Performance Evaluation System - Login</title>
  <link rel="icon" href="img/loginlogo.png?v=2" type="image/png">
  <link rel="stylesheet" href="styles.css">
</head>
<body>
  <div class="container" id="login-container">
    <h1 class="app-title">Faculty Performance Evaluation System</h1>
    <div class="login-form" role="region" aria-labelledby="login-title">
      <!-- Institution Logo -->
      <div class="login-logo-wrap">
        <img src="img/loginlogo.png" alt="Institution Logo" class="login-logo" />
      </div>
      <h2 id="login-title" class="login-title">Login</h2>
      <hr class="divider" aria-hidden="true" />
      <div id="login-error" class="error-message"></div>

      <form id="login-form">
        <div class="form-group">
          <label for="username">Username/ID</label>
          <input type="text" id="username" name="username" placeholder="Enter your username or ID" autocomplete="username" required>
        </div>

        <div class="form-group">
          <label for="password">Password</label>
          <input type="password" id="password" name="password" placeholder="Enter your password" autocomplete="current-password" required>
        </div>

        <button type="submit" id="login-btn" class="btn-primary btn-full">Login</button>
      </form>
      
      <div style="margin-top: 0.75rem;">
        <a href="forgot_password_report.php" style="font-size: 0.9rem;">Forgot Password</a>
      </div>
      
      
  </div>
  
  <style>
    .login-logo-wrap {
      display: flex;
      justify-content: center;
      align-items: center;
      margin-bottom: 1rem;
    }
    .login-logo {
      width: 100px;
    }
    .login-form {
      display: flex;
      flex-direction: column;
      align-items: center;
    }
    .form-group {
      display: flex;
      flex-direction: column;
      align-items: center;
    }
    .form-group label {
      display: none;
    }
    .form-group input {
      width: 100%;
    }
    .btn-primary {
      width: 100%;
    }
  </style>
  
  <script src="script.js"></script>
  <script>
    (function() {
      const msg = <?php echo json_encode($loginError, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
      const prefill = <?php echo json_encode($loginPrefillUsername, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
      if (prefill) {
        const u = document.getElementById('username');
        if (u) u.value = prefill;
      }
      if (msg) {
        const e = document.getElementById('login-error');
        if (e) {
          e.textContent = msg;
          e.style.display = 'block';
        }
      }
    })();
  </script>
  
</body>
</html>
