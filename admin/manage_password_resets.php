<?php
require_once __DIR__ . '/../config.php';

// Access control
requireRole('admin');

// Ensure table exists (safety)
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS password_reset_requests (
        id INT AUTO_INCREMENT PRIMARY KEY,
        identifier VARCHAR(50) NOT NULL,
        role ENUM('Student','Faculty','Dean') NOT NULL,
        status ENUM('Pending','Resolved') DEFAULT 'Pending',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
} catch (PDOException $e) { /* ignore */ }

// Backward compatibility: add role column if table was created previously without it
try {
    $colCheck = $pdo->prepare("SELECT COUNT(*) AS c FROM information_schema.columns WHERE table_schema = ? AND table_name = 'password_reset_requests' AND column_name = 'role'");
    $colCheck->execute([DB_NAME]);
    $hasRole = (int)($colCheck->fetch()['c'] ?? 0) > 0;
    if (!$hasRole) {
        // Add as NULL-able to avoid failing on existing rows; new inserts should provide a role
        $pdo->exec("ALTER TABLE password_reset_requests ADD COLUMN role ENUM('Student','Faculty','Dean') NULL AFTER identifier");
        // Optional: set a default status if missing (defensive)
    }
} catch (PDOException $e) { /* ignore */ }

// Fetch requests joined with users to get user_id and full_name via role-specific identifiers
try {
    $sql = "
        SELECT pr.id AS request_id, pr.identifier, pr.role,
               pr.status, pr.created_at,
               u.id AS user_id, u.full_name
        FROM password_reset_requests pr
        LEFT JOIN students s   ON (pr.role = 'Student' AND s.student_id = pr.identifier)
        LEFT JOIN faculty f    ON (pr.role = 'Faculty' AND f.employee_id = pr.identifier)
        LEFT JOIN deans d      ON (pr.role = 'Dean'    AND d.employee_id = pr.identifier)
        LEFT JOIN users u      ON (
             (pr.role = 'Student' AND u.id = s.user_id)
          OR (pr.role = 'Faculty' AND u.id = f.user_id)
          OR (pr.role = 'Dean'    AND u.id = d.user_id)
        )
        ORDER BY pr.status ASC, pr.created_at DESC
    ";
    $stmt = $pdo->query($sql);
    $requests = $stmt->fetchAll();
} catch (PDOException $e) {
    $requests = [];
    $admin_error = 'Database error while fetching requests: ' . $e->getMessage();
}

