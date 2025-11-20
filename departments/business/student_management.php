<?php
require_once '../../config.php';
require_once '../../catalog.php';
requireRole('admin');

// Ensure this is Business department admin
if ($_SESSION['department'] !== 'Business') {
    header('Location: ../../dashboard.php');
    exit();
}

$admin_department = $_SESSION['department'];
$admin_name = $_SESSION['full_name'] ?? $_SESSION['username'];

// Handle student actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $student_id = $_POST['student_id'] ?? '';
    
    if ($action === 'update_student' && $student_id) {
        $full_name = sanitizeInput($_POST['full_name'] ?? '');
        $email = sanitizeInput($_POST['email'] ?? '');
        $year_level = sanitizeInput($_POST['year_level'] ?? '');
        $program = sanitizeInput($_POST['program'] ?? '');
        $enrollments_json = $_POST['enrollments_json'] ?? '';
        $enrollments = [];
        if ($enrollments_json) {
            $tmp = json_decode($enrollments_json, true);
            if (is_array($tmp)) { $enrollments = $tmp; }
        }
        
        try {
            // Ensure junction table exists for enrollments (DDL outside transaction to avoid implicit commits)
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

            // Validate Full Name: only ASCII letters and spaces; must contain at least one letter
            if (!preg_match('/^(?=.*[A-Za-z])[A-Za-z ]+$/', $full_name)) {
                throw new PDOException('Full Name must only contain letters and spaces.');
            }

            // Update user info
            $stmt = $pdo->prepare("UPDATE users SET full_name = ?, email = ? WHERE id = ? AND department = 'Business'");
            $stmt->execute([$full_name, $email, $student_id]);
            
            // Update student info
            $stmt = $pdo->prepare("UPDATE students SET year_level = ?, program = ? WHERE user_id = ?");
            $stmt->execute([$year_level, $program, $student_id]);

            // Replace existing enrollments with submitted ones
            $del = $pdo->prepare("DELETE FROM student_faculty_subjects WHERE student_user_id = ?");
            $del->execute([(int)$student_id]);

            if (!empty($enrollments)) {
                $ins = $pdo->prepare("INSERT INTO student_faculty_subjects (student_user_id, faculty_user_id, subject_code, subject_name) VALUES (?, ?, ?, ?)");
                foreach ($enrollments as $en) {
                    $fac_uid = isset($en['faculty_user_id']) ? (int)$en['faculty_user_id'] : 0;
                    $s_code = isset($en['subject_code']) ? sanitizeInput($en['subject_code']) : null;
                    $s_name = isset($en['subject_name']) ? sanitizeInput($en['subject_name']) : '';
                    if ($fac_uid > 0 && $s_name !== '') {
                        $ins->execute([(int)$student_id, $fac_uid, $s_code, $s_name]);
                    }
                }
            }
            
            $pdo->commit();
            $success = 'Student information updated successfully!';
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            $error = 'Error updating student: ' . $e->getMessage();
        }
    }
    
    if ($action === 'delete_student' && $student_id) {
        try {
            $stmt = $pdo->prepare("DELETE FROM users WHERE id = ? AND department = 'Business' AND role = 'student'");
            $stmt->execute([$student_id]);
            // Clean up enrollments
            $pdo->prepare("DELETE FROM student_faculty_subjects WHERE student_user_id = ?")->execute([(int)$student_id]);
            $success = 'Student removed successfully!';
        } catch (PDOException $e) {
            $error = 'Error removing student: ' . $e->getMessage();
        }
    }
}

