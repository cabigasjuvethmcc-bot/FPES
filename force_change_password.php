<?php
require_once __DIR__ . '/config.php';
requireLogin();

// If user isn't flagged, redirect to dashboard
if (empty($_SESSION['must_change_password'])) {
    header('Location: dashboard.php');
    exit();
}

$csrf = generateCSRFToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Update Your Password</title>
  <link rel="stylesheet" href="styles.css">
  <style>
    .card { max-width: 520px; margin: 3rem auto; background:#fff; border-radius: 14px; box-shadow: var(--card-shadow); padding: 1.25rem 1.25rem 1.5rem; }
    .hint { color:#6b7280; font-size: .9rem; margin-top: .25rem; }
    .success-message { color: #166534; background: #dcfce7; padding: .75rem; border-radius: 10px; margin-bottom: .75rem; display:none; }
    .error-message { display:none; }
    .strength { font-size:.85rem; margin-top:.25rem; }
  </style>
</head>
<body style="background: var(--bg-color);">
  <div class="card">
    <h2 style="margin-bottom:.75rem;">Update Your Password</h2>
    <p class="hint">An administrator reset your password. You must create a new password before continuing.</p>

    <div id="msg-success" class="success-message"></div>
    <div id="msg-error" class="error-message"></div>

    <form id="force-change-form">
      <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
      <div class="form-group">
        <label for="new_password">New Password</label>
        <input type="password" id="new_password" name="new_password" required>
        <div id="pw-strength" class="strength hint">Must be at least 8 characters, include letters and numbers.</div>
      </div>
      <div class="form-group">
        <label for="confirm_password">Confirm New Password</label>
        <input type="password" id="confirm_password" name="confirm_password" required>
      </div>
      <button type="submit" class="btn-primary">Save New Password</button>
    </form>
  </div>

  <script>
    function checkStrength(pw){
      let score = 0;
      if (pw.length >= 8) score++;
      if (/[A-Za-z]/.test(pw)) score++;
      if (/\d/.test(pw)) score++;
      return score;
    }

    const newPw = document.getElementById('new_password');
    const strength = document.getElementById('pw-strength');
    newPw.addEventListener('input', () => {
      const s = checkStrength(newPw.value);
      const map = ['Weak','Okay','Good'];
      strength.textContent = 'Strength: ' + map[Math.max(0, s-1)] + ' — must be at least 8 characters, include letters and numbers.';
    });

    document.getElementById('force-change-form').addEventListener('submit', function(e){
      e.preventDefault();
      const form = new FormData(this);
      // When forced change, API will ignore current_password requirement
      form.set('current_password', '');
      fetch('api/change_password.php', { method: 'POST', body: form })
        .then(r => r.json())
        .then(data => {
          const ok = document.getElementById('msg-success');
          const err = document.getElementById('msg-error');
          ok.style.display = 'none';
          err.style.display = 'none';
          if (data.success) {
            ok.textContent = data.message || 'Password updated. Please login again.';
            ok.style.display = 'block';
            setTimeout(() => { window.location.href = data.redirect || 'index.php'; }, 1200);
          } else {
            err.textContent = data.message || 'Unable to change password';
            err.style.display = 'block';
          }
        })
        .catch(() => {
          const err = document.getElementById('msg-error');
          err.textContent = 'Network error. Please try again.';
          err.style.display = 'block';
        });
    });
  </script>
</body>
</html>