$csrf = generateCSRFToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Password Reset Requests</title>
  <link rel="stylesheet" href="../styles.css">
  <style>
    body { background: var(--bg-color); }
    .page-wrap { max-width: 1200px; margin: 2rem auto; padding: 0 1rem; }
    .header { display:flex; justify-content: space-between; align-items:center; margin-bottom:1rem; }
    .back-link { text-decoration:none; color: var(--dark-color); }

    /* Card wrapper consistent with admin modules */
    .management-section { background:#fff; padding: 1.25rem; border-radius: 12px; box-shadow: var(--card-shadow); }
    .filters { display:flex; gap:.75rem; flex-wrap: wrap; margin-bottom: 1rem; }
    .filters input, .filters select { padding:.6rem .8rem; border:2px solid #e1e5e9; border-radius:8px; background:#fff; }

    /* Table styles consistent with users-table */
    .users-table { width:100%; border-collapse: collapse; }
    .users-table th, .users-table td { padding: 0.85rem 1rem; text-align:left; border-bottom:1px solid #e1e5e9; }
    .users-table th { background: var(--bg-color); font-weight:600; }

    .role-badge { padding: 0.25rem 0.65rem; border-radius: 16px; font-size:.85rem; font-weight:600; color:#fff; }
    .role-Student { background: var(--primary-color); }
    .role-Faculty { background: var(--secondary-color); }
    .role-Dean { background: var(--warning-color); }

    .status-badge { padding:.25rem .6rem; border-radius: 16px; font-size:.85rem; font-weight:600; }
    .status-Pending { background:#fff4e5; color:#8a5a00; }
    .status-Resolved { background:#e7f8ef; color:#0b6b3a; }

    .actions { display:flex; gap:.5rem; flex-wrap: wrap; }
    .btn { padding:0.45rem 0.75rem; border:none; border-radius:8px; cursor:pointer; font-weight:600; transition: var(--transition); }
    .btn:disabled { opacity:.6; cursor:not-allowed; }
    .btn-green { background: var(--primary-color); color:#fff; }
    .btn-green:hover { background: var(--primary-dark); }
    .btn-red { background: var(--danger-color); color:#fff; }
    .btn-red:hover { background: var(--danger-dark); }
    .btn-gray { background:#e5e7eb; color:#111; }

    /* Responsive table */
    .table-wrap { width:100%; overflow-x:auto; }
  </style>
</head>
  <body>
    <div class="dashboard">
      <div class="sidebar">
        <h2>Admin Portal</h2>
        <a href="admin.php#overview">System Overview</a>
        <a href="admin.php#users">User Management</a>
        <a href="manage_faculty.php">Manage Faculty</a>
        <a href="admin.php#bulk_upload">Bulk Upload</a>
        <a href="admin.php#criteria">Evaluation Criteria</a>
        <a href="admin.php#reports">System Reports</a>
        <a href="admin.php#eval_schedule">Manage Evaluation Schedule</a>
        <a href="manage_password_resets.php" style="background: var(--primary-color); color:#fff;">Password Reset Requests</a>
        <form id="logoutForm" action="../auth.php" method="POST" style="margin: 0;">
            <input type="hidden" name="action" value="logout">
            <button type="submit" class="logout-btn">Logout</button>
        </form>
      </div>

      <div class="main-content">
        <div class="header">
          <h2>Password Reset Requests</h2>
        </div>

        <?php if (isset($_GET['reset']) && $_GET['reset'] === 'invalid'): ?>
          <div class="error-message" style="display:block; margin-bottom:1rem;">Invalid reset request. Please try again.</div>
        <?php endif; ?>
        <?php if (isset($_GET['reset']) && $_GET['reset'] === 'error'): ?>
          <div class="error-message" style="display:block; margin-bottom:1rem;">Failed to reset password. Please try again or check the database connection.</div>
        <?php endif; ?>
        <?php if (isset($_GET['reset']) && $_GET['reset'] === 'success'): ?>
          <div class="success-message" style="display:block; margin-bottom:1rem;">Password has been reset successfully! Default password is 123.</div>
        <?php endif; ?>

        <div class="management-section">
          <div class="filters">
          <input type="text" id="searchBox" placeholder="Search by name, identifier or user ID..." />
          <select id="roleFilter">
            <option value="">All Roles</option>
            <option>Student</option>
            <option>Faculty</option>
            <option>Dean</option>
          </select>
          <select id="statusFilter">
            <option value="">All Status</option>
            <option>Pending</option>
            <option>Resolved</option>
          </select>
          <button class="btn btn-gray" onclick="window.location.reload()">Refresh</button>
          </div>

          <div class="table-wrap">
            <table class="users-table" id="resetTable">
              <thead>
                <tr>
                  <th>User ID</th>
                  <th>Full Name</th>
                  <th>Role</th>
                  <th>Request Date</th>
                  <th>Status</th>
                  <th>Action</th>
                </tr>
              </thead>
              <tbody>
                <?php if (isset($admin_error)): ?>
                  <tr><td colspan="7">Error: <?php echo htmlspecialchars($admin_error); ?></td></tr>
                <?php endif; ?>
                <?php if (!$requests): ?>
                  <tr><td colspan="7" style="text-align:center; color:#6b7280;">No requests found</td></tr>
                <?php else: ?>
                  <?php foreach ($requests as $r): ?>
                    <tr data-role="<?php echo htmlspecialchars($r['role']); ?>" data-status="<?php echo htmlspecialchars($r['status']); ?>">
                      <td><?php echo $r['user_id'] ? (int)$r['user_id'] : '—'; ?></td>
                      <td><?php echo $r['full_name'] ? htmlspecialchars($r['full_name']) : 'Unknown'; ?></td>
                      <td>
                        <span class="role-badge role-<?php echo htmlspecialchars($r['role']); ?>"><?php echo htmlspecialchars($r['role']); ?></span>
                      </td>
                      <td><?php echo htmlspecialchars(date('Y-m-d H:i', strtotime($r['created_at']))); ?></td>
                      <td><span class="status-badge status-<?php echo htmlspecialchars($r['status']); ?>"><?php echo htmlspecialchars($r['status']); ?></span></td>
                      <td>
                        <div class="actions">
                        <form method="POST" action="reset_password.php" onsubmit="return confirm('Are you sure you want to reset this password?');">
                          <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                          <input type="hidden" name="request_id" value="<?php echo (int)$r['request_id']; ?>">
                          <input type="hidden" name="identifier" value="<?php echo htmlspecialchars($r['identifier']); ?>">
                          <input type="hidden" name="role" value="<?php echo htmlspecialchars($r['role']); ?>">
                          <button class="btn btn-green" <?php echo $r['status']==='Resolved' ? 'disabled' : ''; ?> type="submit">Approve & Reset</button>
                        </form>
                        <form method="POST" action="mark_resolved.php" onsubmit="return confirm('Mark this request as resolved?');">
                          <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                          <input type="hidden" name="request_id" value="<?php echo (int)$r['request_id']; ?>">
                          <button class="btn btn-green" <?php echo $r['status']==='Resolved' ? 'disabled' : ''; ?> type="submit">Mark Resolved</button>
                        </form>
                        <form method="POST" action="delete_reset_request.php">
                          <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                          <input type="hidden" name="request_id" value="<?php echo (int)$r['request_id']; ?>">
                          <button class="btn btn-red" type="submit">Remove</button>
                        </form>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>

    <!-- Success Modal -->
    <div id="successModal" class="modal" style="display:none; position:fixed; left:0; top:0; width:100%; height:100%; background:rgba(0,0,0,.5); z-index:1000;">
      <div class="modal-content" style="background:#fff; margin:10% auto; padding:1.5rem; border-radius:12px; width:90%; max-width:420px; text-align:center; box-shadow: var(--card-shadow-hover);">
        <h3 style="margin-bottom:.5rem;">Password Reset</h3>
        <p style="color:#374151; margin-bottom:1rem;">Password has been reset successfully! Default password is 123.</p>
        <button id="modalCloseBtn" class="btn btn-green">OK</button>
      </div>
    </div>

    <script>
      // Client-side filtering/search
      (function(){
        const q = document.getElementById('searchBox');
        const role = document.getElementById('roleFilter');
        const status = document.getElementById('statusFilter');
        const tbody = document.querySelector('#resetTable tbody');

        function applyFilters(){
          const term = (q.value || '').toLowerCase();
          const rf = role.value;
          const sf = status.value;
          Array.from(tbody.querySelectorAll('tr')).forEach(tr => {
            const text = tr.textContent.toLowerCase();
            const matchTerm = !term || text.includes(term);
            const matchRole = !rf || tr.getAttribute('data-role') === rf;
            const matchStatus = !sf || tr.getAttribute('data-status') === sf;
            tr.style.display = (matchTerm && matchRole && matchStatus) ? '' : 'none';
          });
        }

        ['input','change'].forEach(evt => {
          q.addEventListener(evt, applyFilters);
          role.addEventListener(evt, applyFilters);
          status.addEventListener(evt, applyFilters);
        });
      })();

      // Success modal if redirected with ?reset=success
      (function(){
        const params = new URLSearchParams(window.location.search);
        if (params.get('reset') === 'success') {
          const m = document.getElementById('successModal');
          const b = document.getElementById('modalCloseBtn');
          m.style.display = 'block';
          // On OK, reload to reflect updated request status and clear query param
          b.addEventListener('click', ()=>{
            window.location.href = 'manage_password_resets.php';
          });
          m.addEventListener('click', (e)=>{ if(e.target===m) m.style.display='none'; });
          // Safety: auto-refresh after 1.5s if user doesn't click OK
          setTimeout(()=>{
            if (m.style.display === 'block') {
              window.location.href = 'manage_password_resets.php';
            }
          }, 1500);
        }
      })();
    </script>
  </body>
  </html>