// Get all Business students with detailed info
try {
    $stmt = $pdo->prepare("SELECT u.*, s.student_id, s.year_level, s.program 
                           FROM users u 
                           LEFT JOIN students s ON u.id = s.user_id 
                           WHERE u.role = 'student' AND u.department = 'Business' 
                           ORDER BY u.full_name");
    $stmt->execute();
    $students = $stmt->fetchAll();
} catch (PDOException $e) {
    $students = [];
    $error = 'Error fetching students: ' . $e->getMessage();
}

// Prefetch current enrollments to prefill modal
$student_ids = array_map(function($s){ return (int)$s['id']; }, $students);
$enrollments_map = [];
if (!empty($student_ids)) {
    try {
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

        $in = implode(',', array_fill(0, count($student_ids), '?'));
        $q = $pdo->prepare("SELECT student_user_id, faculty_user_id, subject_code, subject_name FROM student_faculty_subjects WHERE student_user_id IN ($in)");
        $q->execute($student_ids);
        while ($row = $q->fetch()) {
            $sid = (int)$row['student_user_id'];
            if (!isset($enrollments_map[$sid])) { $enrollments_map[$sid] = []; }
            $enrollments_map[$sid][] = [
                'faculty_user_id' => (int)$row['faculty_user_id'],
                'subject_code' => $row['subject_code'],
                'subject_name' => $row['subject_name'],
            ];
        }
    } catch (PDOException $e) {
        // ignore
    }
}

// Business programs for dropdown from centralized catalog
$business_programs = $PROGRAMS_BY_DEPT['Business'] ?? [];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Business Department - Student Management</title>
    <link rel="stylesheet" href="../../styles.css">
    <link rel="stylesheet" href="business-theme.css">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        /* Light neutral theme */
        body { font-family: Inter, system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif; }
        .business-dashboard { background: #f5f7fb; padding: 24px; }
        .business-header { background: #ffffff; box-shadow: 0 10px 24px rgba(0,0,0,0.06); border-radius: 20px; }
        .business-card { background: #ffffff; box-shadow: 0 10px 24px rgba(0,0,0,0.06); border-radius: 16px; }
        .business-table-header { padding: 24px; }

        /* Slim sidebar */
        .dashboard .sidebar { width: 200px; background: #ffffff; color: #374151; border-right: 1px solid #e5e7eb; }
        .dashboard .sidebar a { color: #4b5563; }
        .dashboard .sidebar a:hover { background: #f3f4f6; color: #111827; }
        .dashboard .main-content { background: #f5f7fb; }
        .header-media { position: absolute; right: 20px; top: 20px; width: 110px; height: 110px; opacity: 0.18; pointer-events: none; }
        .header-media img { width: 100%; height: 100%; object-fit: contain; filter: drop-shadow(0 6px 16px rgba(0,0,0,0.2)); }
        @media (max-width: 768px) { .header-media { display: none; } }
    </style>
</head>
<body>
    <div class="dashboard">
        <!-- Department Sidebar -->
        <div class="sidebar">
            <h2>Business Admin</h2>
            <a href="../../department_dashboard.php"><i class="fas fa-gauge-high"></i> Dashboard</a>
            <a href="enrollment.php"><i class="fas fa-user-plus"></i> Enroll Student</a>
            <a href="../../bulk_upload.php"><i class="fas fa-file-upload"></i> Bulk Upload</a>
            <a href="student_management.php" class="active"><i class="fas fa-users-cog"></i> Manage Students</a>
            <a href="faculty_management.php"><i class="fas fa-chalkboard-teacher"></i> Manage Faculty</a>
            <a href="../../reports/department_report.php?dept=Business" target="_blank"><i class="fas fa-chart-bar"></i> Department Report</a>
            <button class="logout-btn" onclick="logout()"><i class="fas fa-sign-out-alt"></i> Logout</button>
        </div>
        <div class="main-content">
            <div class="business-dashboard">
                <div class="dashboard-container" style="max-width: 1600px; margin: 0 auto; padding: 20px;">
                    
                    <!-- Header -->
                    <div class="business-header business-animate" style="position: relative; overflow: hidden;">
                        <div style="display: flex; justify-content: space-between; align-items: center;">
                            <div style="display: flex; align-items: center; gap: 20px;">
                                <div class="business-icon">
                                    <i class="fas fa-users-cog"></i>
                                </div>
                                <div>
                                    <h1 style="margin: 0; font-size: 2.5rem; font-weight: 700;">Student Management</h1>
                                    <p style="margin: 5px 0 0 0; opacity: 0.9; font-size: 1.1rem;">Business Department</p>
                                </div>
                            </div>
                        </div>
                        <div class="header-media">
                            <img src="../../assets/department-hero.svg" alt="Department visual" loading="lazy">
                        </div>
                    </div>

                    <?php if (isset($success)): ?>
                        <div class="business-success business-animate">
                            <i class="fas fa-check-circle" style="margin-right: 10px;"></i>
                            <?php echo htmlspecialchars($success); ?>
                        </div>
                    <?php endif; ?>

                    <?php if (isset($error)): ?>
                        <div class="business-error business-animate">
                            <i class="fas fa-exclamation-triangle" style="margin-right: 10px;"></i>
                            <?php echo htmlspecialchars($error); ?>
                        </div>
                    <?php endif; ?>

                    <!-- Students Management -->
                    <div class="business-card business-animate">
                        <div class="business-table-header" style="display: flex; justify-content: space-between; align-items: center;">
                            <div>
                                <h3 style="margin: 0; font-size: 1.5rem;">Business Students (<?php echo count($students); ?>)</h3>
                                <p style="margin: 5px 0 0 0; opacity: 0.9;">Manage all Business department students</p>
                            </div>
                            <div class="business-icon" style="width: 50px; height: 50px; font-size: 20px;">
                                <i class="fas fa-briefcase"></i>
                            </div>
                        </div>

                        <!-- Search & Filters -->
                        <div class="business-table-header" style="display:grid; gap:10px; border-top:1px solid #e5e7eb;">
                            <div style="display:grid; grid-template-columns: repeat(4, minmax(160px, 1fr)); gap:10px;">
                                <input id="filter-id" class="business-input" type="text" placeholder="Search Student ID">
                                <input id="filter-name" class="business-input" type="text" placeholder="Search Name">
                                <input id="filter-program" class="business-input" type="text" placeholder="Search Program">
                                <select id="filter-year" class="business-input">
                                    <option value="">All Year Levels</option>
                                    <option value="1st Year">1st Year</option>
                                    <option value="2nd Year">2nd Year</option>
                                    <option value="3rd Year">3rd Year</option>
                                    <option value="4th Year">4th Year</option>
                                </select>
                            </div>
                            <div style="display:flex; gap:10px;">
                                <button id="filters-clear" class="business-btn" type="button" style="background:#6b7280;">Clear</button>
                            </div>
                        </div>
                        
                        <?php if (empty($students)): ?>
                            <div style="padding: 60px; text-align: center; color: #666;">
                                <i class="fas fa-user-tie" style="font-size: 48px; color: var(--business-primary); margin-bottom: 20px;"></i>
                                <h3 style="color: var(--business-primary); margin-bottom: 10px; text-transform: uppercase;">No Students Found</h3>
                                <p>No students are currently enrolled in the Business department.</p>
                                <a href="enrollment.php" class="business-btn" style="margin-top: 20px;">
                                    <i class="fas fa-plus-circle"></i>
                                    Enroll First Student
                                </a>
                            </div>
                        <?php else: ?>
                            <div style="overflow-x: auto;">
                                <table id="students-table" class="business-table" style="width: 100%; border-collapse: collapse;">
                                    <thead>
                                        <tr>
                                            <th>Student ID</th>
                                            <th>Full Name</th>
                                            <th>Username</th>
                                            <th>Email</th>
                                            <th>Year Level</th>
                                            <th>Program</th>
                                            <th>Enrolled</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody id="students-tbody">
                                        <?php foreach ($students as $student): ?>
                                            <tr id="student-<?php echo $student['id']; ?>">
                                                <td><strong><?php echo htmlspecialchars($student['student_id'] ?? 'N/A'); ?></strong></td>
                                                <td><?php echo htmlspecialchars($student['full_name']); ?></td>
                                                <td><?php echo htmlspecialchars($student['username']); ?></td>
                                                <td><?php echo htmlspecialchars($student['email'] ?? 'N/A'); ?></td>
                                                <td>
                                                    <?php if ($student['year_level']): ?>
                                                        <span class="business-badge" style="font-size: 12px; padding: 4px 12px;">
                                                            <?php echo htmlspecialchars($student['year_level']); ?>
                                                        </span>
                                                    <?php else: ?>
                                                        N/A
                                                    <?php endif; ?>
                                                </td>
                                                <td style="max-width: 200px; overflow: hidden; text-overflow: ellipsis;">
                                                    <?php echo htmlspecialchars($student['program'] ?? 'N/A'); ?>
                                                </td>
                                                <td><?php echo date('M d, Y', strtotime($student['created_at'])); ?></td>
                                                <td>
                                                    <div style="display: flex; gap: 5px;">
                                                        <button onclick="editStudent(<?php echo $student['id']; ?>)" class="business-btn" style="padding: 5px 10px; font-size: 12px;">
                                                            <i class="fas fa-edit"></i>
                                                        </button>
                                                        <button onclick="deleteStudent(<?php echo $student['id']; ?>, '<?php echo htmlspecialchars($student['full_name']); ?>')" class="business-btn" style="padding: 5px 10px; font-size: 12px; background: #dc3545;">
                                                            <i class="fas fa-trash"></i>
                                                        </button>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                                <!-- Pagination -->
                                <div id="pagination" style="display:flex; justify-content: space-between; align-items:center; padding:12px 0;">
                                    <div style="display:flex; align-items:center; gap:8px;">
                                        <label for="page-size" style="font-size:12px; color:#6b7280;">Rows per page</label>
                                        <select id="page-size" class="business-input" style="max-width:100px;">
                                            <option value="10">10</option>
                                            <option value="20">20</option>
                                            <option value="50">50</option>
                                        </select>
                                        <span id="page-info" style="font-size:12px; color:#6b7280;"></span>
                                    </div>
                                    <div style="display:flex; gap:6px;">
                                        <button type="button" class="business-btn" id="prev-page" style="padding:6px 10px;">Prev</button>
                                        <button type="button" class="business-btn" id="next-page" style="padding:6px 10px;">Next</button>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Edit Student Modal -->
    <div id="editModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000;">
        <div style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); background: white; padding: 40px; border-radius: 15px; width: 90%; max-width: 800px;">
            <h3 style="margin: 0 0 30px 0; color: var(--business-primary); text-transform: uppercase;">Edit Student Information</h3>
            <form id="editForm" method="POST">
                <input type="hidden" name="action" value="update_student">
                <input type="hidden" name="student_id" id="editStudentId">
                <input type="hidden" name="enrollments_json" id="enrollmentsJson">
                
                <div style="display: grid; gap: 20px;">
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                        <div>
                            <label style="display: block; margin-bottom: 8px; font-weight: 600; text-transform: uppercase; font-size: 12px;">Full Name</label>
                            <input type="text" name="full_name" id="editFullName" class="business-input" required>
                        </div>
                        <div>
                            <label style="display: block; margin-bottom: 8px; font-weight: 600; text-transform: uppercase; font-size: 12px;">Email</label>
                            <input type="email" name="email" id="editEmail" class="business-input">
                        </div>
                    </div>
                    
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                        <div>
                            <label style="display: block; margin-bottom: 8px; font-weight: 600; text-transform: uppercase; font-size: 12px;">Year Level</label>
                            <select name="year_level" id="editYearLevel" class="business-input">
                                <option value="">Select Year Level</option>
                                <option value="1st Year">1st Year</option>
                                <option value="2nd Year">2nd Year</option>
                                <option value="3rd Year">3rd Year</option>
                                <option value="4th Year">4th Year</option>
                            </select>
                        </div>
                        <div>
                            <label style="display: block; margin-bottom: 8px; font-weight: 600; text-transform: uppercase; font-size: 12px;">Program</label>
                            <select name="program" id="editProgram" class="business-input">
                                <option value="">Select Program</option>
                                <?php foreach ($business_programs as $program): ?>
                                    <option value="<?php echo htmlspecialchars($program); ?>"><?php echo htmlspecialchars($program); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <!-- Enrollment management -->
                    <div class="business-card" style="padding: 16px; border-radius: 12px; background: #f9fafb;">
                        <div style="display:flex; align-items:center; justify-content: space-between; margin-bottom: 10px;">
                            <h4 style="margin:0; color:#111827; text-transform: uppercase; font-size:14px;">Enrolled Subjects and Assigned Faculty</h4>
                            <button type="button" class="business-btn" style="padding:6px 10px; font-size:12px;" onclick="addEnrollmentRow()"><i class="fas fa-plus"></i> Add Subject</button>
                        </div>
                        <div id="enrollmentRows" style="display: grid; gap: 10px;"></div>
                        <p style="margin-top:8px; font-size:12px; color:#6b7280;">Add one or more subjects with their corresponding assigned faculty for this student.</p>
                    </div>
                    
                    <div style="display: flex; gap: 15px; justify-content: flex-end; margin-top: 20px;">
                        <button type="button" onclick="closeEditModal()" style="background: #6c757d; color: white; border: none; padding: 12px 24px; border-radius: 8px; cursor: pointer;">
                            Cancel
                        </button>
                        <button type="submit" class="business-btn">
                            <i class="fas fa-save"></i>
                            Update Student
                        </button>
                    </div>
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
                    if (data && data.success && data.redirect) {
                        window.location.href = data.redirect;
                        return;
                    }
                } catch(_) { /* ignore parse errors */ }
            } catch(_) { /* ignore network errors */ }
            window.location.href = '../../auth.php?action=logout';
        }
        // Animation delays
        document.addEventListener('DOMContentLoaded', function() {
            const cards = document.querySelectorAll('.business-animate');
            cards.forEach((card, index) => {
                card.style.animationDelay = `${index * 0.1}s`;
            });
        });

        // Data caches
        const DEPT = 'Business';
        let subjectsCache = [];
        let facultyCache = [];
        const enrollmentsMap = <?php echo json_encode($enrollments_map); ?>;

        function fetchSubjects() {
            if (subjectsCache.length) return Promise.resolve(subjectsCache);
            return fetch(`../../api/subjects.php?department=${encodeURIComponent(DEPT)}`)
                .then(r=>r.json()).then(d=>{ subjectsCache = d.success ? d.data : []; return subjectsCache; })
                .catch(()=>[]);
        }
        function fetchFaculty() {
            if (facultyCache.length) return Promise.resolve(facultyCache);
            return fetch(`../../api/faculties.php?department=${encodeURIComponent(DEPT)}`)
                .then(r=>r.json()).then(d=>{ facultyCache = d.success ? d.data : []; return facultyCache; })
                .catch(()=>[]);
        }

        function makeSelect(options, valueKey, labelKey, currentValue, placeholder, cls) {
            const sel = document.createElement('select');
            sel.className = cls;
            const ph = document.createElement('option');
            ph.value = '';
            ph.textContent = placeholder;
            sel.appendChild(ph);
            options.forEach(o=>{
                const op = document.createElement('option');
                op.value = o[valueKey] ?? '';
                op.textContent = o[labelKey] ?? '';
                if (currentValue && String(currentValue) === String(op.value)) op.selected = true;
                sel.appendChild(op);
            });
            return sel;
        }

        function addEnrollmentRow(prefill) {
            const container = document.getElementById('enrollmentRows');
            const row = document.createElement('div');
            row.style.display = 'grid';
            row.style.gridTemplateColumns = '1fr 1fr auto';
            row.style.gap = '10px';
            row.style.alignItems = 'center';

            Promise.all([fetchSubjects(), fetchFaculty()]).then(([subjects, faculty])=>{
                const subjOptions = subjects.map(s=>({ value: s.code || s.name, label: `${s.name}${s.code ? ' ('+s.code+')':''}`, code: s.code || '', name: s.name }));
                const facOptions = faculty.map(f=>({ value: f.user_id, label: f.full_name || f.username }));

                const subjSel = makeSelect(subjOptions, 'value', 'label', prefill?.subject_code || '', 'Select subject', 'business-input');
                const facSel = makeSelect(facOptions, 'value', 'label', prefill?.faculty_user_id || '', 'Assign faculty', 'business-input');

                const removeBtn = document.createElement('button');
                removeBtn.type = 'button';
                removeBtn.className = 'business-btn';
                removeBtn.style.background = '#ef4444';
                removeBtn.style.padding = '8px 12px';
                removeBtn.innerHTML = '<i class="fas fa-times"></i>';
                removeBtn.addEventListener('click', ()=> row.remove());

                row.appendChild(subjSel);
                row.appendChild(facSel);
                row.appendChild(removeBtn);

                if (prefill && !prefill.subject_code && prefill.subject_name) {
                    Array.from(subjSel.options).forEach(op=>{ if (op.textContent.startsWith(prefill.subject_name)) { subjSel.value = op.value; } });
                }
            });

            container.appendChild(row);
        }

        function serializeEnrollments() {
            const rows = Array.from(document.querySelectorAll('#enrollmentRows > div'));
            const result = [];
            rows.forEach(div=>{
                const selects = div.querySelectorAll('select');
                const subjSel = selects[0];
                const facSel = selects[1];
                const subjOpt = subjSel.options[subjSel.selectedIndex];
                if (!subjSel.value || !facSel.value) return;
                const label = subjOpt.textContent;
                const name = label.replace(/\s*\([^)]*\)\s*$/, '');
                const code = subjSel.value || '';
                result.push({ subject_code: code, subject_name: name, faculty_user_id: parseInt(facSel.value, 10) });
            });
            document.getElementById('enrollmentsJson').value = JSON.stringify(result);
        }

        document.getElementById('editForm').addEventListener('submit', function(){ serializeEnrollments(); });

        function clearEnrollmentRows() { document.getElementById('enrollmentRows').innerHTML = ''; }

        function editStudent(studentId) {
            // Get student data from the table row
            const row = document.getElementById('student-' + studentId);
            const cells = row.getElementsByTagName('td');
            
            document.getElementById('editStudentId').value = studentId;
            document.getElementById('editFullName').value = cells[1].textContent;
            document.getElementById('editEmail').value = cells[3].textContent === 'N/A' ? '' : cells[3].textContent;
            
            // Set year level
            const yearLevelText = cells[4].textContent.trim();
            document.getElementById('editYearLevel').value = yearLevelText === 'N/A' ? '' : yearLevelText;
            
            // Set program
            const programText = cells[5].textContent.trim();
            document.getElementById('editProgram').value = programText === 'N/A' ? '' : programText;
            
            clearEnrollmentRows();
            const existing = enrollmentsMap[String(studentId)] || [];
            if (existing.length) { existing.forEach(en=> addEnrollmentRow(en)); } else { addEnrollmentRow(); }

            document.getElementById('editModal').style.display = 'block';
        }

        function closeEditModal() {
            document.getElementById('editModal').style.display = 'none';
        }

        function deleteStudent(studentId, studentName) {
            if (confirm('Are you sure you want to remove ' + studentName + ' from the Business department? This action cannot be undone.')) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `
                    <input type="hidden" name="action" value="delete_student">
                    <input type="hidden" name="student_id" value="${studentId}">
                `;
                document.body.appendChild(form);
                form.submit();
            }
        }

        // Close modal when clicking outside
        document.getElementById('editModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeEditModal();
            }
        });

        // Search, filter, pagination
        (function(){
            const tbody = document.getElementById('students-tbody');
            if (!tbody) return;
            const rows = Array.from(tbody.querySelectorAll('tr'));
            const qId = document.getElementById('filter-id');
            const qName = document.getElementById('filter-name');
            const qProg = document.getElementById('filter-program');
            const qYear = document.getElementById('filter-year');
            const clearBtn = document.getElementById('filters-clear');
            const pageSizeSel = document.getElementById('page-size');
            const prevBtn = document.getElementById('prev-page');
            const nextBtn = document.getElementById('next-page');
            const pageInfo = document.getElementById('page-info');

            let filtered = rows.slice();
            let current = 1;

            function applyFilters(){
                const idv = (qId?.value || '').toLowerCase();
                const namev = (qName?.value || '').toLowerCase();
                const prov = (qProg?.value || '').toLowerCase();
                const yearv = (qYear?.value || '').toLowerCase();
                filtered = rows.filter(tr => {
                    const cells = tr.getElementsByTagName('td');
                    const sid = (cells[0]?.innerText || '').toLowerCase();
                    const fname = (cells[1]?.innerText || '').toLowerCase();
                    const prog = (cells[5]?.innerText || '').toLowerCase();
                    const year = (cells[4]?.innerText || '').toLowerCase();
                    if (idv && !sid.includes(idv)) return false;
                    if (namev && !fname.includes(namev)) return false;
                    if (prov && !prog.includes(prov)) return false;
                    if (yearv && !year.includes(yearv)) return false;
                    return true;
                });
                current = 1;
                render();
            }

            function getPageSize(){
                const n = parseInt(pageSizeSel?.value || '10', 10);
                return isNaN(n) || n <= 0 ? 10 : n;
            }

            function render(){
                const size = getPageSize();
                const total = filtered.length;
                const pages = Math.max(1, Math.ceil(total / size));
                if (current > pages) current = pages;
                const start = (current - 1) * size;
                const end = start + size;
                // hide all first
                rows.forEach(tr => tr.style.display = 'none');
                filtered.slice(start, end).forEach(tr => tr.style.display = '');
                if (pageInfo) pageInfo.textContent = `Showing ${Math.min(total, start+1)}-${Math.min(total, end)} of ${total}`;
                if (prevBtn) prevBtn.disabled = current <= 1;
                if (nextBtn) nextBtn.disabled = current >= pages;
            }

            function clearFilters(){
                if (qId) qId.value = '';
                if (qName) qName.value = '';
                if (qProg) qProg.value = '';
                if (qYear) qYear.value = '';
                applyFilters();
            }

            qId && qId.addEventListener('input', applyFilters);
            qName && qName.addEventListener('input', applyFilters);
            qProg && qProg.addEventListener('input', applyFilters);
            qYear && qYear.addEventListener('change', applyFilters);
            pageSizeSel && pageSizeSel.addEventListener('change', render);
            clearBtn && clearBtn.addEventListener('click', clearFilters);
            prevBtn && prevBtn.addEventListener('click', ()=>{ if (current>1){ current--; render(); } });
            nextBtn && nextBtn.addEventListener('click', ()=>{ current++; render(); });

            // initial
            applyFilters();
        })();
    </script>
</body>
</html>
