document.addEventListener("DOMContentLoaded", () => {
  // Dynamically switch username label based on selected role
  function updateLoginIdentityLabel() {
    const roleSel = document.getElementById("role");
    const userInput = document.getElementById("username");
    const userLabel = document.querySelector("label[for='username']");
    if (!roleSel || !userInput || !userLabel) return;
    if (roleSel.value === 'student') {
      userLabel.textContent = 'Student ID:';
      userInput.placeholder = 'Enter your Student ID';
    } else if (roleSel.value === 'faculty' || roleSel.value === 'dean') {
      userLabel.textContent = 'Employee ID:';
      userInput.placeholder = 'Enter your Employee ID (e.g., F-001 / D-001)';
    } else {
      userLabel.textContent = 'Username:';
      userInput.placeholder = 'Enter your username';
    }
  }

  const roleSel = document.getElementById("role");
  if (roleSel) {
    roleSel.addEventListener('change', updateLoginIdentityLabel);
    // Initialize on load
    updateLoginIdentityLabel();
  }

  document.getElementById("login-form").addEventListener("submit", function(e) {
    e.preventDefault();
    
    const formData = new FormData(this);
    formData.append('action', 'login');
    
    const errorDiv = document.getElementById("login-error");
    const loginBtn = document.getElementById("login-btn");
    
    // Disable button and show loading
    loginBtn.disabled = true;
    loginBtn.textContent = "Logging in...";
    errorDiv.style.display = "none";

    fetch('auth.php', {
      method: 'POST',
      body: formData
    })
    .then(response => response.json())
    .then(data => {
      if (data.success) {
        window.location.href = data.redirect;
      } else {
        errorDiv.textContent = data.message;
        errorDiv.style.display = "block";
      }
    })
    .catch(error => {
      errorDiv.textContent = "An error occurred. Please try again.";
      errorDiv.style.display = "block";
    })
    .finally(() => {
      loginBtn.disabled = false;
      loginBtn.textContent = "Login";
    });
  });

  // Forgot Password: modal wiring (if present on this page)
  const forgotLink = document.getElementById('forgot-link');
  const forgotModal = document.getElementById('forgot-modal');
  const forgotClose = document.getElementById('forgot-close');
  const forgotForm = document.getElementById('forgot-form');
  const forgotMsg = document.getElementById('forgot-msg');
  const forgotSubmit = document.getElementById('forgot-submit');

  function hideForgotMsg() { if (forgotMsg) { forgotMsg.style.display = 'none'; forgotMsg.textContent = ''; forgotMsg.className = 'error-message'; } }
  function showForgotMsg(text, ok=false) {
    if (!forgotMsg) return;
    forgotMsg.textContent = text;
    forgotMsg.style.display = 'block';
    forgotMsg.className = ok ? 'success-message' : 'error-message';
  }

  if (forgotLink && forgotModal) {
    forgotLink.addEventListener('click', (e) => {
      e.preventDefault();
      hideForgotMsg();
      const idInput = document.getElementById('identifier');
      if (idInput) idInput.value = '';
      forgotModal.style.display = 'block';
    });
  }
  if (forgotClose && forgotModal) {
    forgotClose.addEventListener('click', () => { forgotModal.style.display = 'none'; });
  }
  window.addEventListener('click', (e) => {
    if (forgotModal && e.target === forgotModal) forgotModal.style.display = 'none';
  });

  if (forgotForm) {
    forgotForm.addEventListener('submit', function(e){
      e.preventDefault();
      hideForgotMsg();
      if (!forgotSubmit) return;
      forgotSubmit.disabled = true;
      const prevText = forgotSubmit.textContent;
      forgotSubmit.textContent = 'Submitting...';
      const formData = new FormData(this);
      fetch('password_reset_request.php', {
        method: 'POST',
        body: formData
      })
      .then(r => r.json())
      .then(data => {
        if (data.success) {
          showForgotMsg(data.message || 'Request submitted.', true);
          // optionally close after a delay
          setTimeout(() => { if (forgotModal) forgotModal.style.display = 'none'; }, 1200);
        } else {
          showForgotMsg(data.message || 'Failed to submit request.');
        }
      })
      .catch(() => showForgotMsg('An error occurred. Please try again.'))
      .finally(() => {
        forgotSubmit.disabled = false;
        forgotSubmit.textContent = prevText;
      });
    });
  }
});
