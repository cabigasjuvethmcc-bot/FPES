<?php
require_once '../config.php';
requireRole('student');

// Get student info
$stmt = $pdo->prepare("SELECT s.*, u.full_name, u.department FROM students s 
                       JOIN users u ON s.user_id = u.id 
                       WHERE s.id = ?");
$stmt->execute([$_SESSION['student_id']]);
$student = $stmt->fetch();

// Get enrolled subjects for this student with assigned faculty
// Uses the junction table created during enrollment: student_faculty_subjects
// Note: junction table stores faculty_user_id, while evaluations expects faculty.id
$enrollments = [];
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

    $stmt = $pdo->prepare("SELECT 
            sfs.subject_code, 
            sfs.subject_name, 
            fu.id AS faculty_user_id,
            f.id AS faculty_id,
            fu.full_name AS faculty_name,
            fu.department AS faculty_department,
            f.position AS faculty_position
        FROM student_faculty_subjects sfs
        JOIN users fu ON fu.id = sfs.faculty_user_id AND fu.role = 'faculty'
        JOIN faculty f ON f.user_id = fu.id
        WHERE sfs.student_user_id = ?
        ORDER BY sfs.subject_name, fu.full_name");
    $stmt->execute([$_SESSION['user_id']]);
    $enrollments = $stmt->fetchAll();
} catch (PDOException $e) {
    $enrollments = [];
}

// Ensure evaluation criteria are up to date (auto-migrate legacy defaults if needed)
if (function_exists('ensureNewEvaluationCriteria')) {
    ensureNewEvaluationCriteria($pdo);
}

// Get evaluation criteria
$stmt = $pdo->prepare("SELECT * FROM evaluation_criteria WHERE is_active = 1 ORDER BY category, criterion");
$stmt->execute();
$criteria = $stmt->fetchAll();

// Group criteria by category
$grouped_criteria = [];
foreach ($criteria as $criterion) {
    $grouped_criteria[$criterion['category']][] = $criterion;
}

// Get student's evaluations
$stmt = $pdo->prepare("SELECT e.*, u.full_name as faculty_name, f.position 
                       FROM evaluations e 
                       JOIN faculty f ON e.faculty_id = f.id 
                       JOIN users u ON f.user_id = u.id 
                       WHERE e.student_id = ? 
                       ORDER BY e.created_at DESC");
$stmt->execute([$_SESSION['student_id']]);
$evaluations = $stmt->fetchAll();

// Evaluation schedule state and active period
list($evalOpen, $evalState, $evalReason, $evalSchedule) = isEvaluationOpenForStudents($pdo);
$activePeriod = $evalOpen ? getActiveSemesterYear($pdo) : null;

// Quick evaluation context (set when student comes from a QR link)
$quickEval = isset($_SESSION['quick_eval']) ? $_SESSION['quick_eval'] : null;
if ($quickEval && !empty($quickEval['faculty_id'])) {
    // Normalize values for comparison
    $quickEval['faculty_id'] = (int)$quickEval['faculty_id'];
    $quickEval['subject_code'] = isset($quickEval['subject_code']) ? trim((string)$quickEval['subject_code']) : '';
    $quickEval['subject_name'] = isset($quickEval['subject_name']) ? trim((string)$quickEval['subject_name']) : '';
}
// Use-once: clear after page load
unset($_SESSION['quick_eval']);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Dashboard - Faculty Performance Evaluation</title>
    <link rel="icon" href="../img/loginlogo.png?v=2" type="image/png">
    <link rel="stylesheet" href="../styles.css">
    <link rel="stylesheet" href="student.css">
</head>
<body>
    <!-- Mobile sidebar toggle -->
    <button class="sidebar-toggle" id="sidebarToggle" aria-label="Toggle navigation">☰ Menu</button>
    <div class="dashboard">
        <div class="sidebar">
            <h2>Student Portal</h2>
            <a href="#" onclick="showSection('evaluate')">Evaluate Faculty</a>
            <a href="#" onclick="showSection('history')">My Evaluations</a>
            <a href="#" onclick="showSection('profile')">Profile</a>
            <button class="logout-btn" onclick="logout()">Logout</button>
        </div>

        <div class="main-content">
            <div class="welcome-header">
                <h1>Welcome, <?php echo htmlspecialchars($student['full_name']); ?>!</h1>
                <p>Student ID: <?php echo htmlspecialchars($student['student_id']); ?> | Program: <?php echo htmlspecialchars($student['program']); ?></p>
            </div>

            <?php
                // Banner notice for evaluation state
                $bannerMsg = '';
                if ($evalOpen) {
                    $bannerMsg = $evalSchedule['notice'] ?? 'Evaluations are currently OPEN.';
                    echo '<div class="success-message">' . htmlspecialchars($bannerMsg) . '</div>';
                } else {
                    $msg = 'Evaluations are currently closed. Please wait for the schedule to open.';
                    echo '<div class="error-message">' . htmlspecialchars($msg) . '</div>';
                }
            ?>

            <!-- Evaluate Faculty Section -->
            <div id="evaluate-section" class="content-section">
                <h2>Evaluate Faculty Performance</h2>
                <div class="evaluation-form-container">
                    <?php if ($evalOpen): ?>
                    <form id="evaluation-form" method="post" action="submit_evaluation.php">
                        <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                        <h3 class="section-title">Subject & Faculty Selection</h3>

                        <?php if ($quickEval && !empty($quickEval['faculty_id'])): ?>
                            <div class="success-message">This evaluation was started from a QR code. Please review the subject and faculty below and complete the form.</div>
                            <div class="form-group">
                                <label>Subject &amp; Faculty:</label>
                                <div style="padding:0.75rem 1rem; background:#f9fafb; border-radius:8px; border:1px solid #e5e7eb;">
                                    <strong><?php echo htmlspecialchars(($quickEval['subject_code'] ? $quickEval['subject_code'] . ' - ' : '') . ($quickEval['subject_name'] ?: 'Subject')); ?></strong><br>
                                    <small>Faculty ID: <?php echo (int)$quickEval['faculty_id']; ?> (via QR)</small>
                                </div>
                                <input type="hidden" id="faculty_id" name="faculty_id" value="<?php echo (int)$quickEval['faculty_id']; ?>">
                                <input type="hidden" id="subject" name="subject" value="<?php echo htmlspecialchars($quickEval['subject_name'] ?: $quickEval['subject_code']); ?>">
                            </div>
                        <?php else: ?>
                            <?php if (!empty($enrollments)): ?>
                                <div class="form-group">
                                    <label for="enrollment_select">Subject &amp; Faculty:</label>
                                    <select id="enrollment_select" name="enrollment" required>
                                        <option value="">-- Select Subject &amp; Faculty --</option>
                                        <?php foreach ($enrollments as $en): ?>
                                            <?php
                                                $optLabel = trim(($en['subject_code'] ? $en['subject_code'] . ' - ' : '') . $en['subject_name'] . ' | ' . $en['faculty_name']);
                                                $value = $en['faculty_id'] . '::' . $en['subject_name'];
                                            ?>
                                            <option 
                                                value="<?php echo htmlspecialchars($value); ?>"
                                                data-faculty-id="<?php echo (int)$en['faculty_id']; ?>"
                                                data-subject="<?php echo htmlspecialchars($en['subject_name']); ?>">
                                                <?php echo htmlspecialchars($optLabel); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <small class="muted-note">Choose the subject and faculty you want to evaluate. You can also scan a QR code if provided.</small>
                                </div>
                                <input type="hidden" id="faculty_id" name="faculty_id" value="">
                                <input type="hidden" id="subject" name="subject" value="">
                            <?php else: ?>
                                <div class="error-message">No enrolled subjects with assigned faculty were found for your account. Please contact your department or scan a valid QR code to evaluate.</div>
                                <input type="hidden" id="faculty_id" name="faculty_id" value="">
                                <input type="hidden" id="subject" name="subject" value="">
                            <?php endif; ?>
                        <?php endif; ?>

                        <div class="form-group">
                            <label for="semester">Semester:</label>
                            <select id="semester" name="semester" required disabled>
                                <option value="">-- Select Semester --</option>
                                <option value="1st Semester" <?php echo ($activePeriod && $activePeriod['semester']==='1st Semester') ? 'selected' : ''; ?>>1st Semester</option>
                                <option value="2nd Semester" <?php echo ($activePeriod && $activePeriod['semester']==='2nd Semester') ? 'selected' : ''; ?>>2nd Semester</option>
                            </select>
                            <small class="muted-note">Semester is set automatically based on the current evaluation schedule.</small>
                        </div>

                        <div class="form-group">
                            <label for="academic_year">Academic Year:</label>
                            <input type="text" id="academic_year" name="academic_year" value="<?php echo htmlspecialchars($activePeriod['academic_year'] ?? ''); ?>" placeholder="e.g., 2023-2024" required readonly>
                        </div>

                        <div class="evaluation-criteria">
                            <h3 class="section-title">Evaluation Criteria</h3>
                            <p><strong>Direction:</strong> Please evaluate the faculty member involved by choosing the number that corresponds to the given parameter/dimension on the scale of 5, where 5 is the highest score and 1 is the lowest score.</p>
                            <p><strong>Scale &mdash; Descriptive Rating with its Corresponding Qualitative Description</strong></p>
                            <p><strong>5 &mdash; Outstanding</strong><br>
                                Outstanding exhibits the behavior described <em>at all times</em> when the occasion occurs. The faculty is an exceptional role model.</p>
                            <p><strong>4 &mdash; Very Satisfactory</strong><br>
                                Very satisfactory exhibits the behavior described <em>most of the time</em> when the occasion occurs. The performance meets and often exceeds the job requirements.</p>
                            <p><strong>3 &mdash; Satisfactory</strong><br>
                                Satisfactory exhibits the behavior described <em>sometimes</em> when the occasion occurs. The performance meets job requirements.</p>
                            <p><strong>2 &mdash; Fair</strong><br>
                                Fair exhibits the behavior described <em>rarely</em> when the occasion occurs. The performance only minimally meets job requirements.</p>
                            <p><strong>1 &mdash; Poor</strong><br>
                                Poor behavior described has not been exhibited when the occasion occurs. The faculty fails to meet job requirements.</p>
                            
                            <?php foreach ($grouped_criteria as $category => $category_criteria): ?>
                                <div class="criteria-category">
                                    <h4><?php echo htmlspecialchars($category); ?></h4>
                                    <?php foreach ($category_criteria as $criterion): ?>
                                        <div class="criterion-item">
                                            <label><?php echo htmlspecialchars($criterion['criterion']); ?></label>
                                            <div class="rating-scale">
                                                <?php for ($i = 5; $i >= 1; $i--): ?>
                                                    <label class="rating-option">
                                                        <input type="radio" name="rating_<?php echo $criterion['id']; ?>" value="<?php echo $i; ?>" required>
                                                        <span><?php echo $i; ?></span>
                                                    </label>
                                                <?php endfor; ?>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <h3 class="section-title">Additional Comments</h3>
                        <div class="form-group">
                            <label for="overall_comments">Overall Comments:</label>
                            <textarea id="overall_comments" name="overall_comments" rows="4" placeholder="Share your overall thoughts about this faculty member's performance"></textarea>
                        </div>

                        <button type="submit" class="submit-btn">Submit Evaluation</button>
                    </form>
                    <?php else: ?>
                        <div class="error-message">Evaluations are currently closed. Please wait for the schedule to open.</div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Evaluation History Section -->
            <div id="history-section" class="content-section" style="display: none;">
                <h2>My Evaluations</h2>
                <div class="evaluations-list">
                    <?php if (empty($evaluations)): ?>
                        <p>You haven't submitted any evaluations yet.</p>
                    <?php else: ?>
                        <table class="evaluations-table">
                            <thead>
                                <tr>
                                    <th>Faculty</th>
                                    <th>Subject</th>
                                    <th>Semester</th>
                                    <th>Academic Year</th>
                                    <th>Status</th>
                                    <th>Submitted</th>
                                    <th>Details</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($evaluations as $eval): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($eval['faculty_name']); ?></td>
                                        <td><?php echo htmlspecialchars($eval['subject']); ?></td>
                                        <td><?php echo htmlspecialchars($eval['semester']); ?></td>
                                        <td><?php echo htmlspecialchars($eval['academic_year']); ?></td>
                                        <td><span class="status-<?php echo $eval['status']; ?>"><?php echo ucfirst($eval['status']); ?></span></td>
                                        <td><?php echo date('M j, Y', strtotime($eval['submitted_at'])); ?></td>
                                        <td><a href="view_evaluation.php?id=<?php echo (int)$eval['id']; ?>">View</a></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Profile Section -->
            <div id="profile-section" class="content-section" style="display: none;">
                <h2>My Profile</h2>
                <div class="profile-info">
                    <div class="info-group">
                        <label>Full Name:</label>
                        <span><?php echo htmlspecialchars($student['full_name']); ?></span>
                    </div>
                    <div class="info-group">
                        <label>Student ID:</label>
                        <span><?php echo htmlspecialchars($student['student_id']); ?></span>
                    </div>
                    <div class="info-group">
                        <label>Program:</label>
                        <span><?php echo htmlspecialchars($student['program']); ?></span>
                    </div>
                    <div class="info-group">
                        <label>Year Level:</label>
                        <span><?php echo htmlspecialchars($student['year_level']); ?></span>
                    </div>
                    <div class="info-group">
                        <label>Department:</label>
                        <span><?php echo htmlspecialchars($student['department']); ?></span>
                    </div>
                </div>

                <div class="profile-info" style="margin-top:1rem;">
                    <h3 style="margin:0 0 .5rem;">Edit Password</h3>
                    <form id="student-change-password-form" class="forgot-card" onsubmit="return false;">
                        <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                        <div class="form-group">
                            <label for="current_password">Current Password</label>
                            <input type="password" id="current_password" name="current_password" required />
                        </div>
                        <div class="form-group">
                            <label for="new_password">New Password</label>
                            <input type="password" id="new_password" name="new_password" required />
                            <div class="hint" style="color:#6b7280; font-size:.9rem;">At least 8 characters, include letters and numbers.</div>
                        </div>
                        <div class="form-group">
                            <label for="confirm_password">Confirm New Password</label>
                            <input type="password" id="confirm_password" name="confirm_password" required />
                        </div>
                        <div id="pw-msg" class="error-message" style="display:none;"></div>
                        <div id="pw-success" class="success-message" style="display:none;">Password changed successfully.</div>
                        <button type="submit" class="btn-primary">Save Password</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script>
      // Expose student's own evaluations to the frontend for filtering
      window.STUDENT_EVALS = <?php echo json_encode(array_map(function($e){
        return [
          'faculty_id' => (int)$e['faculty_id'],
          'subject' => $e['subject'],
          'semester' => $e['semester'],
          'academic_year' => $e['academic_year']
        ];
      }, $evaluations)); ?>;
    </script>
    <?php if (!empty($_SESSION['flash_message'])): ?>
        <script>
          sessionStorage.setItem('flashMessage', <?php echo json_encode((string)$_SESSION['flash_message']); ?>);
          sessionStorage.setItem('flashType', <?php echo json_encode((string)($_SESSION['flash_type'] ?? 'success')); ?>);
          sessionStorage.setItem('flashSection', <?php echo json_encode((string)($_SESSION['flash_section'] ?? 'evaluate')); ?>);
        </script>
        <?php
          unset($_SESSION['flash_message'], $_SESSION['flash_type'], $_SESSION['flash_section']);
        ?>
    <?php endif; ?>
    <script src="student.js"></script>
    <script>
      (function(){
        var btn = document.getElementById('sidebarToggle');
        var sidebar = document.querySelector('.sidebar');
        if (btn && sidebar) {
          btn.addEventListener('click', function(){
            sidebar.classList.toggle('active');
          });
        }
      })();

      // Password change handler
      (function(){
        const form = document.getElementById('student-change-password-form');
        if (!form) return;
        const err = document.getElementById('pw-msg');
        const ok = document.getElementById('pw-success');
        form.addEventListener('submit', function(){
          err.style.display = 'none'; ok.style.display = 'none';
          const fd = new FormData(form);
          const np = fd.get('new_password')+''; const cp = fd.get('confirm_password')+'';
          if (np.length < 8 || !/[A-Za-z]/.test(np) || !/\d/.test(np)) {
            err.textContent = 'Password must be at least 8 characters and include letters and numbers.';
            err.style.display = 'block';
            return;
          }
          if (np !== cp) {
            err.textContent = 'New Password and Confirm New Password do not match.';
            err.style.display = 'block';
            return;
          }
          fetch('../api/change_password.php', { method:'POST', body: fd })
            .then(r=>r.json())
            .then(data=>{
              if (data.success) {
                ok.textContent = data.message || 'Password changed successfully. Logging out...';
                ok.style.display = 'block';
                setTimeout(()=>{ window.location.href = data.redirect || '../index.php'; }, 1200);
              } else {
                err.textContent = data.message || 'Unable to change password.';
                err.style.display = 'block';
              }
            })
            .catch(()=>{ err.textContent = 'Network error. Please try again.'; err.style.display='block'; });
        });
      })();
    </script>
</body>
</html>
