<?php
require_once '../config.php';
requireRole('admin');

// This feature has been removed from System Admin. Subject management is now handled in the Department Admin area.
http_response_code(404);
exit('Not Found');

// Ensure subjects table exists
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS subjects (
        id INT AUTO_INCREMENT PRIMARY KEY,
        department VARCHAR(100) NOT NULL,
        code VARCHAR(50) NOT NULL,
        name VARCHAR(255) NOT NULL,
        UNIQUE KEY unique_subject (department, code)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
} catch (PDOException $e) {
    die('Database initialization error');
}

// Normalize department helper
function normalizeDept($dept) {
    $map = [
        'SOT' => 'Technology',
        'SOB' => 'Business',
        'SOE' => 'Education',
        'School of Technology' => 'Technology',
        'School of Business' => 'Business',
        'School of Education' => 'Education',
    ];
    return $map[$dept] ?? $dept;
}

$departments = ['Technology', 'Business', 'Education'];
$success = null;
$error = null;

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'add_subject') {
            $dept = normalizeDept(trim($_POST['department'] ?? ''));
            $code = trim($_POST['code'] ?? '');
            $name = trim($_POST['name'] ?? '');
            if (!$dept || !$code || !$name) throw new Exception('All fields are required');
            $stmt = $pdo->prepare('INSERT INTO subjects (department, code, name) VALUES (?, ?, ?)');
            $stmt->execute([$dept, $code, $name]);
            $success = 'Subject added successfully';
        } elseif ($action === 'update_subject') {
            $id = (int)($_POST['id'] ?? 0);
            $dept = normalizeDept(trim($_POST['department'] ?? ''));
            $code = trim($_POST['code'] ?? '');
            $name = trim($_POST['name'] ?? '');
            if (!$id || !$dept || !$code || !$name) throw new Exception('All fields are required');
            $stmt = $pdo->prepare('UPDATE subjects SET department = ?, code = ?, name = ? WHERE id = ?');
            $stmt->execute([$dept, $code, $name, $id]);
            $success = 'Subject updated successfully';
        } elseif ($action === 'delete_subject') {
            $id = (int)($_POST['id'] ?? 0);
            if (!$id) throw new Exception('Invalid subject ID');
            $stmt = $pdo->prepare('DELETE FROM subjects WHERE id = ?');
            $stmt->execute([$id]);
            $success = 'Subject deleted successfully';
        }
    } catch (Exception $e) {
        // Handle duplicate subject code per department
        if ($e instanceof PDOException && $e->getCode() == 23000) {
            $error = 'Subject code already exists for this department';
        } else {
            $error = $e->getMessage();
        }
    }
}

$current_dept = normalizeDept($_GET['dept'] ?? '');
if ($current_dept && !in_array($current_dept, $departments)) {
    $current_dept = '';
}

