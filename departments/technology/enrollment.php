<?php
require_once '../../config.php';
require_once '../../catalog.php';
requireRole('admin');

// Ensure this is Technology department admin
if ($_SESSION['department'] !== 'Technology') {
    header('Location: ../../dashboard.php');
    exit();
}

// Get current admin's department
$admin_department = $_SESSION['department'];
$admin_name = $_SESSION['full_name'] ?? $_SESSION['username'];

// Diagnostics: check storage engine
try {
    $engines = [];
    foreach (['users','students','student_faculty_subjects'] as $tbl) {
        $stmt = $pdo->query("SHOW TABLE STATUS LIKE '" . str_replace("'","''", $tbl) . "'");
        $info = $stmt ? $stmt->fetch() : null;
        if ($info) { $engines[$tbl] = $info['Engine'] ?? ''; }
    }
    $non_innodb_tables = array_keys(array_filter($engines, function($e){ return $e && strtolower($e) !== 'innodb'; }));
    if (!empty($non_innodb_tables)) {
        $engine_warning = 'Warning: The following tables are not InnoDB and will disable transactions: ' . implode(', ', $non_innodb_tables) . '. Please convert them to InnoDB.';
    }
} catch (PDOException $e) { /* ignore diagnostics */ }

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'enroll_student') {
        // Username will be auto-set to the generated Student ID
        $password = $_POST['password'] ?? '';
        $full_name = sanitizeInput($_POST['full_name'] ?? '');
        $email = sanitizeInput($_POST['email'] ?? '');
        // Gender-based auto ID generation
        $gender = sanitizeInput($_POST['gender'] ?? '');
        $year_level = sanitizeInput($_POST['year_level'] ?? '');
        $program = sanitizeInput($_POST['program'] ?? '');
        // Restrict scope: Department Admin can only operate within their own department
        $selected_department = $admin_department;
        // Multi-assignments: list of selected faculty and JSON of subjects per faculty
        $assigned_faculty_ids = $_POST['assigned_faculty_user_ids'] ?? [];
        if (!is_array($assigned_faculty_ids)) { $assigned_faculty_ids = []; }
        $assigned_faculty_ids = array_values(array_filter(array_map('intval', $assigned_faculty_ids), function($v){ return $v > 0; }));
        $faculty_subjects_json = $_POST['faculty_subjects_payload'] ?? '';
        $faculty_subjects_map = [];
        if ($faculty_subjects_json) {
            $decoded = json_decode($faculty_subjects_json, true);
            if (is_array($decoded)) { $faculty_subjects_map = $decoded; }
        }
        
        // Username is no longer provided manually; it will be set to Student ID
        if (empty($password) || empty($full_name) || empty($gender)) {
            $error = 'All required fields must be filled';
        } else {
            // Validate full name: allow letters, spaces, period (.), hyphen (-), comma (,), and apostrophe ('); must contain at least one letter
            if (!preg_match('/^(?=.*[A-Za-z])[A-Za-z .,\'-]+$/', $full_name)) {
                $error = "Full Name may include letters, spaces, period (.), hyphen (-), comma (,), and apostrophe (').";
            }
            // Validate assigned faculty belongs to the selected department (if provided)
            if (!empty($assigned_faculty_ids)) {
                try {
                    $placeholders = implode(',', array_fill(0, count($assigned_faculty_ids), '?'));
                    $facChk = $pdo->prepare("SELECT id, department FROM users WHERE role = 'faculty' AND id IN ($placeholders)");
                    $facChk->execute($assigned_faculty_ids);
                    $rows = $facChk->fetchAll();
                    $validIds = [];
                    foreach ($rows as $r) {
                        if (($r['department'] ?? '') === $selected_department) { $validIds[] = (int)$r['id']; }
                    }
                    foreach ($assigned_faculty_ids as $fid) {
                        if (!in_array($fid, $validIds, true)) { $error = 'All assigned faculty must be from the selected department.'; break; }
                    }
                } catch (PDOException $e) {
                    $error = 'Validation error. Please try again.';
                }
            }

            if (!isset($error)) {
                try {
                    // Ensure students table has gender column (best-effort)
                    try {
                        $pdo->exec("ALTER TABLE students ADD COLUMN gender ENUM('Male','Female') NULL AFTER user_id");
                    } catch (PDOException $e2) { /* likely exists, ignore */ }

                    // Helper to generate next Student ID by gender
                    $generateStudentId = function(PDO $pdo, string $gender): string {
                        $prefix = ($gender === 'Male') ? '222' : '221';
                        $stmt = $pdo->prepare("SELECT MAX(CAST(RIGHT(student_id, 3) AS UNSIGNED)) AS max_seq FROM students WHERE student_id LIKE CONCAT(?, '-%')");
                        $stmt->execute([$prefix]);
                        $row = $stmt->fetch();
                        $next = (int)($row['max_seq'] ?? 0) + 1;
                        return sprintf('%s-%03d', $prefix, $next);
                    };

                    // Ensure junction table exists BEFORE starting the transaction (DDL causes implicit commits in MySQL)
                    $pdo->exec("CREATE TABLE IF NOT EXISTS student_faculty_subjects (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        student_user_id INT NOT NULL,
                        faculty_user_id INT NOT NULL,
                        subject_code VARCHAR(50) DEFAULT NULL,
                        subject_name VARCHAR(255) NOT NULL,
                        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        UNIQUE KEY uniq_assignment (student_user_id, faculty_user_id, subject_code, subject_name),
                        INDEX idx_student (student_user_id),
                        INDEX idx_faculty (faculty_user_id)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

                    $pdo->beginTransaction();

                    // Generate Student ID first so it can be used as the username
                    $new_student_id = $generateStudentId($pdo, $gender);

                    // Hash password
                    $hashed_password = password_hash($password, PASSWORD_DEFAULT);

                    // Insert user with username equal to Student ID
                    $stmt = $pdo->prepare("INSERT INTO users (username, password, role, full_name, email, department) VALUES (?, ?, 'student', ?, ?, ?)");
                    $stmt->execute([$new_student_id, $hashed_password, $full_name, $email, $selected_department]);
                    $user_id = $pdo->lastInsertId();

                    // Insert student record using the same Student ID
                    $stmt = $pdo->prepare("INSERT INTO students (user_id, student_id, year_level, program, gender) VALUES (?, ?, ?, ?, ?)");
                    $stmt->execute([$user_id, $new_student_id, $year_level, $program, $gender]);

                    // Junction table creation already ensured before transaction

                    // Insert assignments (faculty and selected subjects). If no subjects provided for a faculty, store a placeholder row per faculty without subject_name? We'll skip unless subjects are provided.
                    if (!empty($assigned_faculty_ids)) {
                        foreach ($assigned_faculty_ids as $fid) {
                            $subjects = isset($faculty_subjects_map[$fid]) && is_array($faculty_subjects_map[$fid]) ? $faculty_subjects_map[$fid] : [];
                            if (empty($subjects)) {
                                // fallback: fetch subjects of faculty and assign none (skip)
                                continue;
                            }
                            $ins = $pdo->prepare("INSERT IGNORE INTO student_faculty_subjects (student_user_id, faculty_user_id, subject_code, subject_name) VALUES (?, ?, ?, ?)");
                            foreach ($subjects as $sub) {
                                $code = isset($sub['code']) ? substr((string)$sub['code'], 0, 50) : null;
                                $name = isset($sub['name']) ? substr((string)$sub['name'], 0, 255) : '';
                                if ($name === '') continue;
                                $ins->execute([$user_id, $fid, $code, $name]);
                            }
                        }
                    }

                    if ($pdo && $pdo->inTransaction()) {
                        $pdo->commit();
                    }
                    // PRG: Redirect to avoid duplicate resubmission on refresh
                    header('Location: enrollment.php?success=1&sid=' . urlencode($new_student_id));
                    exit();
                } catch (PDOException $e) {
                    // Rollback only if a transaction is active to avoid 'There is no active transaction'
                    if ($pdo && $pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    if ($e->getCode() == 23000) {
                        $error = 'Conflict while creating user/student ID. Please try again.';
                    } else {
                        $error = 'Database error: ' . $e->getMessage();
                    }
                }
            }
        }
    }
}

// Surface success message from PRG redirect
if (isset($_GET['success']) && $_GET['success'] == '1') {
    $sid = isset($_GET['sid']) ? sanitizeInput($_GET['sid']) : '';
    $success = 'Student successfully enrolled! Assigned ID: ' . htmlspecialchars($sid);
}

// Get students in Technology department ONLY
try {
    $stmt = $pdo->prepare("SELECT u.*, s.student_id, s.year_level, s.program 
                           FROM users u 
                           JOIN students s ON u.id = s.user_id 
                           WHERE u.role = 'student' AND u.department = 'Technology' 
                           ORDER BY u.created_at DESC");
    $stmt->execute();
    $students = $stmt->fetchAll();
} catch (PDOException $e) {
    $students = [];
    $error = 'Error fetching students: ' . $e->getMessage();
}

// Close DB connection after all queries in this request are complete
$pdo = null;

// Technology-specific programs (SOT) from centralized catalog
$tech_programs = $PROGRAMS_BY_DEPT['Technology'] ?? [];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Technology Department - Student Enrollment</title>
    <link rel="stylesheet" href="../../styles.css">
    <link rel="stylesheet" href="technology-theme.css">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        .header-media { position: absolute; right: 20px; top: 20px; width: 110px; height: 110px; opacity: 0.18; pointer-events: none; }
        .header-media img { width: 100%; height: 100%; object-fit: contain; filter: drop-shadow(0 6px 16px rgba(0,0,0,0.2)); }
        @media (max-width: 768px) { .header-media { display: none; } }

        /* Light neutral theme overrides */
        body { font-family: Inter, system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif; }
        .tech-dashboard { background: #f5f7fb; padding: 24px; }
        .tech-header { background: #ffffff; box-shadow: 0 10px 24px rgba(0,0,0,0.06); border-radius: 20px; }
        .tech-card { background: #ffffff; box-shadow: 0 10px 24px rgba(0,0,0,0.06); border-radius: 16px; }
        .tech-table-header { padding: 24px; }

        /* Slim sidebar to avoid double navigation */
        .dashboard .sidebar { width: 200px; background: #ffffff; color: #374151; border-right: 1px solid #e5e7eb; }
        .dashboard .sidebar a { color: #4b5563; }
        .dashboard .sidebar a:hover { background: #f3f4f6; color: #111827; }
        .dashboard .main-content { background: #f5f7fb; }
    </style>
</head>
<body>
    <div class="dashboard">
        <!-- Department Sidebar -->
        <div class="sidebar">
            <h2>Technology Admin</h2>
            <a href="../../department_dashboard.php"><i class="fas fa-gauge-high"></i> Dashboard</a>
            <a href="enrollment.php"><i class="fas fa-user-plus"></i> Enroll Student</a>
            <a href="../../bulk_upload.php"><i class="fas fa-file-upload"></i> Bulk Upload</a>
            <a href="student_management.php"><i class="fas fa-users-cog"></i> Manage Students</a>
            <a href="faculty_management.php"><i class="fas fa-chalkboard-teacher"></i> Manage Faculty</a>
            <a href="../../reports/department_report.php?dept=Technology" target="_blank"><i class="fas fa-chart-bar"></i> Department Report</a>
            <button class="logout-btn" onclick="logout()"><i class="fas fa-sign-out-alt"></i> Logout</button>
        </div>
        <div class="main-content">
    <div class="tech-dashboard">
        <div class="dashboard-container" style="max-width: 1400px; margin: 0 auto; padding: 20px;">
            
            <!-- Technology Header -->
            <div class="tech-header tech-animate" style="position: relative; overflow: hidden;">
                <div style="display: flex; justify-content: space-between; align-items: center;">
                    <div style="display: flex; align-items: center; gap: 20px;">
                        <div class="tech-icon">
                            <i class="fas fa-laptop-code"></i>
                        </div>
                        <div>
                            <h1 style="margin: 0; font-size: 2.5rem; font-weight: 700;">Technology Department</h1>
                            <p style="margin: 5px 0 0 0; opacity: 0.9; font-size: 1.1rem;">Student Enrollment Management</p>
                        </div>
                    </div>
                    <!-- Removed top action to avoid duplicate navigation; use left sidebar -->
                </div>
                <div class="header-media">
                    <img src="../../assets/department-hero.svg" alt="Department visual" loading="lazy">
                </div>
            </div>

            <?php if (isset($success)): ?>
                <div class="tech-card tech-animate" style="background: linear-gradient(135deg, #00ccff, #0099ff); color: white; padding: 20px; margin-bottom: 20px;">
                    <i class="fas fa-check-circle" style="margin-right: 10px;"></i>
                    <?php echo htmlspecialchars($success); ?>
                </div>
            <?php endif; ?>

            <?php if (isset($error)): ?>
                <div class="tech-card tech-animate" style="background: linear-gradient(135deg, #ff6600, #ff3300); color: white; padding: 20px; margin-bottom: 20px;">
                    <i class="fas fa-exclamation-triangle" style="margin-right: 10px;"></i>
                    <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>

            <!-- Enrollment Form -->
            <div class="tech-card tech-animate" style="margin-bottom: 30px;">
                <div style="padding: 40px;">
                    <div style="display: flex; align-items: center; gap: 15px; margin-bottom: 30px;">
                        <div class="tech-icon" style="width: 50px; height: 50px; font-size: 20px;">
                            <i class="fas fa-user-plus"></i>
                        </div>
                        <h2 style="margin: 0; color: var(--tech-primary); font-size: 1.8rem;">Enroll New Technology Student</h2>
                    </div>
                    
                    <form method="POST" style="display: grid; gap: 25px;">
                        <input type="hidden" name="action" value="enroll_student">
                        
                        <div>
                            <label style="display: block; margin-bottom: 8px; font-weight: 600; color: var(--tech-dark);">Password *</label>
                            <input type="password" name="password" class="tech-input" required>
                        </div>

                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                            <div>
                                <label style="display: block; margin-bottom: 8px; font-weight: 600; color: var(--tech-dark);">Full Name *</label>
                                <input type="text" name="full_name" class="tech-input" required pattern="(?=.*[A-Za-z])[A-Za-z .,'-]+" title="Use letters, spaces, period (.), hyphen (-), comma (,), and apostrophe (')">
                            </div>
                            <div>
                                <label style="display: block; margin-bottom: 8px; font-weight: 600; color: var(--tech-dark);">Email</label>
                                <input type="email" name="email" class="tech-input">
                            </div>
                        </div>

                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                            <div>
                                <label style="display: block; margin-bottom: 8px; font-weight: 600; color: var(--tech-dark);">Gender *</label>
                                <select name="gender" class="tech-input" required>
                                    <option value="">Select Gender</option>
                                    <option value="Male">Male</option>
                                    <option value="Female">Female</option>
                                </select>
                                <small style="color:#6b7280; display:block; margin-top:6px;">Student ID will be auto-generated (222-XXX for Male, 221-XXX for Female)</small>
                            </div>
                            <div>
                                <label style="display: block; margin-bottom: 8px; font-weight: 600; color: var(--tech-dark);">Year Level</label>
                                <select name="year_level" class="tech-input">
                                    <option value="">Select Year Level</option>
                                    <option value="1st Year">1st Year</option>
                                    <option value="2nd Year">2nd Year</option>
                                    <option value="3rd Year">3rd Year</option>
                                    <option value="4th Year">4th Year</option>
                                </select>
                            </div>
                        </div>

                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                            <div>
                                <label style="display: block; margin-bottom: 8px; font-weight: 600; color: var(--tech-dark);">Department *</label>
                                <select name="department" id="department_select" class="tech-input" required>
                                    <option value="Technology" selected><?php echo htmlspecialchars($DEPT_LABELS['Technology']); ?></option>
                                </select>
                            </div>
                            <div>
                                <label style="display: block; margin-bottom: 8px; font-weight: 600; color: var(--tech-dark);">Assigned Faculty (Multiple)</label>
                                <select name="assigned_faculty_user_ids[]" id="faculty_select" class="tech-input" multiple size="5">
                                </select>
                                <small style="color:#6b7280;">Hold Ctrl/Cmd to select multiple faculty.</small>
                            </div>
                        </div>

                        <div>
                            <label style="display: block; margin-bottom: 8px; font-weight: 600; color: var(--tech-dark);">Program</label>
                            <select name="program" class="tech-input" id="program_select">
                                <option value="">Select Program</option>
                                <?php foreach ($tech_programs as $program): ?>
                                    <option value="<?php echo htmlspecialchars($program); ?>"><?php echo htmlspecialchars($program); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- Dynamic subjects selection per selected faculty -->
                        <div id="faculty-subjects-container" class="tech-card" style="padding:16px; display:none;">
                            <h3 style="margin-top:0; color: var(--tech-dark);">Select Subjects per Faculty</h3>
                            <div id="faculty-subjects-list" style="display:grid; gap:16px;"></div>
                            <input type="hidden" name="faculty_subjects_payload" id="faculty_subjects_payload" value="{}">
                        </div>

                        <button type="submit" class="tech-btn" style="justify-self: start; font-size: 16px;">
                            <i class="fas fa-plus-circle"></i>
                            Enroll Student
                        </button>
                    </form>
                </div>
            </div>

            <!-- Students Table -->
            <div class="tech-card tech-animate">
                <div class="tech-table-header" style="display: flex; justify-content: space-between; align-items: center;">
                    <div>
                        <h3 style="margin: 0; font-size: 1.5rem;">Technology Department Students</h3>
                        <p style="margin: 5px 0 0 0; opacity: 0.9;">Total Enrolled: <?php echo count($students); ?></p>
                    </div>
                    <div class="tech-icon" style="width: 50px; height: 50px; font-size: 20px;">
                        <i class="fas fa-users"></i>
                    </div>
                </div>
                
                <?php if (empty($students)): ?>
                    <div style="padding: 60px; text-align: center; color: #666;">
                        <i class="fas fa-user-graduate" style="font-size: 48px; color: var(--tech-primary); margin-bottom: 20px;"></i>
                        <h3 style="color: var(--tech-primary); margin-bottom: 10px;">No Students Enrolled Yet</h3>
                        <p>Start by enrolling your first Technology student above.</p>
                    </div>
                <?php else: ?>
                    <div style="overflow-x: auto;">
                        <table class="tech-table" style="width: 100%; border-collapse: collapse;">
                            <thead>
                                <tr>
                                    <th style="text-align: left;">Student ID</th>
                                    <th style="text-align: left;">Full Name</th>
                                    <th style="text-align: left;">Username</th>
                                    <th style="text-align: left;">Email</th>
                                    <th style="text-align: left;">Year Level</th>
                                    <th style="text-align: left;">Program</th>
                                    <th style="text-align: left;">Enrolled Date</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($students as $student): ?>
                                    <tr>
                                        <td><strong><?php echo htmlspecialchars($student['student_id']); ?></strong></td>
                                        <td><?php echo htmlspecialchars($student['full_name']); ?></td>
                                        <td><?php echo htmlspecialchars($student['username']); ?></td>
                                        <td><?php echo htmlspecialchars($student['email'] ?? 'N/A'); ?></td>
                                        <td>
                                            <?php if ($student['year_level']): ?>
                                                <span class="tech-badge" style="font-size: 12px; padding: 4px 12px;">
                                                    <?php echo htmlspecialchars($student['year_level']); ?>
                                                </span>
                                            <?php else: ?>
                                                N/A
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo htmlspecialchars($student['program'] ?? 'N/A'); ?></td>
                                        <td><?php echo date('M d, Y', strtotime($student['created_at'])); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
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
                    if (data && data.success && data.redirect) {
                        window.location.href = data.redirect;
                        return;
                    }
                } catch(_) { /* ignore JSON parse errors */ }
            } catch(_) { /* ignore network errors */ }
            // Fallback to GET redirect
            window.location.href = '../../auth.php?action=logout';
        }
        // Add animation delays for cards
        document.addEventListener('DOMContentLoaded', function() {
            const cards = document.querySelectorAll('.tech-animate');
            cards.forEach((card, index) => {
                card.style.animationDelay = `${index * 0.1}s`;
            });
        });
    </script>
    <script>
        // Dynamic faculty loading based on department selection
        const deptSelect = document.getElementById('department_select');
        const facultySelect = document.getElementById('faculty_select');
        const facSubsContainer = document.getElementById('faculty-subjects-container');
        const facSubsList = document.getElementById('faculty-subjects-list');
        const facSubsPayload = document.getElementById('faculty_subjects_payload');
        async function loadFaculties(dept) {
            if (!dept || !facultySelect) return;
            facultySelect.innerHTML = '';
            try {
                const res = await fetch(`/FPES/api/faculties.php?department=${encodeURIComponent(dept)}`);
                const data = await res.json();
                facultySelect.innerHTML = '';
                if (data.success && Array.isArray(data.data)) {
                    for (const f of data.data) {
                        const opt = document.createElement('option');
                        opt.value = f.user_id;
                        opt.textContent = f.full_name || f.username;
                        facultySelect.appendChild(opt);
                    }
                }
            } catch(e) {
                facultySelect.innerHTML = '';
            }
        }
        if (deptSelect) {
            loadFaculties(deptSelect.value);
            deptSelect.addEventListener('change', e => loadFaculties(e.target.value));
        }

        // Fetch and render subjects for selected faculty
        async function fetchFacultySubjects(facultyUserId) {
            const res = await fetch(`/FPES/api/faculty_subjects.php?faculty_user_id=${encodeURIComponent(facultyUserId)}`);
            const data = await res.json();
            if (data.success && Array.isArray(data.data)) return data.data;
            return [];
        }

        function updatePayloadFromUI() {
            const payload = {};
            facSubsList.querySelectorAll('[data-faculty]').forEach(block => {
                const fid = block.getAttribute('data-faculty');
                const checked = Array.from(block.querySelectorAll('input[type="checkbox"]:checked')).map(cb => ({ code: cb.getAttribute('data-code') || null, name: cb.getAttribute('data-name') }));
                if (checked.length > 0) payload[fid] = checked;
            });
            facSubsPayload.value = JSON.stringify(payload);
        }

        async function renderSelectedFacultySubjects() {
            const selected = Array.from(facultySelect.selectedOptions).map(o => ({ id: o.value, name: o.textContent }));
            facSubsList.innerHTML = '';
            if (selected.length === 0) {
                facSubsContainer.style.display = 'none';
                facSubsPayload.value = '{}';
                return;
            }
            facSubsContainer.style.display = 'block';
            for (const fac of selected) {
                const subjects = await fetchFacultySubjects(fac.id);
                const section = document.createElement('div');
                section.setAttribute('data-faculty', fac.id);
                section.style.border = '1px solid #e5e7eb';
                section.style.borderRadius = '8px';
                section.style.padding = '12px';
                section.innerHTML = `<div style="font-weight:600; margin-bottom:8px;">${fac.name} — Subjects</div>`;
                if (subjects.length === 0) {
                    const p = document.createElement('p');
                    p.textContent = 'No subjects found for this faculty.';
                    p.style.color = '#6b7280';
                    section.appendChild(p);
                } else {
                    const grid = document.createElement('div');
                    grid.style.display = 'grid';
                    grid.style.gridTemplateColumns = 'repeat(auto-fit, minmax(220px, 1fr))';
                    grid.style.gap = '8px 16px';
                    for (const s of subjects) {
                        const id = `sub_${fac.id}_${(s.subject_code||'').replace(/[^a-zA-Z0-9]/g,'')}_${Math.random().toString(36).slice(2,7)}`;
                        const wrap = document.createElement('div');
                        wrap.innerHTML = `
                            <label style="display:flex; align-items:center; gap:8px;">
                                <input type="checkbox" data-code="${s.subject_code ?? ''}" data-name="${s.subject_name}" id="${id}">
                                <span>${(s.subject_code ? s.subject_code + ' - ' : '') + s.subject_name}</span>
                            </label>`;
                        grid.appendChild(wrap);
                    }
                    section.appendChild(grid);
                }
                facSubsList.appendChild(section);
            }
            updatePayloadFromUI();
        }

        if (facultySelect) {
            facultySelect.addEventListener('change', renderSelectedFacultySubjects);
        }
        facSubsList && facSubsList.addEventListener('change', updatePayloadFromUI);
    </script>
</body>
</html>
