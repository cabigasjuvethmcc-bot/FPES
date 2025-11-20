<?php
require_once '../../config.php';
require_once '../../catalog.php';
requireRole('admin');

// Ensure this is Technology department admin
if (($_SESSION['department'] ?? '') !== 'Technology') {
    header('Location: ../../dashboard.php');
    exit();
}

$admin_department = 'Technology';
$admin_name = $_SESSION['full_name'] ?? $_SESSION['username'];

// Handle faculty subject update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'update_faculty_subjects') {
        $faculty_user_id = (int)($_POST['faculty_user_id'] ?? 0);
        $subjects = $_POST['subjects'] ?? [];
        try {
            if ($faculty_user_id <= 0) { throw new Exception('Invalid faculty'); }
            // Ensure table exists
            $pdo->exec("CREATE TABLE IF NOT EXISTS faculty_subjects (
                id INT AUTO_INCREMENT PRIMARY KEY,
                faculty_user_id INT NOT NULL,
                subject_code VARCHAR(50) DEFAULT NULL,
                subject_name VARCHAR(255) NOT NULL,
                UNIQUE KEY uniq_faculty_subject (faculty_user_id, subject_code, subject_name),
                INDEX idx_faculty_user_id (faculty_user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

            // Begin transaction
            $pdo->beginTransaction();
            // Clear existing
            $pdo->prepare('DELETE FROM faculty_subjects WHERE faculty_user_id = ?')->execute([$faculty_user_id]);

            if (is_array($subjects) && !empty($subjects)) {
                // Build allowed set from DB subjects for this department
                $allowed = [];
                $subStmt = $pdo->prepare('SELECT code, name FROM subjects WHERE department = ?');
                $subStmt->execute([$admin_department]);
                foreach ($subStmt->fetchAll() as $row) {
                    $allowed[$row['code'] . '::' . $row['name']] = true;
                }
                $ins = $pdo->prepare('INSERT INTO faculty_subjects (faculty_user_id, subject_code, subject_name) VALUES (?, ?, ?)');
                foreach ($subjects as $sub) {
                    if (!is_string($sub) || $sub === '' || !isset($allowed[$sub])) { continue; }
                    $parts = explode('::', $sub, 2);
                    $code = sanitizeInput($parts[0] ?? '');
                    $name = sanitizeInput($parts[1] ?? '');
                    if ($name !== '') {
                        $ins->execute([$faculty_user_id, $code, $name]);
                    }
                }
            }
            $pdo->commit();
            $success = 'Faculty subjects updated successfully!';
        } catch (Exception $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            $error = 'Failed to update faculty subjects.';
        }
    }
}