// Fetch subjects
try {
    if ($current_dept) {
        $stmt = $pdo->prepare('SELECT * FROM subjects WHERE department = ? ORDER BY department, name');
        $stmt->execute([$current_dept]);
    } else {
        $stmt = $pdo->query('SELECT * FROM subjects ORDER BY department, name');
    }
    $subjects = $stmt->fetchAll();
} catch (PDOException $e) {
    $subjects = [];
    $error = 'Failed to fetch subjects';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Subjects</title>
    <link rel="stylesheet" href="../styles.css">
    <style>
        .container { max-width: 1100px; margin: 24px auto; padding: 0 16px; }
        .card { background:#fff; border-radius:12px; box-shadow: var(--card-shadow); margin-bottom: 20px; }
        .card-header { padding: 18px 22px; border-bottom:1px solid #e5e7eb; display:flex; justify-content:space-between; align-items:center; }
        .card-body { padding: 18px 22px; }
        .grid-2 { display:grid; grid-template-columns: 1fr 2fr; gap:20px; }
        .form-group { margin-bottom: 12px; }
        .form-group label { display:block; font-weight:600; margin-bottom:6px; }
        .input, select { width:100%; padding:10px; border:2px solid #e5e7eb; border-radius:8px; }
        .btn { background: var(--secondary-color); color:#fff; padding:10px 16px; border:none; border-radius:8px; cursor:pointer; }
        .btn-danger { background: var(--danger-color); }
        .btn-outline { background:#fff; color:var(--secondary-color); border:2px solid var(--secondary-color); }
        table { width:100%; border-collapse:collapse; }
        th, td { padding:10px; border-bottom:1px solid #e5e7eb; text-align:left; }
        th { background:#f8fafc; }
        .toolbar { display:flex; gap:10px; align-items:center; }
        .badge { display:inline-block; padding:4px 10px; border-radius:999px; background:#eef2f7; border:1px solid #e5e7eb; font-size:12px; }
        .sidebar { width: 220px; background:#fff; border-right:1px solid #e5e7eb; }
        .layout { display:flex; }
        .main { flex:1; background:#f5f7fb; }
    </style>
</head>
<body>
<div class="dashboard">
    <div class="sidebar">
        <h2>Admin Portal</h2>
        <a href="admin.php">System Overview</a>
        <a href="admin.php#users" onclick="event.preventDefault(); window.location='admin.php';">User Management</a>
        <a href="admin.php#criteria" onclick="event.preventDefault(); window.location='admin.php';">Evaluation Criteria</a>
        <a href="manage_subjects.php" class="active">Manage Subjects</a>
        <a href="#" onclick="logout()">Logout</a>
    </div>
    <div class="main-content">
        <div class="container">
            <?php if ($success): ?>
                <div class="success-message"><?php echo htmlspecialchars($success); ?></div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="error-message"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <div class="card">
                <div class="card-header">
                    <div class="toolbar">
                        <h3 style="margin:0;">Subjects Management</h3>
                        <?php if ($current_dept): ?>
                            <span class="badge">Department: <?php echo htmlspecialchars($current_dept); ?></span>
                        <?php endif; ?>
                    </div>
                    <form method="get" class="toolbar" style="margin:0;">
                        <select name="dept" onchange="this.form.submit()">
                            <option value="">All Departments</option>
                            <?php foreach ($departments as $d): ?>
                                <option value="<?php echo $d; ?>" <?php echo $current_dept===$d?'selected':''; ?>><?php echo $d; ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?php if ($current_dept): ?>
                            <a class="btn btn-outline" href="manage_subjects.php">Clear</a>
                        <?php endif; ?>
                    </form>
                </div>
                <div class="card-body grid-2">
                    <div>
                        <h4>Add New Subject</h4>
                        <form method="post">
                            <input type="hidden" name="action" value="add_subject">
                            <div class="form-group">
                                <label>Department</label>
                                <select name="department" class="input" required>
                                    <option value="">-- Select Department --</option>
                                    <?php foreach ($departments as $d): ?>
                                        <option value="<?php echo $d; ?>" <?php echo $current_dept===$d?'selected':''; ?>><?php echo $d; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Subject Code</label>
                                <input type="text" name="code" class="input" placeholder="e.g., IT101" required>
                            </div>
                            <div class="form-group">
                                <label>Subject Name</label>
                                <input type="text" name="name" class="input" placeholder="e.g., Introduction to IT" required>
                            </div>
                            <button class="btn" type="submit">Add Subject</button>
                        </form>
                    </div>
                    <div>
                        <h4>Subjects List</h4>
                        <?php if (empty($subjects)): ?>
                            <p>No subjects found.</p>
                        <?php else: ?>
                            <table>
                                <thead>
                                    <tr>
                                        <th>Department</th>
                                        <th>Code</th>
                                        <th>Name</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($subjects as $s): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($s['department']); ?></td>
                                            <td><?php echo htmlspecialchars($s['code']); ?></td>
                                            <td><?php echo htmlspecialchars($s['name']); ?></td>
                                            <td>
                                                <details>
                                                    <summary>Edit</summary>
                                                    <form method="post" style="margin-top:8px;">
                                                        <input type="hidden" name="action" value="update_subject">
                                                        <input type="hidden" name="id" value="<?php echo $s['id']; ?>">
                                                        <div class="form-group">
                                                            <label>Department</label>
                                                            <select name="department" class="input" required>
                                                                <?php foreach ($departments as $d): ?>
                                                                    <option value="<?php echo $d; ?>" <?php echo $s['department']===$d?'selected':''; ?>><?php echo $d; ?></option>
                                                                <?php endforeach; ?>
                                                            </select>
                                                        </div>
                                                        <div class="form-group">
                                                            <label>Code</label>
                                                            <input type="text" name="code" class="input" value="<?php echo htmlspecialchars($s['code']); ?>" required>
                                                        </div>
                                                        <div class="form-group">
                                                            <label>Name</label>
                                                            <input type="text" name="name" class="input" value="<?php echo htmlspecialchars($s['name']); ?>" required>
                                                        </div>
                                                        <button class="btn" type="submit">Save</button>
                                                    </form>
                                                </details>
                                                <form method="post" onsubmit="return confirm('Delete this subject?');" style="display:inline-block; margin-left:8px;">
                                                    <input type="hidden" name="action" value="delete_subject">
                                                    <input type="hidden" name="id" value="<?php echo $s['id']; ?>">
                                                    <button class="btn btn-danger" type="submit">Delete</button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<script>
function logout() {
    fetch('../auth.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: 'action=logout' })
      .then(r => r.json()).then(d => { if (d.success) { window.location.href = d.redirect; } else { window.location.href='../index.php'; } })
      .catch(() => window.location.href='../index.php');
}
</script>
</body>
</html>
