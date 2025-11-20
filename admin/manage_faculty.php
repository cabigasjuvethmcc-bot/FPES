<?php
require_once '../config.php';
require_once '../catalog.php';
requireRole('admin');

$admin_name = $_SESSION['full_name'] ?? $_SESSION['username'];

// Handle faculty subject update (System Admin)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'update_faculty_subjects') {
        $faculty_user_id = (int)($_POST['faculty_user_id'] ?? 0);
        $subjects = $_POST['subjects'] ?? [];
        try {
            if ($faculty_user_id <= 0) { throw new Exception('Invalid faculty'); }

            // Figure out the faculty's department
            $deptStmt = $pdo->prepare('SELECT department FROM users WHERE id = ? AND role = "faculty"');
            $deptStmt->execute([$faculty_user_id]);
            $facultyDept = $deptStmt->fetchColumn();
            if (!$facultyDept) { throw new Exception('Faculty not found'); }

            // Ensure table exists
            $pdo->exec("CREATE TABLE IF NOT EXISTS faculty_subjects (
                id INT AUTO_INCREMENT PRIMARY KEY,
                faculty_user_id INT NOT NULL,
                subject_code VARCHAR(50) DEFAULT NULL,
                subject_name VARCHAR(255) NOT NULL,
                UNIQUE KEY uniq_faculty_subject (faculty_user_id, subject_code, subject_name),
                INDEX idx_faculty_user_id (faculty_user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

            // Build allowed set from DB subjects for this faculty's department
            $allowed = [];
            $subStmt = $pdo->prepare('SELECT code, name FROM subjects WHERE department = ?');
            $subStmt->execute([$facultyDept]);
            foreach ($subStmt->fetchAll() as $row) {
                $allowed[$row['code'] . '::' . $row['name']] = true;
            }

            // Begin transaction and replace current subjects
            $pdo->beginTransaction();
            $pdo->prepare('DELETE FROM faculty_subjects WHERE faculty_user_id = ?')->execute([$faculty_user_id]);

            if (is_array($subjects) && !empty($subjects)) {
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

// Load all faculty with department
try {
    $stmt = $pdo->prepare("SELECT u.id, u.username, u.full_name, u.department, f.employee_id
                           FROM users u
                           JOIN faculty f ON u.id = f.user_id
                           WHERE u.role = 'faculty'
                           ORDER BY u.department, u.full_name");
    $stmt->execute();
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
  <title>System Admin - Manage Faculty</title>
  <link rel="stylesheet" href="../styles.css">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
  <style>
    /* Bring in key Admin UI styles so this page matches admin.php */
    .users-table { width: 100%; border-collapse: collapse; margin-top: 1rem; }
    .users-table th, .users-table td { padding: 1rem; text-align: left; border-bottom: 1px solid #e1e5e9; }
    .users-table th { background: var(--bg-color); font-weight: 600; }
    .management-section { background: white; padding: 2rem; border-radius: 12px; box-shadow: var(--card-shadow); margin-top: 1rem; }
    .action-buttons { display: flex; gap: 0.5rem; }
    .btn-edit { background: var(--primary-color); color: #fff; border: none; }
    .btn-delete { background: var(--danger-color); color: #fff; border: none; }
    .submit-btn { background: var(--secondary-color); color: white; padding: 0.75rem 1.25rem; border: none; border-radius: 8px; font-weight: 600; cursor: pointer; transition: var(--transition); }
    .submit-btn:hover { background: var(--secondary-dark); }
    .success-message, .error-message { padding: 1rem; border-radius: 8px; margin: 1rem 0; }
    .success-message { background: var(--secondary-color); color: #fff; }
    .error-message { background: var(--danger-color); color: #fff; }
    /* Modal styles copied to match admin.php */
    .modal { display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.5); }
    .modal-content { background-color: white; margin: 5% auto; padding: 2rem; border-radius: 12px; width: 90%; max-width: 700px; max-height: 80vh; overflow-y: auto; box-shadow: var(--card-shadow); }
    /* Page-specific small tweaks */
    .mf-header { display:flex; justify-content: space-between; align-items:center; gap:1rem; flex-wrap: wrap; }
    .filters { display:flex; gap:.75rem; align-items:center; }
    .filters select, .filters input { padding:.6rem .8rem; border:2px solid #e1e5e9; border-radius:8px; background:#fff; }
  </style>
</head>
<body>
  <button class="sidebar-toggle" id="adminSidebarToggle" aria-label="Toggle navigation">☰ Menu</button>
  <div class="dashboard">
    <div class="sidebar">
      <h2>Admin Portal</h2>
      <a href="admin.php#overview">System Overview</a>
      <a href="admin.php#users">User Management</a>
      <a href="#" class="active">Manage Faculty</a>
      <a href="admin.php#bulk_upload">Bulk Upload</a>
      <a href="admin.php#criteria">Evaluation Criteria</a>
      <a href="admin.php#reports">System Reports</a>
      <a href="admin.php#eval_schedule">Manage Evaluation Schedule</a>
      <a href="manage_password_resets.php">Password Reset Requests</a>
      <button class="logout-btn" onclick="logout()">Logout</button>
    </div>
    <div class="main-content">
      <div class="container" style="max-width:1200px;">
        <div class="mf-header">
          <h1>Manage Faculty</h1>
          <div class="filters">
            <select id="deptFilter">
              <option value="">All Departments</option>
              <option value="Business">Business</option>
              <option value="Education">Education</option>
              <option value="Technology">Technology</option>
            </select>
            <input type="text" id="facultySearch" placeholder="Search by name or username...">
            <button class="submit-btn" id="btnClearFilters" type="button">Clear</button>
          </div>
        </div>

        <?php if (isset($success)): ?><div class="success-message" style="display:block;"><?php echo htmlspecialchars($success); ?></div><?php endif; ?>
        <?php if (isset($error)): ?><div class="error-message" style="display:block;"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>

        <div class="management-section">
          <table class="users-table" id="facultyTable">
            <thead>
              <tr>
                <th>Department</th>
                <th>Employee ID</th>
                <th>Full Name</th>
                <th>Username</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($faculty_list as $f): ?>
              <tr data-dept="<?php echo htmlspecialchars($f['department'] ?? ''); ?>">
                <td><?php echo htmlspecialchars($f['department'] ?? ''); ?></td>
                <td><?php echo htmlspecialchars($f['employee_id'] ?? ''); ?></td>
                <td class="col-name"><?php echo htmlspecialchars($f['full_name'] ?? $f['username']); ?></td>
                <td class="col-username"><?php echo htmlspecialchars($f['username']); ?></td>
                <td>
                  <div class="action-buttons">
                    <button class="btn-small btn-edit" onclick="openEditSubjects(<?php echo (int)$f['id']; ?>, '<?php echo htmlspecialchars($f['full_name'] ?? $f['username'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($f['department'], ENT_QUOTES); ?>')">
                      Edit Subjects
                    </button>
                  </div>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>

  <!-- Modal -->
  <div id="subjectsModal" class="modal" style="display:none;">
    <div class="modal-content">
      <h3 id="modalTitle" style="margin-top:0;">Edit Faculty Subjects</h3>
      <form method="POST" id="subjectsForm">
        <input type="hidden" name="action" value="update_faculty_subjects">
        <input type="hidden" name="faculty_user_id" id="modalFacultyId">
        <div class="form-group">
          <label>Subjects</label>
          <div class="subject-picker">
            <div class="picker-toolbar" style="display:flex; gap:8px; align-items:center; margin:6px 0;">
              <input type="text" id="subjectSearch" placeholder="Search subjects (code or name)" style="flex:1; padding:.6rem .8rem; border:2px solid #e1e5e9; border-radius:8px;">
              <button type="button" class="submit-btn" id="btnSelectAll" style="background: var(--primary-color);">Select All</button>
              <button type="button" class="submit-btn" id="btnClear" style="background: var(--danger-color);">Clear</button>
            </div>
            <select id="modalSubjects" name="subjects[]" multiple size="10" style="height:260px; width:100%; padding:.6rem; border:2px solid #e1e5e9; border-radius:8px;"></select>
            <div id="selectedCount" style="margin-top:6px; font-size:12px; color:#6b7280;">0 selected</div>
          </div>
        </div>
        <div class="action-buttons" style="justify-content:flex-end;">
          <button type="button" class="btn-small" style="background:#6b7280; color:#fff;" onclick="closeModal()">Cancel</button>
          <button type="submit" class="submit-btn">Save</button>
        </div>
      </form>
    </div>
  </div>

  <script>
    (function(){
      var btn = document.getElementById('adminSidebarToggle');
      var sidebar = document.querySelector('.sidebar');
      if (btn && sidebar) {
        btn.addEventListener('click', function(){
          var isActive = sidebar.classList.toggle('active');
          if (isActive) {
            btn.classList.add('open');
          } else {
            btn.classList.remove('open');
          }
        });
        // On small screens, hide sidebar after clicking any menu link
        var links = sidebar.querySelectorAll('a');
        links.forEach(function(link){
          link.addEventListener('click', function(){
            if (window.innerWidth <= 768) {
              sidebar.classList.remove('active');
              btn.classList.remove('open');
            }
          });
        });
      }
    })();

    async function logout() {
      try {
        const res = await fetch('../auth.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: 'action=logout'
        });
        try { const data = await res.json(); if (data && data.success && data.redirect) { window.location.href = data.redirect; return; } } catch(_) {}
      } catch(_) {}
      window.location.href = '../auth.php?action=logout';
    }

    async function fetchSubjectsByDept(dept) {
      try { const r = await fetch(`../api/subjects.php?department=${encodeURIComponent(dept)}`); const d = await r.json(); return (d && d.success && Array.isArray(d.data)) ? d.data : []; } catch { return []; }
    }
    async function fetchFacultySubjects(uid) {
      try { const r = await fetch(`../api/faculty_subjects.php?faculty_user_id=${encodeURIComponent(uid)}`); const d = await r.json(); return (d && d.success && Array.isArray(d.data)) ? d.data : []; } catch { return []; }
    }
    async function openEditSubjects(userId, name, dept) {
      document.getElementById('modalFacultyId').value = userId;
      document.getElementById('modalTitle').textContent = `Edit Subjects for ${name} (${dept})`;
      const sel = document.getElementById('modalSubjects');
      sel.innerHTML = '';
      const [subjects, current] = await Promise.all([fetchSubjectsByDept(dept), fetchFacultySubjects(userId)]);
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

    // Client-side filters (search + department)
    (function(){
      const search = document.getElementById('facultySearch');
      const deptFilter = document.getElementById('deptFilter');
      const clearBtn = document.getElementById('btnClearFilters');
      const tbody = document.querySelector('#facultyTable tbody');
      function apply(){
        const q = (search.value || '').toLowerCase();
        const d = deptFilter.value || '';
        Array.from(tbody.querySelectorAll('tr')).forEach(tr => {
          const dept = tr.getAttribute('data-dept') || '';
          const name = (tr.querySelector('.col-name')?.textContent || '').toLowerCase();
          const user = (tr.querySelector('.col-username')?.textContent || '').toLowerCase();
          const matchesDept = !d || dept === d;
          const matchesText = !q || name.includes(q) || user.includes(q);
          tr.style.display = (matchesDept && matchesText) ? '' : 'none';
        });
      }
      if (search) search.addEventListener('input', apply);
      if (deptFilter) deptFilter.addEventListener('change', apply);
      if (clearBtn) clearBtn.addEventListener('click', () => { search.value = ''; deptFilter.value=''; apply(); });
    })();
  </script>
</body>
</html>