// Load faculty list for Technology
try {
    $stmt = $pdo->prepare("SELECT u.id, u.username, u.full_name, f.employee_id
                           FROM users u
                           JOIN faculty f ON u.id = f.user_id
                           WHERE u.role = 'faculty' AND u.department = ?
                           ORDER BY u.full_name");
    $stmt->execute([$admin_department]);
    $faculty_list = $stmt->fetchAll();
} catch (PDOException $e) {
    $faculty_list = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Technology Department - Manage Faculty</title>
  <link rel="stylesheet" href="../../styles.css">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
  <style>
    :root { --dept-primary: #800020; --dept-secondary: #a0002a; }
    .dashboard .sidebar { width: 200px; background: #ffffff; color: #374151; border-right: 1px solid #e5e7eb; }
    .dashboard .sidebar a { color: #4b5563; }
    .dashboard .sidebar a:hover { background: #f3f4f6; color: #111827; }
    .dashboard .main-content { background: #f5f7fb; }
    .btn { background: var(--dept-primary); color:#fff; padding:8px 12px; border-radius:10px; border:0; cursor:pointer; }
    .education-input { width: 100%; padding: 10px; border: 2px solid #e5e7eb; border-radius: 10px; }
    .table { width:100%; border-collapse: collapse; }
    .table th, .table td { padding: 12px; border-bottom:1px solid #e5e7eb; text-align:left; }
  </style>
</head>
<body>
  <div class="dashboard">
    <div class="sidebar">
      <h2>Technology Admin</h2>
      <a href="../../department_dashboard.php"><i class="fas fa-gauge-high"></i> Dashboard</a>
      <a href="enrollment.php"><i class="fas fa-user-plus"></i> Enroll Student</a>
      <a href="../../bulk_upload.php"><i class="fas fa-file-upload"></i> Bulk Upload</a>
      <a href="student_management.php"><i class="fas fa-users-cog"></i> Manage Students</a>
      <a href="#" class="active"><i class="fas fa-chalkboard-teacher"></i> Manage Faculty</a>
      <a href="../../reports/department_report.php?dept=Technology" target="_blank"><i class="fas fa-chart-bar"></i> Department Report</a>
      <button class="logout-btn" onclick="logout()"><i class="fas fa-sign-out-alt"></i> Logout</button>
    </div>
    <div class="main-content">
      <div style="padding:20px; max-width:1200px; margin:0 auto;">
        <h1 style="margin:0 0 12px 0;">Manage Faculty - Technology</h1>
        <?php if (isset($success)): ?><div class="success-message"><?php echo htmlspecialchars($success); ?></div><?php endif; ?>
        <?php if (isset($error)): ?><div class="error-message"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
        <div style="background:#fff; border-radius:12px; padding:16px;">
          <table class="table">
            <thead><tr><th>Employee ID</th><th>Full Name</th><th>Username</th><th>Actions</th></tr></thead>
            <tbody>
              <?php foreach ($faculty_list as $f): ?>
              <tr>
                <td><?php echo htmlspecialchars($f['employee_id'] ?? ''); ?></td>
                <td><?php echo htmlspecialchars($f['full_name'] ?? $f['username']); ?></td>
                <td><?php echo htmlspecialchars($f['username']); ?></td>
                <td><button class="btn" onclick="openEditSubjects(<?php echo (int)$f['id']; ?>, '<?php echo htmlspecialchars($f['full_name'] ?? $f['username'], ENT_QUOTES); ?>')"><i class="fas fa-edit"></i> Edit Subjects</button></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>

  <!-- Modal -->
  <div id="subjectsModal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.5); z-index:1000;">
    <div style="position:absolute; top:50%; left:50%; transform:translate(-50%,-50%); background:#fff; padding:24px; border-radius:12px; width:90%; max-width:700px;">
      <h3 id="modalTitle" style="margin-top:0;">Edit Faculty Subjects</h3>
      <form method="POST" id="subjectsForm">
        <input type="hidden" name="action" value="update_faculty_subjects">
        <input type="hidden" name="faculty_user_id" id="modalFacultyId">
        <div class="form-group">
          <label>Subjects (Technology)</label>
          <div class="subject-picker">
            <div class="picker-toolbar" style="display:flex; gap:8px; align-items:center; margin:6px 0;">
              <input type="text" id="subjectSearch" class="education-input" placeholder="Search subjects (code or name)" style="flex:1;">
              <button type="button" class="btn" id="btnSelectAll" style="background:#16a34a;">Select All</button>
              <button type="button" class="btn" id="btnClear" style="background:#ef4444;">Clear</button>
            </div>
            <select id="modalSubjects" name="subjects[]" multiple size="10" class="education-input" style="height:260px;"></select>
            <div id="selectedCount" style="margin-top:6px; font-size:12px; color:#6b7280;">0 selected</div>
          </div>
        </div>
        <div style="display:flex; gap:10px; justify-content:flex-end;">
          <button type="button" class="btn" style="background:#6b7280;" onclick="closeModal()">Cancel</button>
          <button type="submit" class="btn">Save</button>
        </div>
      </form>
    </div>
  </div>

  <script>
    async function logout() {
      try {
        const res = await fetch('../../auth.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: 'action=logout'
        });
        try {
          const data = await res.json();
          if (data && data.success && data.redirect) { window.location.href = data.redirect; return; }
        } catch(_) { /* ignore parse */ }
      } catch(_) { /* ignore */ }
      window.location.href = '../../auth.php?action=logout';
    }
  </script>
  <script>
    async function fetchSubjects() {
      try { const r = await fetch(`../../api/subjects.php?department=Technology`); const d = await r.json(); return (d && d.success && Array.isArray(d.data)) ? d.data : []; } catch { return []; }
    }
    async function fetchFacultySubjects(uid) {
      try { const r = await fetch(`../../api/faculty_subjects.php?faculty_user_id=${encodeURIComponent(uid)}`); const d = await r.json(); return (d && d.success && Array.isArray(d.data)) ? d.data : []; } catch { return []; }
    }
    async function openEditSubjects(userId, name) {
      document.getElementById('modalFacultyId').value = userId;
      document.getElementById('modalTitle').textContent = `Edit Subjects for ${name}`;
      const sel = document.getElementById('modalSubjects');
      sel.innerHTML = '';
      const [subjects, current] = await Promise.all([fetchSubjects(), fetchFacultySubjects(userId)]);
      const currentSet = new Set(current.map(x => (x.subject_code ? x.subject_code + '::' + x.subject_name : '' )));
      subjects.forEach(s => {
        const opt = document.createElement('option');
        opt.value = s.code + '::' + s.name;
        opt.textContent = `${s.code} - ${s.name}`;
        if (currentSet.has(opt.value)) opt.selected = true;
        sel.appendChild(opt);
      });
      const search = document.getElementById('subjectSearch');
      const btnAll = document.getElementById('btnSelectAll');
      const btnClear = document.getElementById('btnClear');
      const count = document.getElementById('selectedCount');
      function updateCount(){ const n = Array.from(sel.options).filter(o=>o.selected).length; count.textContent = `${n} selected`; }
      function filterOptions(q){ const qq=(q||'').toLowerCase(); Array.from(sel.options).forEach(opt=>{ const txt=(opt.textContent||'').toLowerCase(); opt.hidden = qq && !txt.includes(qq); }); }
      search.oninput = ()=>filterOptions(search.value);
      btnAll.onclick = ()=>{ Array.from(sel.options).forEach(o=>{ if(!o.hidden) o.selected = true; }); updateCount(); };
      btnClear.onclick = ()=>{ Array.from(sel.options).forEach(o=> o.selected = false); updateCount(); };
      sel.onchange = updateCount; updateCount();
      document.getElementById('subjectsModal').style.display = 'block';
    }
    function closeModal(){ document.getElementById('subjectsModal').style.display = 'none'; }
    document.getElementById('subjectsModal').addEventListener('click', function(e){ if (e.target === this) closeModal(); });
  </script>
</body>
</html>
