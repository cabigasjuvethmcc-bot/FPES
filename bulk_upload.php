<?php
require_once __DIR__ . '/config.php';
requireRole('admin');

$adminDept = $_SESSION['department'] ?? '';
// Treat admins from core/IT as System Admins; dept admins are restricted to their department
$deptAdminList = ['Business','Education','Technology'];
$isSystemAdmin = !in_array($adminDept, $deptAdminList, true);
$csrf = generateCSRFToken();
$embedded = isset($_GET['embed']) && $_GET['embed'] == '1';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bulk Upload Users</title>
  <link rel="stylesheet" href="styles.css">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
  <style>
    .container { max-width: 1100px; margin: 24px auto; background:#fff; border-radius:16px; padding:28px; box-shadow: 0 12px 30px rgba(0,0,0,.08); }
    .header { display:flex; gap:16px; justify-content:space-between; align-items:center; margin-bottom:20px; }
    .header h1 { margin:0; font-size: 1.8rem; letter-spacing:.2px; }
    .toolbar { display:flex; gap:10px; align-items:center; flex-wrap:wrap; }
    .grid { display:grid; grid-template-columns: 1fr; gap:18px; }
    .form-group { margin-bottom:14px; }
    label { font-weight:700; display:block; margin-bottom:6px; color:#111827; }
    select { width:100%; padding:12px; border:2px solid #e5e7eb; border-radius:10px; background:#fff; }
    input[type=file] { display:none; }
    .actions { display:flex; gap:10px; flex-wrap:wrap; }
    .btn { background: var(--secondary-color); color:#fff; padding:10px 16px; border:none; border-radius:10px; cursor:pointer; font-weight:700; box-shadow: 0 6px 14px rgba(16,185,129,.2); }
    .btn:hover { filter: brightness(.98); transform: translateY(-1px); transition: all .15s ease; }
    .btn.alt { background: var(--primary-color); box-shadow: 0 6px 14px rgba(59,130,246,.18); }
    .btn.danger { background: var(--danger-color); box-shadow: 0 6px 14px rgba(239,68,68,.18); }
    .hint { color:#6b7280; font-size: 0.9rem; }
    .progress { height:10px; background:#eef2f7; border-radius:999px; overflow:hidden; margin-top:10px; }
    .bar { height:100%; width:0%; background: linear-gradient(90deg, var(--primary-color), #22c55e); transition: width .35s ease; }
    .card { background:#f9fafb; border-radius:14px; padding:18px; border:1px solid #eef2f7; }
    .result { margin-top:16px; padding:14px; background:#f0fff4; border:1px solid #bbf7d0; border-radius:10px; display:none; }
    .error { background:#fff1f2; border-color:#fecdd3; }
    .dropzone { border:2px dashed #d1d5db; background:#f9fafb; border-radius:12px; padding:16px; text-align:center; color:#6b7280; cursor:pointer; transition:border-color .15s ease, background .15s ease; }
    .dropzone:hover { border-color:#93c5fd; background:#f3f9ff; }
    .pill { display:inline-flex; align-items:center; gap:8px; padding:8px 14px; border-radius:999px; font-weight:700; }
    .pill.green { background:#ecfdf5; color:#065f46; border:1px solid #a7f3d0; }
    .file-name { margin-top:8px; font-size:.9rem; color:#374151; }
    @media (max-width: 640px) {
      .container { padding:18px; border-radius:12px; }
      .header h1 { font-size: 1.4rem; }
    }
  </style>
  <?php
    // Department-specific theming for Department Admins
    $deptCodes = ['Education' => 'SOE', 'Business' => 'SOB', 'Technology' => 'SOT'];
    $deptColors = [
      'Technology' => ['primary' => '#0ea5e9', 'secondary' => '#22d3ee', 'accent' => '#06b6d4'], // tech/modern
      'Education'  => ['primary' => '#1e3a8a', 'secondary' => '#3b82f6', 'accent' => '#60a5fa'], // academic
      'Business'   => ['primary' => '#0f766e', 'secondary' => '#10b981', 'accent' => '#34d399'], // professional
    ];
    $deptLabel = $deptCodes[$adminDept] ?? '';
    if (!$isSystemAdmin) {
      $c = $deptColors[$adminDept] ?? $deptColors['Technology'];
      echo '<style>
        :root{ --primary-color: '.htmlspecialchars($c['primary']).'; --secondary-color: '.htmlspecialchars($c['secondary']).'; }
        .sidebar h2{ color:#111827; }
        .sidebar{ background:#ffffff; color:#374151; border-right:1px solid #e5e7eb; }
        .sidebar a{ color:#4b5563; }
        .sidebar a:hover{ background:#f3f4f6; color:#111827; }
      </style>';
    }
  ?>
  <?php if (!$embedded): ?>
</head>
<body>
  <div class="dashboard">
    <div class="sidebar">
      <h2><?php echo $isSystemAdmin ? 'Admin' : htmlspecialchars(($deptLabel ?: $adminDept).' Admin'); ?></h2>
      <?php if ($isSystemAdmin): ?>
        <a href="admin/admin.php"><i class="fas fa-gauge-high"></i> Back to Dashboard</a>
        <button class="logout-btn" onclick="logout()">Logout</button>
      <?php else: ?>
        <a href="department_dashboard.php"><i class="fas fa-gauge-high"></i> Dashboard</a>
        <a href="departments/<?php echo strtolower($adminDept); ?>/enrollment.php"><i class="fas fa-user-plus"></i> Enroll Student</a>
        <a href="bulk_upload.php" class="active"><i class="fas fa-file-upload"></i> Bulk Upload</a>
        <a href="departments/<?php echo strtolower($adminDept); ?>/student_management.php"><i class="fas fa-users-cog"></i> Manage Students</a>
        <a href="departments/<?php echo strtolower($adminDept); ?>/faculty_management.php"><i class="fas fa-chalkboard-teacher"></i> Manage Faculty</a>
        <a href="reports/department_report.php?dept=<?php echo urlencode($adminDept); ?>" target="_blank"><i class="fas fa-chart-bar"></i> Department Report</a>
        <button class="logout-btn" onclick="logout()"><i class="fas fa-sign-out-alt"></i> Logout</button>
      <?php endif; ?>
    </div>
    <div class="main-content">
  <?php else: ?>
</head>
<body style="background: transparent;">
  <div class="main-content" style="padding:0;">
  <?php endif; ?>
      <div class="container">
        <div class="header">
          <h1>Bulk Upload Users</h1>
          <?php if (!$isSystemAdmin): ?>
            <span class="pill" style="background: rgba(107,114,128,.12); color:#111827; border:1px solid #e5e7eb;">
              <?php if ($adminDept === 'Education'): ?>
                <i class="fas fa-graduation-cap"></i> SOE Admin Portal
              <?php elseif ($adminDept === 'Business'): ?>
                <i class="fas fa-chart-line"></i> SOB Admin Portal
              <?php else: ?>
                <i class="fas fa-gear"></i> SOT Admin Portal
              <?php endif; ?>
            </span>
          <?php endif; ?>
          <div class="toolbar">
            <button class="btn alt" onclick="downloadTemplate('student')">Student Template</button>
            <button class="btn alt" onclick="downloadTemplate('faculty')">Faculty Template</button>
            <?php if ($isSystemAdmin): ?>
            <button class="btn alt" onclick="downloadTemplate('dean')">Dean Template</button>
            <?php endif; ?>
          </div>
        </div>

        <div class="grid">
          <div class="card">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
              <h3 style="margin:0;">Upload File</h3>
              <span class="pill green">CSV or XLSX</span>
            </div>
            <form id="uploadForm">
              <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>" />
              <div class="form-group">
                <label for="role">User Type</label>
                <select id="role" name="role" required>
                  <option value="">-- Select --</option>
                  <option value="student">Students</option>
                  <option value="faculty">Faculty</option>
                  <?php if ($isSystemAdmin): ?>
                  <option value="dean">Deans</option>
                  <?php endif; ?>
                </select>
                <div class="hint">Choose a user type then drag a file below or click to browse.</div>
              </div>

              <?php if ($isSystemAdmin): ?>
              <div class="form-group" id="deptRow" style="display:none;">
                <label for="department">Apply Department (optional)</label>
                <select id="department" name="department">
                  <option value="">-- None --</option>
                  <option value="Business">Business</option>
                  <option value="Education">Education</option>
                  <option value="Technology">Technology</option>
                </select>
                <div class="hint">For Students template (no department column), choose department to assign. For Faculty/Deans, file column takes precedence.</div>
              </div>
              <?php else: ?>
              <div class="form-group">
                <label>Department</label>
                <input type="text" value="<?php echo htmlspecialchars($adminDept); ?>" disabled>
                <div class="hint">As Department Admin, uploads are limited to your department.</div>
              </div>
              <?php endif; ?>

              <div class="form-group">
                <label for="file">Select File</label>
                <div id="dropzone" class="dropzone">Drop CSV/XLSX here or click to select</div>
                <input type="file" id="file" name="file" accept=".csv,.xlsx" required />
                <div id="fileName" class="file-name" style="display:none;"></div>
              </div>

              <div class="form-group" style="display:flex; gap:10px; align-items:center;">
                <button type="submit" class="btn">Start Upload</button>
                <button type="button" class="btn danger" onclick="resetForm()">Reset</button>
              </div>

              <div class="progress"><div id="bar" class="bar"></div></div>
            </form>
          </div>

          
        </div>

        <div id="result" class="result"></div>
      </div>
    </div>
  <?php if (!$embedded): ?>
    </div>
  </div>
  <?php endif; ?>

  <script>
    <?php if (!$embedded): ?>
    function logout() {
      fetch('auth.php', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/x-www-form-urlencoded',
          'X-Requested-With': 'XMLHttpRequest'
        },
        body: 'action=logout'
      })
      .then(r => r.json())
      .then(d => {
        if (d && d.success && d.redirect) { location.href = d.redirect; return; }
        // Fallback if unexpected JSON
        location.href = 'auth.php?action=logout';
      })
      .catch(() => {
        // Fallback if network/parse error
        location.href = 'auth.php?action=logout';
      });
    }
    <?php endif; ?>
    function setProgress(p){ document.getElementById('bar').style.width = p + '%'; }
    function resetForm(){
      const form = document.getElementById('uploadForm');
      form.reset();
      setProgress(0);
      const box = document.getElementById('result');
      box.style.display='none';
      const fn = document.getElementById('fileName');
      if (fn) { fn.style.display='none'; fn.textContent=''; }
      const dz = document.getElementById('dropzone');
      if (dz) { dz.classList.remove('drag'); dz.textContent = 'Drop CSV/XLSX here or click to select'; }
    }
    function downloadTemplate(role){ window.location.href = 'api/bulk_templates.php?role=' + encodeURIComponent(role) + '&format=csv'; }
    const roleEl = document.getElementById('role');
    const deptRow = document.getElementById('deptRow');
    if (roleEl && deptRow) {
      roleEl.addEventListener('change', ()=>{
        // Show dept apply only for students (since their template lacks department)
        deptRow.style.display = roleEl.value === 'student' ? '' : 'none';
      });
    }
    // Dropzone wiring
    (function(){
      const dz = document.getElementById('dropzone');
      const input = document.getElementById('file');
      const nameEl = document.getElementById('fileName');
      if (!dz || !input) return;
      const showName = (file) => {
        if (!nameEl) return;
        nameEl.style.display = 'block';
        nameEl.textContent = file ? `Selected: ${file.name}` : '';
      };
      dz.addEventListener('click', ()=> input.click());
      input.addEventListener('change', ()=> {
        const f = input.files && input.files[0];
        showName(f || null);
      });
      dz.addEventListener('dragover', (ev)=>{ ev.preventDefault(); dz.classList.add('drag'); });
      dz.addEventListener('dragleave', ()=> dz.classList.remove('drag'));
      dz.addEventListener('drop', (ev)=>{
        ev.preventDefault(); dz.classList.remove('drag');
        if (ev.dataTransfer && ev.dataTransfer.files && ev.dataTransfer.files.length){
          input.files = ev.dataTransfer.files; // assign FileList
          const f = input.files[0];
          showName(f || null);
        }
      });
    })();

    document.getElementById('uploadForm').addEventListener('submit', async (e)=>{
      e.preventDefault();
      const form = e.target;
      const fd = new FormData(form);
      setProgress(10);
      try {
        const res = await fetch('api/bulk_upload.php', { method: 'POST', body: fd });
        setProgress(80);
        const data = await res.json();
        setProgress(100);
        const box = document.getElementById('result');
        box.style.display = 'block';
        if (data.success) {
          box.className = 'result';
          box.innerHTML = `
            <strong>Upload Complete</strong><br/>
            ${data.summary}<br/>
            <div style="margin-top:8px; display:flex; gap:8px; flex-wrap:wrap;">
              ${data.error_report ? `<a class="btn alt" href="${data.error_report}" target="_blank">Download Error Report</a>` : ''}
              ${data.credentials_report ? `<a class="btn" href="${data.credentials_report}" target="_blank" title="Contains temporary credentials. Handle securely.">Download Temporary Credentials (CSV)</a>` : ''}
            </div>
            ${typeof data.provided_passwords !== 'undefined' ? `<div class="hint" style="margin-top:6px;">Passwords: <strong>${data.provided_passwords}</strong> provided, <strong>${data.generated_passwords}</strong> generated.</div>` : ''}
          `;
        } else {
          box.className = 'result error';
          box.innerHTML = `<strong>Upload Failed</strong><br/>${data.message || 'An error occurred.'}`;
        }
      } catch(err){
        setProgress(100);
        const box = document.getElementById('result');
        box.style.display = 'block';
        box.className = 'result error';
        box.textContent = 'Upload failed: ' + err;
      }
    });
  </script>
</body>
</html>
