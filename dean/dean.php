<?php
require_once '../config.php';
requireRole('dean');

// Get dean info
$stmt = $pdo->prepare("SELECT u.full_name, u.department FROM users u WHERE u.id = ?");
$stmt->execute([$_SESSION['user_id']]);
$dean = $stmt->fetch();
// Current dean's department for scoping
$department = $dean['department'] ?? '';

// Evaluation schedule state and active period for UI control
list($evalOpen, $evalState, $evalReason, $evalSchedule) = isEvaluationOpenForStudents($pdo);
$activePeriod = $evalOpen ? getActiveSemesterYear($pdo) : null;

// Get overall statistics (scoped to dean's department)
$department = trim($department);
$stmt = $pdo->prepare("SELECT 
                        COUNT(DISTINCT e.id) as total_evaluations,
                        COUNT(DISTINCT e.faculty_id) as evaluated_faculty,
                        COUNT(DISTINCT f.id) as total_faculty,
                        AVG(e.overall_rating) as avg_rating
                       FROM faculty f
                       JOIN users u ON f.user_id = u.id
                       LEFT JOIN evaluations e ON e.faculty_id = f.id AND e.status = 'submitted' AND COALESCE(e.is_counted,1) = 1
                       WHERE u.department = ? AND u.department IS NOT NULL AND u.department <> ''");
$stmt->execute([$department]);
$overall_stats = $stmt->fetch();

// Get faculty performance summary (scoped)
$stmt = $pdo->prepare("SELECT 
                        f.id, u.full_name, u.department, f.position,
                        COUNT(e.id) as evaluation_count,
                        AVG(e.overall_rating) as avg_rating,
                        MIN(e.overall_rating) as min_rating,
                        MAX(e.overall_rating) as max_rating
                       FROM faculty f
                       JOIN users u ON f.user_id = u.id
                       LEFT JOIN evaluations e ON f.id = e.faculty_id AND e.status = 'submitted' AND COALESCE(e.is_counted,1) = 1
                       WHERE u.department = ? AND u.department IS NOT NULL AND u.department <> ''
                       GROUP BY f.id, u.full_name, u.department, f.position
                       ORDER BY avg_rating DESC");
$stmt->execute([$department]);
$faculty_performance = $stmt->fetchAll();

// Get department-wise statistics (only this dean's department)
$stmt = $pdo->prepare("SELECT 
                        u.department,
                        COUNT(DISTINCT f.id) as faculty_count,
                        COUNT(e.id) as evaluation_count,
                        AVG(e.overall_rating) as avg_rating
                       FROM faculty f
                       JOIN users u ON f.user_id = u.id
                       LEFT JOIN evaluations e ON f.id = e.faculty_id AND e.status = 'submitted' AND COALESCE(e.is_counted,1) = 1
                       WHERE u.department = ? AND u.department IS NOT NULL AND u.department <> ''
                       GROUP BY u.department
                       ORDER BY avg_rating DESC");
$stmt->execute([$department]);
$department_stats = $stmt->fetchAll();

// Get evaluation trends by semester (scoped)
$stmt = $pdo->prepare("SELECT 
                        e.academic_year, e.semester,
                        COUNT(*) as evaluation_count,
                        AVG(e.overall_rating) as avg_rating
                       FROM evaluations e
                       JOIN faculty f ON e.faculty_id = f.id
                       JOIN users u ON f.user_id = u.id
                       WHERE e.status = 'submitted' AND COALESCE(e.is_counted,1) = 1 AND u.department = ? AND u.department IS NOT NULL AND u.department <> ''
                       GROUP BY e.academic_year, e.semester
                       ORDER BY e.academic_year DESC, e.semester");
$stmt->execute([$department]);
$semester_trends = $stmt->fetchAll();

// Get top and bottom performing faculty (scoped)
$stmt = $pdo->prepare("SELECT 
                        u.full_name, u.department, f.position,
                        AVG(e.overall_rating) as avg_rating,
                        COUNT(e.id) as evaluation_count
                       FROM faculty f
                       JOIN users u ON f.user_id = u.id
                       JOIN evaluations e ON f.id = e.faculty_id AND e.status = 'submitted' AND COALESCE(e.is_counted,1) = 1
                       WHERE u.department = ? AND u.department IS NOT NULL AND u.department <> ''
                       GROUP BY f.id, u.full_name, u.department, f.position
                       HAVING COUNT(e.id) >= 3
                       ORDER BY avg_rating DESC
                       LIMIT 10");
$stmt->execute([$department]);
$top_performers = $stmt->fetchAll();

$stmt = $pdo->prepare("SELECT 
                        u.full_name, u.department, f.position,
                        AVG(e.overall_rating) as avg_rating,
                        COUNT(e.id) as evaluation_count
                       FROM faculty f
                       JOIN users u ON f.user_id = u.id
                       JOIN evaluations e ON f.id = e.faculty_id AND e.status = 'submitted' AND COALESCE(e.is_counted,1) = 1
                       WHERE u.department = ? AND u.department IS NOT NULL AND u.department <> ''
                       GROUP BY f.id, u.full_name, u.department, f.position
                       HAVING COUNT(e.id) >= 3
                       ORDER BY avg_rating ASC
                       LIMIT 5");
$stmt->execute([$department]);
$bottom_performers = $stmt->fetchAll();

// Ensure evaluation criteria are up to date (auto-migrate legacy defaults if needed)
if (function_exists('ensureNewEvaluationCriteria')) {
    ensureNewEvaluationCriteria($pdo);
}

// Get criteria performance across all faculty (scoped)
$stmt = $pdo->prepare("SELECT 
                        ec.category, ec.criterion,
                        AVG(er.rating) as avg_rating,
                        COUNT(er.rating) as response_count
                       FROM evaluation_responses er
                       JOIN evaluation_criteria ec ON er.criterion_id = ec.id
                       JOIN evaluations e ON er.evaluation_id = e.id
                       JOIN faculty f ON e.faculty_id = f.id
                       JOIN users u ON f.user_id = u.id
                       WHERE e.status = 'submitted' AND COALESCE(e.is_counted,1) = 1 AND u.department = ? AND u.department IS NOT NULL AND u.department <> ''
                       GROUP BY ec.id, ec.category, ec.criterion
                       ORDER BY ec.category, avg_rating DESC");
$stmt->execute([$department]);
$criteria_performance = $stmt->fetchAll();

// Group criteria by category
$grouped_criteria = [];
foreach ($criteria_performance as $criterion) {
    $grouped_criteria[$criterion['category']][] = $criterion;
}

// Load department-scoped faculty for evaluation form
$stmt = $pdo->prepare("SELECT f.id AS faculty_id, u.full_name
                       FROM faculty f
                       JOIN users u ON f.user_id = u.id
                       WHERE u.department = ? AND u.department IS NOT NULL AND u.department <> ''
                       ORDER BY u.full_name");
$stmt->execute([$department]);
$dept_faculty = $stmt->fetchAll();

// Attempt to load subjects for this department (optional; fallback to manual entry)
$dept_subjects = [];
try {
    $stmt = $pdo->prepare("SELECT code, name FROM subjects WHERE department = ? AND department IS NOT NULL AND department <> '' ORDER BY name");
    $stmt->execute([$department]);
    $dept_subjects = $stmt->fetchAll();
} catch (PDOException $e) {
    $dept_subjects = [];
}

// Load criteria list with IDs for the evaluation form
$criteria_by_category = [];
try {
    $stmt = $pdo->prepare("SELECT id, category, criterion FROM evaluation_criteria ORDER BY category, id");
    $stmt->execute();
    $rows = $stmt->fetchAll();
    foreach ($rows as $r) {
        $criteria_by_category[$r['category']][] = $r;
    }
} catch (PDOException $e) {
    $criteria_by_category = [];
}

// Load this dean's submitted evaluations for client filtering and "My Evaluations"
$my_evaluations = [];
try {
    $stmt = $pdo->prepare("SELECT e.id, e.faculty_id, e.subject, e.semester, e.academic_year, e.submitted_at,
                                   u.full_name AS faculty_name, e.status, e.overall_rating
                            FROM evaluations e
                            JOIN faculty f ON e.faculty_id = f.id
                            JOIN users u ON f.user_id = u.id
                            WHERE e.status = 'submitted' AND e.evaluator_user_id = ? AND e.evaluator_role = 'dean'");
    $stmt->execute([$_SESSION['user_id']]);
    $my_evaluations = $stmt->fetchAll();
} catch (PDOException $e) {
    $my_evaluations = [];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dean Dashboard - Faculty Performance Analytics</title>
    <link rel="icon" href="../img/loginlogo.png?v=2" type="image/png">
    <link rel="stylesheet" href="../styles.css">
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

        /* Make the evaluation trends chart smaller on screen */
        #trendsChart {
            max-height: 260px;
        }
        
        .performance-table {
            width: 100%;
            border-collapse: collapse;
            background: white;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: var(--card-shadow);
        }
        
        .performance-table th, .performance-table td {
            padding: 1rem;
            text-align: left;
            border-bottom: 1px solid #e1e5e9;
        }
        
        .performance-table th {
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
        .rating-none { background: var(--gray-color); color: white; }
        
        .two-column {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 2rem;
        }
        
        .criteria-section {
            background: white;
            padding: 1.5rem;
            border-radius: 12px;
            box-shadow: var(--card-shadow);
            margin-bottom: 2rem;
        }
        
        .criteria-category {
            margin-bottom: 1.5rem;
        }
        
        .criteria-category h4 {
            color: var(--primary-color);
            margin-bottom: 1rem;
        }
        
        .criterion-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.5rem 0;
            border-bottom: 1px solid #f0f0f0;
        }
        
        .criterion-item:last-child {
            border-bottom: none;
        }
        
        .export-buttons {
            margin-bottom: 2rem;
        }
        
        .export-btn {
            background: var(--secondary-color);
            color: white;
            padding: 0.75rem 1.5rem;
            border: none;
            border-radius: 8px;
            margin-right: 1rem;
            cursor: pointer;
            transition: var(--transition);
        }
        
        .export-btn:hover {
            background: var(--secondary-dark);
        }

        /* Evaluation Form Redesign */
        .section-card {
            background: #fff;
            border-radius: 12px;
            box-shadow: var(--card-shadow);
            padding: 1.25rem;
            margin-bottom: 1.5rem;
        }
        .section-card h3 {
            margin: 0 0 0.75rem 0;
            color: var(--primary-color);
        }
        .form-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(240px, 1fr));
            gap: 1rem 1.25rem;
        }
        @media (max-width: 768px) {
            .form-grid { grid-template-columns: 1fr; }
            .submit-btn { width: 100%; }
        }
        .form-control label { display: block; font-weight: 600; margin-bottom: 0.35rem; }
        .form-control select,
        .form-control input[type="text"],
        .form-control textarea { width: 100%; padding: 0.6rem 0.7rem; border: 1px solid #e1e5e9; border-radius: 8px; }

        .criteria-card { background: #fff; border-radius: 12px; box-shadow: var(--card-shadow); padding: 1rem; }
        .criteria-group { margin-bottom: 1rem; }
        .criteria-group h4 { margin: 0 0 0.5rem 0; color: var(--primary-color); }
        .criterion-row { display: grid; grid-template-columns: 1fr auto; align-items: center; padding: 0.5rem 0; border-bottom: 1px solid #f0f2f4; }
        .criterion-row:last-child { border-bottom: none; }
        .rating-group { display: flex; gap: 0.75rem; align-items: center; }
        .rating-group label { display: inline-flex; align-items: center; gap: 0.25rem; font-weight: 500; }
        .criterion-comment { margin-top: 0.5rem; }
        .criterion-comment input { width: 100%; padding: 0.45rem 0.6rem; border: 1px solid #e1e5e9; border-radius: 6px; }

        .submit-btn {
            background: #2ecc71; /* green */
            color: #fff;
            padding: 0.9rem 1.25rem;
            border: none;
            border-radius: 10px;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.2s ease;
        }
        .submit-btn:hover { background: #27ae60; }
        .muted-note { color: #6b7280; font-size: 0.9rem; margin-top: 0.5rem; }
    </style>
</head>
<body>
    <button class="sidebar-toggle" id="deanSidebarToggle" aria-label="Toggle navigation">☰ Menu</button>
    <div class="dashboard">
        <div class="sidebar">
            <h2>Dean Portal</h2>
            <a href="#" onclick="showSection('overview')">Overview</a>
            <a href="#" onclick="showSection('faculty')">Faculty Performance</a>
            <a href="#" onclick="showSection('departments')">Department Analytics</a>
            <a href="#" onclick="showSection('trends')">Trends & Reports</a>
            <a href="#" onclick="showSection('criteria')">Criteria Analysis</a>
            <a href="#" onclick="showSection('evaluate')">Evaluate Faculty</a>
            <a href="#" onclick="showSection('my_evals')">My Evaluations</a>
            <a href="#" onclick="showSection('profile')">Profile</a>
            <button class="logout-btn" onclick="logout()">Logout</button>
        </div>

        <div class="main-content">
            <div class="welcome-header">
                <h1>Welcome, <?php echo htmlspecialchars($dean['full_name']); ?>!</h1>
                <p>Dean of <?php echo htmlspecialchars($dean['department']); ?> | Faculty Performance Analytics Dashboard</p>
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
                <h2>System Overview</h2>
                
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-number"><?php echo $overall_stats['total_evaluations'] ?? 0; ?></div>
                        <div class="stat-label">Total Evaluations</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-number"><?php echo $overall_stats['evaluated_faculty'] ?? 0; ?></div>
                        <div class="stat-label">Faculty Evaluated</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-number"><?php echo $overall_stats['total_faculty'] ?? 0; ?></div>
                        <div class="stat-label">Total Faculty</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-number"><?php echo ($overall_stats['avg_rating'] ?? null) ? number_format($overall_stats['avg_rating'], 2) : 'N/A'; ?></div>
                        <div class="stat-label">Department Rating<?php 
                            $cnt = (int)($overall_stats['total_evaluations'] ?? 0);
                            if ($cnt > 0) echo ' ('.$cnt.' evals)';
                        ?></div>
                    </div>
                </div>

                <div class="two-column">
                    <div class="chart-container">
                        <h3>Top Performers</h3>
                        <?php if (!empty($top_performers)): ?>
                            <table class="performance-table">
                                <thead>
                                    <tr>
                                        <th>Faculty</th>
                                        <th>Department</th>
                                        <th>Rating</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach (array_slice($top_performers, 0, 5) as $performer): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($performer['full_name']); ?></td>
                                            <td><?php echo htmlspecialchars($performer['department']); ?></td>
                                            <td>
                                                <span class="rating-badge rating-excellent">
                                                    <?php echo number_format($performer['avg_rating'], 2); ?>
                                                </span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php else: ?>
                            <p>No performance data available yet.</p>
                        <?php endif; ?>
                    </div>

                    <div class="chart-container">
                        <h3>Department Performance</h3>
                        <canvas id="departmentChart" width="400" height="300"></canvas>
                    </div>
                </div>
            </div>

            <!-- Faculty Performance Section -->
            <div id="faculty-section" class="content-section" style="display: none;">
                <h2>Faculty Performance Analysis</h2>
                
                <div class="search-container">
                    <input type="text" id="faculty_search" placeholder="Search faculty by name, department, or position..." onkeyup="filterFacultyTable()">
                </div>

                <div class="export-buttons">
                    <button class="export-btn" onclick="exportToCSV()">Export to CSV</button>
                    <button class="export-btn" onclick="generateReport()">Generate Report</button>
                </div>

                <table class="performance-table" id="faculty_table">
                    <thead>
                        <tr>
                            <th>Faculty Name</th>
                            <th>Department</th>
                            <th>Position</th>
                            <th>Evaluations</th>
                            <th>Average Rating</th>
                            <th>Min Rating</th>
                            <th>Max Rating</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($faculty_performance as $faculty): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($faculty['full_name']); ?></td>
                                <td><?php echo htmlspecialchars($faculty['department']); ?></td>
                                <td><?php echo htmlspecialchars($faculty['position']); ?></td>
                                <td><?php echo $faculty['evaluation_count']; ?></td>
                                <td>
                                    <?php if ($faculty['avg_rating']): ?>
                                        <span class="rating-badge rating-<?php 
                                            $rating = $faculty['avg_rating'];
                                            if ($rating >= 4.5) echo 'excellent';
                                            elseif ($rating >= 3.5) echo 'good';
                                            elseif ($rating >= 2.5) echo 'average';
                                            else echo 'poor';
                                        ?>">
                                            <?php echo number_format($faculty['avg_rating'], 2); ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="rating-badge rating-none">No Data</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo $faculty['min_rating'] ? number_format($faculty['min_rating'], 2) : 'N/A'; ?></td>
                                <td><?php echo $faculty['max_rating'] ? number_format($faculty['max_rating'], 2) : 'N/A'; ?></td>
                                <td>
                                    <?php if ($faculty['evaluation_count'] == 0): ?>
                                        <span style="color: var(--danger-color);">Not Evaluated</span>
                                    <?php elseif ($faculty['evaluation_count'] < 3): ?>
                                        <span style="color: var(--warning-color);">Needs More Data</span>
                                    <?php else: ?>
                                        <span style="color: var(--secondary-color);">Sufficient Data</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <!-- Department Analytics Section -->
            <div id="departments-section" class="content-section" style="display: none;">
                <h2>Department Analytics</h2>
                
                <div class="section-header">
                    <h2>Faculty Performance Overview</h2>
                    <p>Comprehensive performance analysis of all faculty members</p>
                </div>

                <div class="search-container">
                    <input type="text" id="faculty_search" placeholder="Search faculty by name, department, or position..." onkeyup="filterFacultyTable()">
                </div>

                <table class="performance-table" id="faculty_table">
                    <thead>
                        <tr>
                            <th>Department</th>
                            <th>Faculty Count</th>
                            <th>Total Evaluations</th>
                            <th>Average Rating</th>
                            <th>Performance Level</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($department_stats as $dept): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($dept['department']); ?></td>
                                <td><?php echo $dept['faculty_count']; ?></td>
                                <td><?php echo $dept['evaluation_count']; ?></td>
                                <td>
                                    <?php if ($dept['avg_rating']): ?>
                                        <?php echo number_format($dept['avg_rating'], 2); ?>
                                    <?php else: ?>
                                        N/A
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($dept['avg_rating']): ?>
                                        <span class="rating-badge rating-<?php 
                                            $rating = $dept['avg_rating'];
                                            if ($rating >= 4.5) echo 'excellent';
                                            elseif ($rating >= 3.5) echo 'good';
                                            elseif ($rating >= 2.5) echo 'average';
                                            else echo 'poor';
                                        ?>">
                                            <?php 
                                                if ($rating >= 4.5) echo 'Excellent';
                                                elseif ($rating >= 3.5) echo 'Good';
                                                elseif ($rating >= 2.5) echo 'Average';
                                                else echo 'Needs Improvement';
                                            ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="rating-badge rating-none">No Data</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Trends & Reports Section -->
            <div id="trends-section" class="content-section" style="display: none;">
                <h2>Trends & Reports</h2>
                
                <?php if (!empty($semester_trends)): ?>
                <div class="chart-container" style="max-width: 900px; margin: 0 auto 2rem auto;">
                    <h3>Evaluation Trends by Semester</h3>
                    <canvas id="trendsChart" width="400" height="160"></canvas>
                </div>
                <?php endif; ?>

                <div class="two-column">
                    <div class="chart-container">
                        <h3>Faculty Needing Attention</h3>
                        <?php if (!empty($bottom_performers)): ?>
                            <table class="performance-table">
                                <thead>
                                    <tr>
                                        <th>Faculty</th>
                                        <th>Department</th>
                                        <th>Rating</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($bottom_performers as $performer): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($performer['full_name']); ?></td>
                                            <td><?php echo htmlspecialchars($performer['department']); ?></td>
                                            <td>
                                                <span class="rating-badge rating-<?php 
                                                    $rating = $performer['avg_rating'];
                                                    if ($rating >= 3.5) echo 'good';
                                                    elseif ($rating >= 2.5) echo 'average';
                                                    else echo 'poor';
                                                ?>">
                                                    <?php echo number_format($performer['avg_rating'], 2); ?>
                                                </span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php else: ?>
                            <p>All faculty are performing well!</p>
                        <?php endif; ?>
                    </div>

                    <div class="chart-container">
                        <h3>Semester Performance Summary</h3>
                        <table class="performance-table">
                            <thead>
                                <tr>
                                    <th>Academic Year</th>
                                    <th>Semester</th>
                                    <th>Evaluations</th>
                                    <th>Avg Rating</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($semester_trends as $trend): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($trend['academic_year']); ?></td>
                                        <td><?php echo htmlspecialchars($trend['semester']); ?></td>
                                        <td><?php echo $trend['evaluation_count']; ?></td>
                                        <td><?php echo number_format($trend['avg_rating'], 2); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Criteria Analysis Section -->
            <div id="criteria-section" class="content-section" style="display: none;">
                <h2>Evaluation Criteria Analysis</h2>
                
                <div class="chart-container">
                    <h3>Overall Criteria Performance</h3>
                    <canvas id="criteriaChart" width="400" height="200"></canvas>
                </div>

                <div class="criteria-section">
                    <?php foreach ($grouped_criteria as $category => $criteria): ?>
                        <div class="criteria-category">
                            <h4><?php echo htmlspecialchars($category); ?></h4>
                            <?php foreach ($criteria as $criterion): ?>
                                <div class="criterion-item">
                                    <span><?php echo htmlspecialchars($criterion['criterion']); ?></span>
                                    <span class="rating-badge rating-<?php 
                                        $rating = $criterion['avg_rating'];
                                        if ($rating >= 4.5) echo 'excellent';
                                        elseif ($rating >= 3.5) echo 'good';
                                        elseif ($rating >= 2.5) echo 'average';
                                        else echo 'poor';
                                    ?>">
                                        <?php echo number_format($criterion['avg_rating'], 2); ?> 
                                        (<?php echo $criterion['response_count']; ?> responses)
                                    </span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Evaluate Faculty Section -->
            <div id="evaluate-section" class="content-section" style="display: none;">
                <h2>Evaluate Faculty (<?php echo htmlspecialchars($dean['department']); ?> Department)</h2>
                <form id="dean-eval-form" <?php echo $evalOpen ? '' : 'style="opacity:.6; pointer-events:none;"'; ?>>
                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">

                    <!-- Faculty & Subject Selection -->
                    <div class="section-card">
                        <h3>Faculty & Subject Selection</h3>
                        <div class="form-grid">
                            <div class="form-control">
                                <label for="faculty_id">Faculty</label>
                                <select name="faculty_id" id="faculty_id" required>
                                    <option value="">-- Select Faculty --</option>
                                    <?php foreach ($dept_faculty as $f): ?>
                                        <option value="<?php echo (int)$f['faculty_id']; ?>"><?php echo htmlspecialchars($f['full_name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-control">
                                <label for="subject">Subject</label>
                                <?php if (!empty($dept_subjects)): ?>
                                    <select name="subject" id="subject" required>
                                        <option value="">-- Select Subject --</option>
                                        <?php foreach ($dept_subjects as $s): ?>
                                            <option value="<?php echo htmlspecialchars(($s['code'] ? $s['code'].' - ' : '').$s['name']); ?>">
                                                <?php echo htmlspecialchars(($s['code'] ? $s['code'].' - ' : '').$s['name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                <?php else: ?>
                                    <input type="text" name="subject" id="subject" placeholder="Enter subject" required />
                                <?php endif; ?>
                            </div>
                            <div class="form-control">
                                <label for="semester">Semester</label>
                                <select name="semester" id="semester" required disabled>
                                    <option value="">-- Select Semester --</option>
                                    <option value="1st Semester" <?php echo ($activePeriod && $activePeriod['semester']==='1st Semester') ? 'selected' : ''; ?>>1st Semester</option>
                                    <option value="2nd Semester" <?php echo ($activePeriod && $activePeriod['semester']==='2nd Semester') ? 'selected' : ''; ?>>2nd Semester</option>
                                </select>
                                <small class="muted-note">Semester is set automatically based on the current evaluation schedule.</small>
                            </div>
                            <div class="form-control">
                                <label for="academic_year">Academic Year</label>
                                <input type="text" name="academic_year" id="academic_year" value="<?php echo htmlspecialchars($activePeriod['academic_year'] ?? ''); ?>" placeholder="e.g., 2025-2026" required readonly />
                            </div>
                        </div>
                    </div>

                    <!-- Evaluation Criteria -->
                    <div class="section-card">
                        <h3>Evaluation Criteria</h3>
                        <p><strong>Teaching Efficiency Rating Scale</strong></p>
                        <p>Rate each statement on a scale of 5 to 1, where 5 is the highest and 1 is the lowest.</p>
                        <p>
                            5 &ndash; Outstanding&nbsp;&nbsp;|
                            4 &ndash; Very Satisfactory&nbsp;&nbsp;|
                            3 &ndash; Satisfactory&nbsp;&nbsp;|
                            2 &ndash; Fair&nbsp;&nbsp;|
                            1 &ndash; Poor
                        </p>
                        <div class="criteria-card">
                            <?php if (empty($criteria_by_category)): ?>
                                <p>No evaluation criteria configured yet.</p>
                            <?php else: ?>
                                <?php foreach ($criteria_by_category as $cat => $items): ?>
                                    <div class="criteria-group">
                                        <h4><?php echo htmlspecialchars($cat); ?></h4>
                                        <?php foreach ($items as $ci): ?>
                                            <div class="criterion-row">
                                                <div class="criterion-text"><?php echo htmlspecialchars($ci['criterion']); ?></div>
                                                <div class="rating-group" role="group" aria-label="Rating for criterion">
                                                    <?php for ($r=5; $r>=1; $r--): ?>
                                                        <label>
                                                            <input type="radio" name="rating_<?php echo (int)$ci['id']; ?>" value="<?php echo $r; ?>" required /> <?php echo $r; ?>
                                                        </label>
                                                    <?php endfor; ?>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Overall Comments -->
                    <div class="section-card">
                        <h3>Overall Comments</h3>
                        <div class="form-control">
                            <label for="overall_comments" class="sr-only">Overall Comments</label>
                            <textarea name="overall_comments" id="overall_comments" rows="4" placeholder="Write your overall feedback (optional)..."></textarea>
                        </div>
                    </div>

                    <div class="section-card" style="display:flex; align-items:center; justify-content: space-between; gap: 1rem;">
                        <button type="submit" class="submit-btn" id="dean-submit-btn">Submit Evaluation</button>
                        <p id="eval-status" class="muted-note" style="margin: 0;"></p>
                    </div>

                    <p class="muted-note">Dean evaluations are anonymous and flagged as Dean evaluations.</p>
                </form>
            </div>

            <!-- My Evaluations Section -->
            <div id="my_evals-section" class="content-section" style="display: none;">
                <h2>My Evaluations</h2>
                <?php if (empty($my_evaluations)): ?>
                    <p>You haven't submitted any evaluations yet.</p>
                <?php else: ?>
                    <table class="performance-table">
                        <thead>
                            <tr>
                                <th>Faculty</th>
                                <th>Subject</th>
                                <th>Semester</th>
                                <th>Academic Year</th>
                                <th>Overall</th>
                                <th>Status</th>
                                <th>Submitted</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($my_evaluations as $ev): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($ev['faculty_name']); ?></td>
                                <td><?php echo htmlspecialchars($ev['subject']); ?></td>
                                <td><?php echo htmlspecialchars($ev['semester']); ?></td>
                                <td><?php echo htmlspecialchars($ev['academic_year']); ?></td>
                                <td><?php echo $ev['overall_rating'] ? number_format($ev['overall_rating'],2) : 'N/A'; ?></td>
                                <td><?php echo htmlspecialchars(ucfirst($ev['status'])); ?></td>
                                <td><?php echo $ev['submitted_at'] ? date('M j, Y', strtotime($ev['submitted_at'])) : 'N/A'; ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>

            <!-- Profile Section -->
            <div id="profile-section" class="content-section" style="display: none;">
                <h2>My Profile</h2>
                <div class="profile-info">
                    <div class="info-group">
                        <label>Full Name:</label>
                        <span><?php echo htmlspecialchars($dean['full_name']); ?></span>
                    </div>
                    <div class="info-group">
                        <label>Department:</label>
                        <span><?php echo htmlspecialchars($dean['department']); ?></span>
                    </div>
                </div>

                <div class="profile-info" style="margin-top:1rem;">
                    <h3 style="margin:0 0 .5rem;">Edit Password</h3>
                    <form id="dean-change-password-form" class="forgot-card" onsubmit="return false;">
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
                        <div id="dean-pw-msg" class="error-message" style="display:none;"></div>
                        <div id="dean-pw-success" class="success-message" style="display:none; color:#166534; background:#dcfce7; padding:.75rem; border-radius:10px;">Password changed successfully.</div>
                        <button type="submit" class="btn-primary">Save Password</button>
                    </form>
                </div>
            </div>

        </div>
    </div>

    <script>
        // Expose dean's own evaluations to the frontend for filtering
        window.DEAN_EVALS = <?php echo json_encode(array_map(function($e){
            return [
                'faculty_id' => (int)$e['faculty_id'],
                'subject' => $e['subject'],
                'semester' => $e['semester'],
                'academic_year' => $e['academic_year']
            ];
        }, $my_evaluations)); ?>;
        window.DEAN_ACTIVE_PERIOD = <?php echo json_encode($activePeriod ?: ['semester'=>null,'academic_year'=>null]); ?>;
        // Mobile sidebar toggle (dean)
        (function(){
            const btn = document.getElementById('deanSidebarToggle');
            const sidebar = document.querySelector('.sidebar');
            if (!btn || !sidebar) return;
            btn.addEventListener('click', function(){
                sidebar.classList.toggle('active');
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
            }

            // On small screens, hide sidebar after navigation
            if (window.innerWidth <= 768) {
                const sidebar = document.querySelector('.sidebar');
                if (sidebar && sidebar.classList.contains('active')) {
                    sidebar.classList.remove('active');
                }
            }
        }

        // Faculty search functionality
        function filterFacultyTable() {
            const searchInput = document.getElementById('faculty_search');
            const table = document.getElementById('faculty_table');
            const searchTerm = searchInput.value.toLowerCase();
            
            if (!table) return;
            
            const rows = table.getElementsByTagName('tbody')[0].getElementsByTagName('tr');
            
            for (let i = 0; i < rows.length; i++) {
                const cells = rows[i].getElementsByTagName('td');
                let shouldShow = false;
                
                // Search in name, department, and position columns (0, 1, 2)
                for (let j = 0; j < 3; j++) {
                    if (cells[j] && cells[j].textContent.toLowerCase().includes(searchTerm)) {
                        shouldShow = true;
                        break;
                    }
                }
                
                rows[i].style.display = shouldShow ? '' : 'none';
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

        // Password change handler (Dean)
        (function(){
            const form = document.getElementById('dean-change-password-form');
            if (!form) return;
            const err = document.getElementById('dean-pw-msg');
            const ok = document.getElementById('dean-pw-success');
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

        // Export functions
        function exportToCSV() {
            try {
                const table = document.getElementById('faculty_table');
                if (!table) { alert('Faculty table not found.'); return; }

                // Collect headers
                const headers = [];
                const ths = table.querySelectorAll('thead th');
                ths.forEach(th => headers.push(cleanCell(th.textContent)));

                // Collect rows
                const rows = [headers];
                const trs = table.querySelectorAll('tbody tr');
                trs.forEach(tr => {
                    const row = [];
                    tr.querySelectorAll('td').forEach(td => {
                        // If cell has badge/span, take its text
                        row.push(cleanCell(td.textContent));
                    });
                    rows.push(row);
                });

                // Convert to CSV
                const csv = rows.map(r => r.map(escapeCSV).join(',')).join('\r\n');

                // Add BOM for Excel compatibility
                const blob = new Blob(['\uFEFF' + csv], { type: 'text/csv;charset=utf-8;' });
                const url = URL.createObjectURL(blob);
                const a = document.createElement('a');
                const now = new Date();
                const ts = now.toISOString().slice(0,19).replace(/[:T]/g,'-');
                a.href = url;
                a.download = `faculty_performance_${ts}.csv`;
                document.body.appendChild(a);
                a.click();
                document.body.removeChild(a);
                URL.revokeObjectURL(url);
            } catch (e) {
                console.error('CSV export failed:', e);
                alert('Failed to export CSV.');
            }

            function cleanCell(text){
                return (text || '').replace(/\s+/g, ' ').trim();
            }
            function escapeCSV(val){
                if (val == null) return '';
                const s = String(val);
                if (/[",\n]/.test(s)) {
                    return '"' + s.replace(/"/g, '""') + '"';
                }
                return s;
            }
        }

        function generateReport() {
            // Open the dedicated dean performance report page, which
            // follows the System Admin Faculty Performance Report format.
            window.open('performance_report.php', '_blank');
        }

        // Handle dean evaluation form submit
        const deanEvalForm = document.getElementById('dean-eval-form');
        if (deanEvalForm) {
            deanEvalForm.addEventListener('submit', function(e) {
                e.preventDefault();
                const formData = new FormData(deanEvalForm);
                const statusEl = document.getElementById('eval-status');
                statusEl.textContent = 'Submitting...';
                statusEl.style.color = '';

                fetch('submit_evaluation.php', {
                    method: 'POST',
                    body: formData
                })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        statusEl.textContent = data.message || 'Evaluation submitted.';
                        statusEl.style.color = 'var(--secondary-color)';
                        deanEvalForm.reset();
                        // Add to in-memory list and hide evaluated faculty going forward
                        try {
                            const fId = parseInt((formData.get('faculty_id')||'0'),10);
                            const subj = (formData.get('subject')||'')+'';
                            const sem = (window.DEAN_ACTIVE_PERIOD && window.DEAN_ACTIVE_PERIOD.semester) || deanSemEl.value;
                            const ay = (window.DEAN_ACTIVE_PERIOD && window.DEAN_ACTIVE_PERIOD.academic_year) || deanAyEl.value;
                            if (!window.DEAN_EVALS) window.DEAN_EVALS = [];
                            window.DEAN_EVALS.push({ faculty_id: fId, subject: subj, semester: sem, academic_year: ay });
                            updateDeanFacultyOptions();
                        } catch(_){} 
                    } else {
                        statusEl.textContent = data.message || 'Submission failed.';
                        statusEl.style.color = 'var(--danger-color)';
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    statusEl.textContent = 'An error occurred while submitting.';
                    statusEl.style.color = 'var(--danger-color)';
                });
            });
        }

        // Hide dean options already evaluated for same subject & period
        const deanFacultySel = document.getElementById('faculty_id');
        const deanSubjectEl = document.getElementById('subject');
        const deanSemEl = document.getElementById('semester');
        const deanAyEl = document.getElementById('academic_year');
        function updateDeanFacultyOptions(){
            if (!deanFacultySel) return;
            const subj = deanSubjectEl ? ((deanSubjectEl.tagName === 'SELECT') ? deanSubjectEl.value : deanSubjectEl.value) : '';
            const sem = deanSemEl ? deanSemEl.value : '';
            const ay = deanAyEl ? deanAyEl.value : '';
            const evals = window.DEAN_EVALS || [];
            Array.from(deanFacultySel.options).forEach((opt, idx) => {
                if (idx === 0) return; // placeholder
                opt.style.display = '';
                const fId = parseInt(opt.value || '0', 10);
                const dup = evals.some(ev => ev.faculty_id === fId && (!subj || ev.subject === subj) && (!sem || ev.semester === sem) && (!ay || ev.academic_year === ay));
                if (dup) {
                    opt.style.display = 'none';
                    if (deanFacultySel.value === opt.value) {
                        deanFacultySel.value = '';
                    }
                }
            });
        }
        if (deanFacultySel) {
            updateDeanFacultyOptions();
            if (deanSubjectEl) deanSubjectEl.addEventListener('change', updateDeanFacultyOptions);
            if (deanSemEl) deanSemEl.addEventListener('change', updateDeanFacultyOptions);
            if (deanAyEl) deanAyEl.addEventListener('input', updateDeanFacultyOptions);
        }

        // Initialize charts
        document.addEventListener('DOMContentLoaded', function() {
            // Department performance chart
            <?php if (!empty($department_stats)): ?>
            const deptCtx = document.getElementById('departmentChart');
            if (deptCtx) {
                new Chart(deptCtx, {
                    type: 'doughnut',
                    data: {
                        labels: [<?php echo implode(',', array_map(function($d) { return "'" . addslashes($d['department']) . "'"; }, $department_stats)); ?>],
                        datasets: [{
                            data: [<?php echo implode(',', array_map(function($d) { return $d['avg_rating'] ?: 0; }, $department_stats)); ?>],
                            backgroundColor: [
                                'rgba(52, 152, 219, 0.8)',
                                'rgba(46, 204, 113, 0.8)',
                                'rgba(155, 89, 182, 0.8)',
                                'rgba(241, 196, 15, 0.8)',
                                'rgba(231, 76, 60, 0.8)'
                            ]
                        }]
                    },
                    options: {
                        responsive: true,
                        plugins: {
                            legend: {
                                position: 'bottom'
                            }
                        }
                    }
                });
            }
            <?php endif; ?>

            // Department comparison chart
            <?php if (!empty($department_stats)): ?>
            const deptCompCtx = document.getElementById('departmentComparisonChart');
            if (deptCompCtx) {
                new Chart(deptCompCtx, {
                    type: 'bar',
                    data: {
                        labels: [<?php echo implode(',', array_map(function($d) { return "'" . addslashes($d['department']) . "'"; }, $department_stats)); ?>],
                        datasets: [{
                            label: 'Average Rating',
                            data: [<?php echo implode(',', array_map(function($d) { return $d['avg_rating'] ?: 0; }, $department_stats)); ?>],
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

            // Trends chart
            <?php if (!empty($semester_trends)): ?>
            const trendsCtx = document.getElementById('trendsChart');
            if (trendsCtx) {
                new Chart(trendsCtx, {
                    type: 'line',
                    data: {
                        labels: [<?php echo implode(',', array_map(function($t) { return "'" . $t['semester'] . " " . $t['academic_year'] . "'"; }, $semester_trends)); ?>],
                        datasets: [{
                            label: 'Average Rating',
                            data: [<?php echo implode(',', array_column($semester_trends, 'avg_rating')); ?>],
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
            <?php if (!empty($criteria_performance)): ?>
            const criteriaCtx = document.getElementById('criteriaChart');
            if (criteriaCtx) {
                new Chart(criteriaCtx, {
                    type: 'bar',
                    data: {
                        labels: [<?php echo implode(',', array_map(function($c) { return "'" . addslashes($c['criterion']) . "'"; }, array_slice($criteria_performance, 0, 10))); ?>],
                        datasets: [{
                            label: 'Average Rating',
                            data: [<?php echo implode(',', array_column(array_slice($criteria_performance, 0, 10), 'avg_rating')); ?>],
                            backgroundColor: 'rgba(155, 89, 182, 0.8)',
                            borderColor: 'rgb(155, 89, 182)',
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
    </script>
</body>
</html>
