<?php
require_once '../config.php';
requireRole('faculty');

// Get faculty info
$stmt = $pdo->prepare("SELECT f.*, u.full_name, u.department FROM faculty f 
                       JOIN users u ON f.user_id = u.id 
                       WHERE f.id = ?");
$stmt->execute([$_SESSION['faculty_id']]);
$faculty = $stmt->fetch();

// My Evaluations data retrieval removed

// Get evaluation statistics
$stmt = $pdo->prepare("SELECT 
                        COUNT(*) as total_evaluations,
                        AVG(overall_rating) as avg_rating,
                        MIN(overall_rating) as min_rating,
                        MAX(overall_rating) as max_rating
                       FROM evaluations 
                       WHERE faculty_id = ? AND status = 'submitted' AND COALESCE(is_counted,1) = 1");
$stmt->execute([$_SESSION['faculty_id']]);
$stats = $stmt->fetch();

// Recent submitted evaluations for detail view
$recent_evals = [];
try {
    $reStmt = $pdo->prepare("SELECT id, subject, semester, academic_year, overall_rating, submitted_at
                              FROM evaluations
                              WHERE faculty_id = ? AND status = 'submitted' AND COALESCE(is_counted,1) = 1
                              ORDER BY submitted_at DESC
                              LIMIT 20");
    $reStmt->execute([$_SESSION['faculty_id']]);
    $recent_evals = $reStmt->fetchAll();
} catch (PDOException $e) {
    $recent_evals = [];
}

// Get ratings by criteria
$stmt = $pdo->prepare("SELECT ec.category, ec.criterion, AVG(er.rating) as avg_rating, COUNT(er.rating) as count
                       FROM evaluation_responses er
                       JOIN evaluation_criteria ec ON er.criterion_id = ec.id
                       JOIN evaluations e ON er.evaluation_id = e.id
                       WHERE e.faculty_id = ? AND e.status = 'submitted' AND COALESCE(e.is_counted,1) = 1
                       GROUP BY ec.id, ec.category, ec.criterion
                       ORDER BY ec.category, ec.criterion");
$criteria_ratings = $stmt->fetchAll();

// Group criteria ratings by category
$grouped_ratings = [];
foreach ($criteria_ratings as $rating) {
    $grouped_ratings[$rating['category']][] = $rating;
}


// Load existing self-evaluation submissions
$self_evals = [];
try {
    $seStmt = $pdo->prepare("SELECT id, subject_code, subject_name, semester, academic_year, overall_rating, submitted_at
                              FROM self_evaluation
                              WHERE faculty_id = ?
                              ORDER BY submitted_at DESC");
    $seStmt->execute([$_SESSION['faculty_id']]);
    $self_evals = $seStmt->fetchAll();
} catch (PDOException $e) {
    $self_evals = [];
}

// Get semester-wise performance
$stmt = $pdo->prepare("SELECT semester, academic_year, AVG(overall_rating) as avg_rating, COUNT(*) as count
                       FROM evaluations 
                       WHERE faculty_id = ? AND status = 'submitted' AND COALESCE(is_counted,1) = 1
                       GROUP BY semester, academic_year
                       ORDER BY academic_year DESC, semester");
$stmt->execute([$_SESSION['faculty_id']]);
$semester_performance = $stmt->fetchAll();

// Distinct terms (semester + academic year) for analytics filter
$terms = [];
try {
    $tStmt = $pdo->prepare("SELECT DISTINCT academic_year, semester
                             FROM evaluations
                             WHERE faculty_id = ? AND status = 'submitted' AND COALESCE(is_counted,1) = 1
                             ORDER BY academic_year DESC, semester");
    $tStmt->execute([$_SESSION['faculty_id']]);
    $terms = $tStmt->fetchAll();
} catch (PDOException $e) {
    $terms = [];
}

// Faculty directory removed from system; keep placeholder variable empty
$all_faculty = [];

// Get assigned subjects for this faculty (by users.id referenced from faculty.user_id)
$subjects = [];
try {
    $subStmt = $pdo->prepare("SELECT fs.subject_code, fs.subject_name
                               FROM faculty_subjects fs
                               JOIN faculty f ON fs.faculty_user_id = f.user_id
                               WHERE f.id = ?
                               ORDER BY fs.subject_name");
    $subStmt->execute([$_SESSION['faculty_id']]);
    $subjects = $subStmt->fetchAll();
} catch (PDOException $e) {
    $subjects = [];
}

// Get students assigned to this faculty by student_faculty_subjects
$assigned_students = [];
try {
    $assignStmt = $pdo->prepare("SELECT sus.student_user_id, su.full_name AS student_name, s.student_id,
                                        sfs.subject_code, sfs.subject_name
                                 FROM faculty f
                                 JOIN student_faculty_subjects sfs ON sfs.faculty_user_id = f.user_id
                                 JOIN users su ON su.id = sfs.student_user_id
                                 LEFT JOIN students s ON s.user_id = su.id
                                 WHERE f.id = ?
                                 ORDER BY su.full_name, sfs.subject_name");
    $assignStmt->execute([$_SESSION['faculty_id']]);
    $assigned_students = $assignStmt->fetchAll();
} catch (PDOException $e) {
    $assigned_students = [];
}

// Evaluation schedule state and active period (mirror student/dean flow)
list($evalOpen, $evalState, $evalReason, $evalSchedule) = isEvaluationOpenForStudents($pdo);
$activePeriod = $evalOpen ? getActiveSemesterYear($pdo) : null;

// Load evaluation criteria for self-evaluation forms
$criteria = [];
try {
    $cStmt = $pdo->query("SELECT id, category, criterion FROM evaluation_criteria WHERE COALESCE(is_active,1) = 1 ORDER BY category, criterion");
    $criteria = $cStmt->fetchAll();
} catch (PDOException $e) {
    $criteria = [];
}

// Build self-evaluation status per assigned subject for current active term (if any)
$self_eval_status = [];
try {
    // Load all self evaluations for this faculty for the active period (if available)
    $params = [$_SESSION['faculty_id']];
    $where = ["faculty_id = ?"];
    if (!empty($activePeriod['semester'])) { $where[] = 'semester = ?'; $params[] = $activePeriod['semester']; }
    if (!empty($activePeriod['academic_year'])) { $where[] = 'academic_year = ?'; $params[] = $activePeriod['academic_year']; }
    $wsql = implode(' AND ', $where);
    $seStmt2 = $pdo->prepare("SELECT subject_code, subject_name FROM self_evaluation WHERE $wsql");
    $seStmt2->execute($params);
    $rows = $seStmt2->fetchAll();
    foreach ($rows as $r) {
        $code = trim((string)$r['subject_code']);
        $name = trim((string)$r['subject_name']);
        $key = ($code !== '' ? $code : 'NO_CODE') . '||' . mb_strtolower($name);
        $self_eval_status[$key] = true;
    }
} catch (PDOException $e) {
    $self_eval_status = [];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Faculty Dashboard - Performance Evaluation</title>
    <link rel="icon" href="../img/loginlogo.png?v=2" type="image/png">
    <link rel="stylesheet" href="../styles.css">
    <link rel="stylesheet" href="../student/student.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }
        
        .stat-card {
            background: white;
            padding: 1.5rem;
            border-radius: 12px;
            box-shadow: var(--card-shadow);
            text-align: center;
        }
        
        .stat-number {
            font-size: 2.5rem;
            font-weight: bold;
            color: var(--primary-color);
            margin-bottom: 0.5rem;
        }
        
        .stat-label {
            color: var(--gray-color);
            font-size: 0.9rem;
        }
        
        .chart-container {
            background: white;
            padding: 2rem;
            border-radius: 12px;
            box-shadow: var(--card-shadow);
            margin-bottom: 2rem;
        }
        
        .evaluations-table {
            width: 100%;
            border-collapse: collapse;
            background: white;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: var(--card-shadow);
        }
        
        .evaluations-table th, .evaluations-table td {
            padding: 1rem;
            text-align: left;
            border-bottom: 1px solid #e1e5e9;
        }
        
        .evaluations-table th {
            background: var(--bg-color);
            font-weight: 600;
        }
        
        .rating-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 600;
        }
        
        .rating-excellent { background: var(--secondary-color); color: white; }
        .rating-good { background: var(--primary-color); color: white; }
        .rating-average { background: var(--warning-color); color: white; }
        .rating-poor { background: var(--danger-color); color: white; }
        
        .criteria-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 1.5rem;
        }
        
        .criteria-category {
            background: white;
            padding: 1.5rem;
            border-radius: 12px;
            box-shadow: var(--card-shadow);
        }
        
        .criteria-category h4 {
            color: var(--primary-color);
            margin-bottom: 1rem;
        }
        
        .criterion-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.75rem 0;
            border-bottom: 1px solid #f0f0f0;
        }
        
        .criterion-item:last-child {
            border-bottom: none;
        }
    </style>
<!-- Add viewport meta is already set in head; now add a mobile sidebar toggle like student portal -->
</head>
<body>
    <button class="sidebar-toggle" id="facultySidebarToggle" aria-label="Toggle navigation">☰ Menu</button>
    <div class="dashboard">
        <div class="sidebar">
            <h2>Faculty Portal</h2>
            <a href="#" onclick="showSection('overview')">Overview</a>
            <!-- My Evaluations menu item removed -->
            <a href="#" onclick="showSection('analytics')">Performance Analytics</a>
            <a href="#" onclick="showSection('profile')">Profile</a>
            <a href="#" onclick="showSection('self-evaluation')">Self-Evaluation</a>
            <a href="#" onclick="showSection('my-self-evaluations')">My Self-Evaluation</a>
            <a href="#" onclick="showSection('qr')">Evaluation QR Codes</a>
            <button class="logout-btn" onclick="logout()">Logout</button>
        </div>

        <div class="main-content">
            <div class="welcome-header">
                <h1>Welcome, <?php echo htmlspecialchars($faculty['full_name']); ?>!</h1>
                <p>Employee ID: <?php echo htmlspecialchars($faculty['employee_id']); ?> | Position: <?php echo htmlspecialchars($faculty['position']); ?></p>
            </div>

            <?php
                // Banner notice for evaluation state
                if ($evalOpen) {
                    $bannerMsg = $evalSchedule['notice'] ?? 'Evaluations are currently OPEN.';
                    echo '<div class="success-message">' . htmlspecialchars($bannerMsg) . '</div>';
                } else {
                    $msg = 'Evaluations are currently closed. Please wait for the schedule to open.';
                    echo '<div class="error-message">' . htmlspecialchars($msg) . '</div>';
                }
            ?>

            <!-- Overview Section -->
            <div id="overview-section" class="content-section">
                <h2>Performance Overview</h2>
                
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-number"><?php echo $stats['total_evaluations'] ?? 0; ?></div>
                        <div class="stat-label">Total Evaluations</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-number"><?php echo $stats['avg_rating'] ? number_format($stats['avg_rating'], 2) : 'N/A'; ?></div>
                        <div class="stat-label">Average Rating</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-number"><?php echo $stats['max_rating'] ?? 'N/A'; ?></div>
                        <div class="stat-label">Highest Rating</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-number"><?php echo $stats['min_rating'] ?? 'N/A'; ?></div>
                        <div class="stat-label">Lowest Rating</div>
                    </div>
                </div>

                <?php if (!empty($semester_performance)): ?>
                <div class="chart-container">
                    <h3>Semester Performance Trend</h3>
                    <canvas id="performanceChart" width="400" height="200"></canvas>
                </div>
                <?php endif; ?>

                <?php if (!empty($grouped_ratings)): ?>
                <div class="criteria-grid">
                    <?php foreach ($grouped_ratings as $category => $ratings): ?>
                        <div class="criteria-category">
                            <h4><?php echo htmlspecialchars($category); ?></h4>
                            <?php foreach ($ratings as $rating): ?>
                                <div class="criterion-item">
                                    <span><?php echo htmlspecialchars($rating['criterion']); ?></span>
                                    <span class="rating-badge rating-<?php 
                                        $avg = $rating['avg_rating'];
                                        if ($avg >= 4.5) echo 'excellent';
                                        elseif ($avg >= 3.5) echo 'good';
                                        elseif ($avg >= 2.5) echo 'average';
                                        else echo 'poor';
                                    ?>">
                                        <?php echo number_format($rating['avg_rating'], 2); ?>
                                    </span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
                
                <?php if (!empty($recent_evals)): ?>
                <div class="chart-container" style="margin-top:1.5rem;">
                    <h3>Recent Evaluations</h3>
                    <table class="evaluations-table">
                        <thead>
                            <tr>
                                <th>Subject</th>
                                <th>Semester</th>
                                <th>Academic Year</th>
                                <th>Overall Rating</th>
                                <th>Submitted</th>
                                <th>Details</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recent_evals as $ev): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($ev['subject']); ?></td>
                                    <td><?php echo htmlspecialchars($ev['semester']); ?></td>
                                    <td><?php echo htmlspecialchars($ev['academic_year']); ?></td>
                                    <td><?php echo $ev['overall_rating'] ? number_format($ev['overall_rating'], 2) : 'N/A'; ?></td>
                                    <td><?php echo $ev['submitted_at'] ? date('M j, Y', strtotime($ev['submitted_at'])) : 'N/A'; ?></td>
                                    <td><a href="evaluation_details.php?id=<?php echo (int)$ev['id']; ?>">View</a></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>

            <!-- Assigned Students Section -->
            <div id="assigned-students-section" class="content-section">
                <h2>My Assigned Students & Subjects</h2>
                <?php if (empty($assigned_students)): ?>
                    <p>No students assigned yet.</p>
                <?php else: ?>
                    <?php
                    // Group by student
                    $by_student = [];
                    foreach ($assigned_students as $row) {
                        $sid = $row['student_user_id'];
                        if (!isset($by_student[$sid])) {
                            $by_student[$sid] = [
                                'name' => $row['student_name'],
                                'student_id' => $row['student_id'] ?? null,
                                'subjects' => []
                            ];
                        }
                        $by_student[$sid]['subjects'][] = [
                            'code' => $row['subject_code'],
                            'name' => $row['subject_name']
                        ];
                    }
                    ?>
                    <table class="evaluations-table">
                        <thead>
                            <tr>
                                <th>Student</th>
                                <th>Student ID</th>
                                <th>Subjects</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($by_student as $stu): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($stu['name']); ?></td>
                                    <td><?php echo htmlspecialchars($stu['student_id'] ?? 'N/A'); ?></td>
                                    <td>
                                        <?php foreach ($stu['subjects'] as $s): ?>
                                            <span style="display:inline-block; background:#eef2f7; color:#334155; padding:4px 10px; border-radius:999px; margin:2px; font-size:12px; border:1px solid #e5e7eb;">
                                                <?php echo htmlspecialchars(($s['code'] ? $s['code'].' - ' : '').$s['name']); ?>
                                            </span>
                                        <?php endforeach; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>

            <!-- My Evaluations section removed -->

            <!-- Self-Evaluation Section -->
            <div id="self-evaluation-section" class="content-section" style="display:none;">
                <h2>Self-Evaluation</h2>
                <?php if (empty($subjects)): ?>
                    <p>You currently have no assigned subjects to self-evaluate.</p>
                <?php else: ?>
                    <div class="chart-container" style="margin-bottom:1.25rem;">
                        <h3>Select Subject</h3>
                        <div class="form-group">
                            <label for="self_subject_select">Subject:</label>
                            <select id="self_subject_select" onchange="onSubjectChangeSelfEval(this)">
                                <option value="">-- Select Subject --</option>
                                <?php 
                                    // Only show subjects that have not yet been self-evaluated for the active period
                                    $visibleCount = 0; 
                                    foreach ($subjects as $s): 
                                        $code = trim((string)$s['subject_code']);
                                        $name = trim((string)$s['subject_name']);
                                        $key = ($code !== '' ? $code : 'NO_CODE') . '||' . mb_strtolower($name);
                                        // If there is an active period and this subject is already evaluated, hide it
                                        if ($activePeriod && !empty($self_eval_status[$key])) {
                                            continue;
                                        }
                                        $visibleCount++;
                                ?>
                                    <option 
                                        value="<?php echo htmlspecialchars($s['subject_code'] ?: $s['subject_name']); ?>"
                                        data-code="<?php echo htmlspecialchars($s['subject_code']); ?>"
                                        data-name="<?php echo htmlspecialchars($s['subject_name']); ?>">
                                        <?php echo htmlspecialchars(($s['subject_code'] ? $s['subject_code'].' - ' : '').$s['subject_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                                <?php if ($visibleCount === 0): ?>
                                    <option value="" disabled>-- All assigned subjects are already evaluated for the current period --</option>
                                <?php endif; ?>
                            </select>
                        </div>
                    </div>

                    <?php 
                    // group criteria by category (for the dynamic form)
                    $crit_by_cat = [];
                    foreach ($criteria as $cr) { $crit_by_cat[$cr["category"]][] = $cr; }
                    ?>

                    <?php if ($evalOpen): ?>
                    <div id="self-eval-form-container" class="chart-container" style="display:none;">
                        <h3 id="self-eval-subject-title">Self-Evaluation</h3>
                        <form class="self-eval-form" onsubmit="return false;">
                            <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>" />
                            <input type="hidden" name="faculty_id" value="<?php echo (int)$_SESSION['faculty_id']; ?>" />
                            <input type="hidden" name="subject_code" value="" />
                            <input type="hidden" name="subject_name" value="" />

                            <div class="form-group">
                                <label for="semester">Semester:</label>
                                <select name="semester_display" disabled>
                                    <option value="">-- Select Semester --</option>
                                    <option value="1st Semester" <?php echo ($activePeriod && $activePeriod['semester']==='1st Semester') ? 'selected' : ''; ?>>1st Semester</option>
                                    <option value="2nd Semester" <?php echo ($activePeriod && $activePeriod['semester']==='2nd Semester') ? 'selected' : ''; ?>>2nd Semester</option>
                                </select>
                                <input type="hidden" name="semester" value="<?php echo htmlspecialchars($activePeriod['semester'] ?? ''); ?>" />
                                <small class="muted-note">Semester is set automatically based on the current evaluation schedule.</small>
                            </div>
                            <div class="form-group">
                                <label for="academic_year">Academic Year:</label>
                                <input type="text" name="academic_year" value="<?php echo htmlspecialchars($activePeriod['academic_year'] ?? ''); ?>" placeholder="e.g., 2025-2026" required readonly />
                            </div>

                            <div class="evaluation-criteria">
                                <h3 class="section-title">Evaluation Criteria</h3>
                                <p><strong>Teaching Efficiency Rating Scale</strong></p>
                                <p>Rate each statement on a scale of 5 to 1, where 5 is the highest and 1 is the lowest.</p>
                                <p>
                                    5 &ndash; Outstanding&nbsp;&nbsp;|
                                    4 &ndash; Very Satisfactory&nbsp;&nbsp;|
                                    3 &ndash; Satisfactory&nbsp;&nbsp;|
                                    2 &ndash; Fair&nbsp;&nbsp;|
                                    1 &ndash; Poor
                                </p>
                                <?php foreach ($crit_by_cat as $cat => $items): ?>
                                    <div class="criteria-category">
                                        <h4><?php echo htmlspecialchars($cat); ?></h4>
                                        <?php foreach ($items as $it): ?>
                                            <div class="criterion-item">
                                                <label><?php echo htmlspecialchars($it['criterion']); ?></label>
                                                <div class="rating-scale">
                                                    <?php for ($i = 5; $i >= 1; $i--): ?>
                                                        <label class="rating-option">
                                                            <input type="radio" name="rating_<?php echo (int)$it['id']; ?>" value="<?php echo $i; ?>" required>
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
                                <label>Overall Comments:</label>
                                <textarea name="overall_comments" rows="4" placeholder="Share your overall thoughts about your performance for this subject"></textarea>
                            </div>
                            <div class="form-group">
                                <button type="button" class="btn-primary" onclick="submitSelfEvaluation(this)">Submit Self-Evaluation</button>
                            </div>
                            <div class="success-message" style="display:none;"></div>
                            <div class="error-message" style="display:none;"></div>
                        </form>
                    </div>
                    <?php else: ?>
                    <div class="error-message">Evaluations are currently closed. Please wait for the schedule to open.</div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>

            <!-- My Self-Evaluation Section -->
            <div id="my-self-evaluations-section" class="content-section" style="display:none;">
                <h2>My Self-Evaluation</h2>
                <?php if (empty($self_evals)): ?>
                    <p>No self-evaluations submitted yet.</p>
                <?php else: ?>
                    <table class="evaluations-table">
                        <thead>
                            <tr>
                                <th>Subject</th>
                                <th>Semester</th>
                                <th>Academic Year</th>
                                <th>Overall Rating</th>
                                <th>Submitted</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($self_evals as $se): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars(($se['subject_code'] ? $se['subject_code'].' - ' : '').$se['subject_name']); ?></td>
                                    <td><?php echo htmlspecialchars($se['semester']); ?></td>
                                    <td><?php echo htmlspecialchars($se['academic_year']); ?></td>
                                    <td><?php echo $se['overall_rating'] ? number_format($se['overall_rating'],2) : 'N/A'; ?></td>
                                    <td><?php echo $se['submitted_at'] ? date('M j, Y', strtotime($se['submitted_at'])) : 'N/A'; ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>

            <!-- Analytics Section -->
            <div id="analytics-section" class="content-section" style="display: none;">
                <h2>Performance Analytics</h2>

                <!-- Filters -->
                <div class="section-card" style="display:grid; gap:1rem;">
                    <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap:1rem;">
                        <div class="form-control">
                            <label for="analytics-term">Semester</label>
                            <select id="analytics-term">
                                <option value="">All Semesters</option>
                                <?php foreach ($terms as $trm): ?>
                                    <?php 
                                      $sem = $trm['semester'];
                                      $ay = $trm['academic_year'];
                                      $val = $sem . '||' . $ay;
                                      $label = $sem . ' ' . $ay;
                                    ?>
                                    <option value="<?php echo htmlspecialchars($val); ?>"><?php echo htmlspecialchars($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-control">
                            <label for="analytics-subject">Subject</label>
                            <select id="analytics-subject">
                                <option value="">All Subjects</option>
                                <?php 
                                  // Build subject list from assigned subjects (subject_name)
                                  $subjectNames = [];
                                  foreach (($subjects ?? []) as $s) { $subjectNames[$s['subject_name']] = true; }
                                  ksort($subjectNames);
                                  foreach (array_keys($subjectNames) as $sn): ?>
                                    <option value="<?php echo htmlspecialchars($sn); ?>"><?php echo htmlspecialchars($sn); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- Self-Evaluation Status (Active Term) -->
                <?php if (!empty($subjects)): ?>
                <div class="chart-container">
                    <h3>Self-Evaluation Status<?php echo $activePeriod ? ' — '.htmlspecialchars($activePeriod['semester'].' '.$activePeriod['academic_year']) : ''; ?></h3>
                    <table class="evaluations-table">
                        <thead>
                            <tr>
                                <th>Subject</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($subjects as $s): 
                                $code = trim((string)$s['subject_code']);
                                $name = trim((string)$s['subject_name']);
                                $key = ($code !== '' ? $code : 'NO_CODE') . '||' . mb_strtolower($name);
                                $done = !empty($self_eval_status[$key]);
                            ?>
                            <tr>
                                <td><?php echo htmlspecialchars(($code ? $code.' - ' : '').$name); ?></td>
                                <td>
                                    <?php if ($done): ?>
                                        <span class="rating-badge" style="background: var(--secondary-color); color:white;">Evaluated</span>
                                    <?php else: ?>
                                        <span class="rating-badge" style="background: var(--danger-color); color:white;">Pending</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <small class="muted-note">Status reflects submissions for the active evaluation period<?php echo $activePeriod ? '' : ' (no active period detected)'; ?>.</small>
                </div>
                <?php endif; ?>

                <!-- Summary Stats -->
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-number" id="stat-avg">--</div>
                        <div class="stat-label">Average Rating</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-number" id="stat-count">0</div>
                        <div class="stat-label">Total Evaluations</div>
                    </div>
                </div>

                <!-- Charts -->
                <div class="chart-container">
                    <h3>Criteria Averages</h3>
                    <canvas id="criteriaBar" width="400" height="220"></canvas>
                </div>
                <div class="chart-container">
                    <h3>Overall Rating Over Time</h3>
                    <canvas id="overallLine" width="400" height="220"></canvas>
                </div>
            </div>

            <!-- Profile Section -->
            <div id="profile-section" class="content-section" style="display: none;">
                <h2>My Profile</h2>
                <div class="profile-info">
                    <div class="info-group">
                        <label>Full Name:</label>
                        <span><?php echo htmlspecialchars($faculty['full_name']); ?></span>
                    </div>
                    <div class="info-group">
                        <label>Employee ID:</label>
                        <span><?php echo htmlspecialchars($faculty['employee_id']); ?></span>
                    </div>
                    <div class="info-group">
                        <label>Position:</label>
                        <span><?php echo htmlspecialchars($faculty['position']); ?></span>
                    </div>
                    <div class="info-group">
                        <label>Department:</label>
                        <span><?php echo htmlspecialchars($faculty['department']); ?></span>
                    </div>
                    <div class="info-group">
                        <label>Hire Date:</label>
                        <span><?php echo $faculty['hire_date'] ? date('M j, Y', strtotime($faculty['hire_date'])) : 'N/A'; ?></span>
                    </div>
                    <div class="info-group">
                        <label>Assigned Subjects:</label>
                        <span>
                            <?php if (empty($subjects)): ?>
                                <em>No subjects assigned</em>
                            <?php else: ?>
                                <?php foreach ($subjects as $s): ?>
                                    <span style="display:inline-block; background:#eef2f7; color:#334155; padding:4px 10px; border-radius:999px; margin:2px; font-size:12px; border:1px solid #e5e7eb;">
                                        <?php echo htmlspecialchars(($s['subject_code'] ? $s['subject_code'] . ' - ' : '') . $s['subject_name']); ?>
                                    </span>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </span>
                    </div>
                </div>

                <div class="profile-info" style="margin-top:1rem;">
                    <h3 style="margin:0 0 .5rem;">Edit Password</h3>
                    <form id="faculty-change-password-form" class="forgot-card" onsubmit="return false;">
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
                        <div id="fac-pw-msg" class="error-message" style="display:none;"></div>
                        <div id="fac-pw-success" class="success-message" style="display:none; color:#166534; background:#dcfce7; padding:.75rem; border-radius:10px;">Password changed successfully.</div>
                        <button type="submit" class="btn-primary">Save Password</button>
                    </form>
                </div>
            </div>

            <!-- Evaluation QR Codes Section -->
            <div id="qr-section" class="content-section" style="display:none;">
                <h2>Evaluation QR Codes</h2>
                <p>Each QR code links students directly to your evaluation form for the selected subject. They will still be required to log in as students.</p>
                <?php if (empty($subjects)): ?>
                    <p>You have no assigned subjects, so no QR codes can be generated.</p>
                <?php else: ?>
                    <div class="chart-container" style="display:grid; gap:1.5rem;">
                        <?php foreach ($subjects as $s): ?>
                            <?php
                                $code = trim((string)($s['subject_code'] ?? ''));
                                $name = trim((string)$s['subject_name']);
                                $display = ($code ? $code . ' - ' : '') . $name;
                                // Build absolute base URL so QR works on mobile devices
                                $scheme = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
                                $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
                                $baseUrl = $scheme . '://' . $host;
                                // App root is the parent of this script directory (handles /FPES locally and / in production)
                                $scriptDir = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/\\');
                                $appRoot = rtrim(dirname($scriptDir), '/\\');
                                // Ensure leading slash (empty means root)
                                if ($appRoot === '.' || $appRoot === '') { $appRoot = ''; }
                                // Use the QR landing page so students can login or sign up before evaluation
                                $qrTargetUrl = $baseUrl . $appRoot . '/qr_landing.php?faculty_id=' . (int)$_SESSION['faculty_id'] . '&subject_code=' . urlencode($code) . '&subject_name=' . urlencode($name);
                                $qrImgUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=220x220&data=' . urlencode($qrTargetUrl);
                            ?>
                            <div style="display:flex; align-items:center; gap:1.5rem; flex-wrap:wrap; border-bottom:1px solid #e5e7eb; padding-bottom:1rem;">
                                <div>
                                    <h3 style="margin:0 0 .25rem;"><?php echo htmlspecialchars($display); ?></h3>
                                    <p style="margin:0; color:#6b7280; font-size:.9rem;">Students can scan this QR during your class to go straight to the evaluation form for this subject.</p>
                                    <p style="margin:.25rem 0 0; word-break:break-all; font-size:.8rem; color:#9ca3af;">
                                        Link: <?php echo htmlspecialchars($qrTargetUrl); ?>
                                    </p>
                                </div>
                                <div style="text-align:center;">
                                    <img src="<?php echo htmlspecialchars($qrImgUrl); ?>" alt="QR for <?php echo htmlspecialchars($display); ?>" style="background:white; padding:8px; border-radius:8px; border:1px solid #e5e7eb; display:block; margin:0 auto 0.5rem;" />
                                    <a href="download_qr.php?faculty_id=<?php echo (int)$_SESSION['faculty_id']; ?>&subject_code=<?php echo urlencode($code); ?>&subject_name=<?php echo urlencode($name); ?>" style="display:inline-block; padding:0.4rem 0.9rem; border-radius:999px; background:#2563eb; color:#fff; font-size:0.85rem; text-decoration:none;">Download QR</a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Faculty Directory Section removed -->
        </div>
    </div>

    <script>
        // Faculty directory feature removed

        // Mobile sidebar toggle (faculty)
        (function(){
            const btn = document.getElementById('facultySidebarToggle');
            const sidebar = document.querySelector('.sidebar');
            if (!btn || !sidebar) return;

            // Toggle sidebar visibility; keep Menu button always visible
            btn.addEventListener('click', function(){
                sidebar.classList.toggle('active');
            });

            // When a nav link is clicked on small screens, hide only the sidebar
            const links = sidebar.querySelectorAll('a');
            links.forEach(function(link){
                link.addEventListener('click', function(){
                    if (window.innerWidth <= 768 && sidebar.classList.contains('active')) {
                        sidebar.classList.remove('active');
                    }
                });
            });
        })();

        // Navigation functions
        function showSection(sectionName) {
            const sections = document.querySelectorAll('.content-section');
            sections.forEach(section => {
                section.style.display = 'none';
            });
            
            const targetSection = document.getElementById(sectionName + '-section');
            if (targetSection) {
                targetSection.style.display = 'block';
                if (sectionName === 'analytics') {
                    // Lazy init analytics when section first shown
                    if (!window.__ANALYTICS_INIT) { initAnalytics(); window.__ANALYTICS_INIT = true; }
                }
            }

            // On small screens, hide sidebar after navigation
            if (window.innerWidth <= 768) {
                const sidebar = document.querySelector('.sidebar');
                if (sidebar && sidebar.classList.contains('active')) {
                    sidebar.classList.remove('active');
                }
            }
        }

        // Logout function
        function logout() {
            fetch('../auth.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'action=logout'
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    window.location.href = data.redirect;
                }
            })
            .catch(error => {
                console.error('Error:', error);
                window.location.href = '../index.php';
            });
        }

        // My Evaluations view handler removed

        // Handle subject selection for Self-Evaluation
        function onSubjectChangeSelfEval(sel){
            const container = document.getElementById('self-eval-form-container');
            const title = document.getElementById('self-eval-subject-title');
            if (!container || !title) return;
            const opt = sel && sel.options[sel.selectedIndex];
            if (!opt || !sel.value) { container.style.display = 'none'; return; }
            const code = opt.getAttribute('data-code') || '';
            const name = opt.getAttribute('data-name') || sel.value;
            // Populate hidden fields
            const form = container.querySelector('form');
            if (form) {
                const sc = form.querySelector('input[name="subject_code"]');
                const sn = form.querySelector('input[name="subject_name"]');
                if (sc) sc.value = code;
                if (sn) sn.value = name;
                // Reset previous selections
                form.querySelectorAll('input[type="radio"]').forEach(r=>{ r.checked = false; });
                const ok = form.querySelector('.success-message'); if (ok) { ok.style.display='none'; ok.textContent=''; }
                const err = form.querySelector('.error-message'); if (err) { err.style.display='none'; err.textContent=''; }
                const oc = form.querySelector('textarea[name="overall_comments"]'); if (oc) oc.value = '';
            }
            // Update title and show
            const display = (code ? code + ' - ' : '') + name;
            title.textContent = 'Self-Evaluation: ' + display;
            container.style.display = 'block';
        }

        // Submit Self-Evaluation
        function submitSelfEvaluation(btn){
            const form = btn.closest('form');
            if (!form) return;
            const ok = form.querySelector('.success-message');
            const err = form.querySelector('.error-message');
            if (ok) { ok.style.display='none'; ok.textContent=''; }
            if (err) { err.style.display='none'; err.textContent=''; }
            const fd = new FormData(form);
            fetch('submit_self_evaluation.php', { method:'POST', body: fd })
              .then(r=>r.json())
              .then(data=>{
                 if (data.success) {
                    if (ok) { ok.textContent = data.message || 'Self-evaluation submitted.'; ok.style.display='block'; }
                 } else {
                    if (err) { err.textContent = data.message || 'Failed to submit self-evaluation.'; err.style.display='block'; }
                 }
              })
              .catch(e=>{
                 if (err) { err.textContent = 'Network error while submitting.'; err.style.display='block'; }
              });
        }

        // Initialize charts
        document.addEventListener('DOMContentLoaded', function() {
            // Performance trend chart
            <?php if (!empty($semester_performance)): ?>
            const performanceCtx = document.getElementById('performanceChart');
            if (performanceCtx) {
                new Chart(performanceCtx, {
                    type: 'line',
                    data: {
                        labels: [<?php echo implode(',', array_map(function($p) { return "'" . $p['semester'] . " " . $p['academic_year'] . "'"; }, $semester_performance)); ?>],
                        datasets: [{
                            label: 'Average Rating',
                            data: [<?php echo implode(',', array_column($semester_performance, 'avg_rating')); ?>],
                            borderColor: 'rgb(52, 152, 219)',
                            backgroundColor: 'rgba(52, 152, 219, 0.1)',
                            tension: 0.1
                        }]
                    },
                    options: {
                        responsive: true,
                        scales: {
                            y: {
                                beginAtZero: true,
                                max: 5
                            }
                        }
                    }
                });
            }
            <?php endif; ?>

            // Criteria performance chart
            <?php if (!empty($criteria_ratings)): ?>
            const criteriaCtx = document.getElementById('criteriaChart');
            if (criteriaCtx) {
                new Chart(criteriaCtx, {
                    type: 'bar',
                    data: {
                        labels: [<?php echo implode(',', array_map(function($r) { return "'" . addslashes($r['criterion']) . "'"; }, $criteria_ratings)); ?>],
                        datasets: [{
                            label: 'Average Rating',
                            data: [<?php echo implode(',', array_column($criteria_ratings, 'avg_rating')); ?>],
                            backgroundColor: 'rgba(46, 204, 113, 0.8)',
                            borderColor: 'rgb(46, 204, 113)',
                            borderWidth: 1
                        }]
                    },
                    options: {
                        responsive: true,
                        scales: {
                            y: {
                                beginAtZero: true,
                                max: 5
                            }
                        }
                    }
                });
            }
            <?php endif; ?>

            // Show overview section by default
            showSection('overview');
        });

        // Password change handler (Faculty)
        (function(){
            const form = document.getElementById('faculty-change-password-form');
            if (!form) return;
            const err = document.getElementById('fac-pw-msg');
            const ok = document.getElementById('fac-pw-success');
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
    <script>
      // Analytics: charts and filters
      let criteriaChart = null;
      let overallChart = null;

      function initAnalytics() {
        try {
          const termSel = document.getElementById('analytics-term');
          const subjSel = document.getElementById('analytics-subject');
          const onChange = () => loadAnalytics();
          if (termSel) termSel.addEventListener('change', onChange);
          if (subjSel) subjSel.addEventListener('change', onChange);
          loadAnalytics();
        } catch (e) {
          console.warn('Analytics init failed:', e);
        }
      }

      function loadAnalytics() {
        const termSel = document.getElementById('analytics-term');
        const subjSel = document.getElementById('analytics-subject');
        let semester = '';
        let academic_year = '';
        const termVal = termSel ? (termSel.value || '') : '';
        if (termVal) { const parts = termVal.split('||'); semester = parts[0] || ''; academic_year = parts[1] || ''; }
        const subject = subjSel ? (subjSel.value || '') : '';

        const qs = new URLSearchParams();
        if (semester) qs.set('semester', semester);
        if (academic_year) qs.set('academic_year', academic_year);
        if (subject) qs.set('subject', subject);

        fetch('analytics_data.php' + (qs.toString() ? ('?' + qs.toString()) : ''))
          .then(r => r.json())
          .then(data => {
            try {
              if (!data.success) throw new Error(data.message || 'Failed to load analytics');
              renderSummary(data.summary);
              renderCriteriaChart(data.criteria);
              renderOverallChart(data.timeseries);
            } catch (e) {
              console.warn('Analytics render failed:', e);
              renderSummary({ evaluations_count: 0, avg_overall_rating: null });
            }
          })
          .catch(err => {
            console.warn('Analytics request failed:', err);
            renderSummary({ evaluations_count: 0, avg_overall_rating: null });
          });
      }

      function renderSummary(sum) {
        const avgEl = document.getElementById('stat-avg');
        const cntEl = document.getElementById('stat-count');
        if (avgEl) avgEl.textContent = (sum && sum.avg_overall_rating != null) ? Number(sum.avg_overall_rating).toFixed(2) : '--';
        if (cntEl) cntEl.textContent = (sum && sum.evaluations_count != null) ? sum.evaluations_count : 0;
      }

      function renderCriteriaChart(rows) {
        const ctx = document.getElementById('criteriaBar');
        if (!ctx || typeof Chart === 'undefined') return;
        const labels = rows.map(r => r.category + ' — ' + r.criterion);
        const values = rows.map(r => Number(r.avg_rating || 0));

        if (criteriaChart) { criteriaChart.destroy(); }
        criteriaChart = new Chart(ctx, {
          type: 'bar',
          data: {
            labels,
            datasets: [{
              label: 'Average Rating',
              data: values,
              backgroundColor: 'rgba(52, 152, 219, 0.5)',
              borderColor: 'rgba(52, 152, 219, 1)',
              borderWidth: 1
            }]
          },
          options: {
            responsive: true,
            scales: {
              y: { beginAtZero: true, suggestedMax: 5, ticks: { stepSize: 1 } }
            },
            plugins: { legend: { display: true } }
          }
        });
      }

      function renderOverallChart(rows) {
        const ctx = document.getElementById('overallLine');
        if (!ctx || typeof Chart === 'undefined') return;
        const labels = rows.map(r => r.day);
        const values = rows.map(r => Number(r.avg_rating || 0));

        if (overallChart) { overallChart.destroy(); }
        overallChart = new Chart(ctx, {
          type: 'line',
          data: {
            labels,
            datasets: [{
              label: 'Avg Overall Rating',
              data: values,
              fill: false,
              borderColor: 'rgba(46, 204, 113, 1)',
              backgroundColor: 'rgba(46, 204, 113, 0.2)',
              tension: 0.2
            }]
          },
          options: {
            responsive: true,
            scales: {
              y: { beginAtZero: true, suggestedMax: 5, ticks: { stepSize: 1 } }
            },
            plugins: { legend: { display: true } }
          }
        });
      }
    </script>
</body>
</html>
