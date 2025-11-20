<?php
require_once '../config.php';
requireRole('admin');

// Get department from URL parameter
$department = $_GET['dept'] ?? '';
if (empty($department)) {
    header('Location: ../dashboard.php');
    exit();
}

// Verify admin has access to this department
if ($_SESSION['department'] !== $department) {
    header('Location: ../dashboard.php');
    exit();
}

// Get department statistics and data
try {
    // Get department overview stats
    $stmt = $pdo->prepare("SELECT 
                            (SELECT COUNT(*) FROM users WHERE role = 'student' AND department = ?) as student_count,
                            (SELECT COUNT(*) FROM users WHERE role = 'faculty' AND department = ?) as faculty_count,
                            (SELECT COUNT(*) FROM users WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) AND department = ?) as new_users_30_days,
                            (SELECT COUNT(*) FROM evaluations e JOIN users u ON e.faculty_id = u.id WHERE u.department = ?) as total_evaluations");
    $stmt->execute([$department, $department, $department, $department]);
    $dept_stats = $stmt->fetch();

    // Get students in this department
    $stmt = $pdo->prepare("SELECT u.*, s.student_id, s.year_level, s.program 
                           FROM users u 
                           LEFT JOIN students s ON u.id = s.user_id 
                           WHERE u.role = 'student' AND u.department = ? 
                           ORDER BY u.created_at DESC");
    $stmt->execute([$department]);
    $students = $stmt->fetchAll();

    // Get faculty in this department, including assigned subjects via subquery
    $stmt = $pdo->prepare("SELECT u.*, f.employee_id, f.position, f.hire_date,
                           (SELECT COUNT(*) FROM evaluations WHERE faculty_id = u.id) as evaluation_count,
                           (SELECT AVG(overall_rating) FROM evaluations WHERE faculty_id = u.id AND status = 'submitted') as avg_rating,
                           (SELECT GROUP_CONCAT(DISTINCT CONCAT(COALESCE(sfs.subject_code, ''), CASE WHEN sfs.subject_code IS NOT NULL AND sfs.subject_code <> '' THEN ' - ' ELSE '' END, sfs.subject_name) SEPARATOR ', ')
                              FROM student_faculty_subjects sfs
                              WHERE sfs.faculty_user_id = u.id) as subjects_assigned
                           FROM users u 
                           LEFT JOIN faculty f ON u.id = f.user_id 
                           WHERE u.role = 'faculty' AND u.department = ? 
                           ORDER BY u.full_name");
    $stmt->execute([$department]);
    $faculty = $stmt->fetchAll();

    // Get program distribution for students
    $stmt = $pdo->prepare("SELECT s.program, COUNT(*) as count 
                           FROM students s 
                           JOIN users u ON s.user_id = u.id 
                           WHERE u.department = ? AND s.program IS NOT NULL 
                           GROUP BY s.program 
                           ORDER BY count DESC");
    $stmt->execute([$department]);
    $program_distribution = $stmt->fetchAll();

    // Get year level distribution
    $stmt = $pdo->prepare("SELECT s.year_level, COUNT(*) as count 
                           FROM students s 
                           JOIN users u ON s.user_id = u.id 
                           WHERE u.department = ? AND s.year_level IS NOT NULL 
                           GROUP BY s.year_level 
                           ORDER BY s.year_level");
    $stmt->execute([$department]);
    $year_distribution = $stmt->fetchAll();

    // Get recent enrollments (last 6 months)
    $stmt = $pdo->prepare("SELECT DATE_FORMAT(created_at, '%Y-%m') as month, COUNT(*) as count 
                           FROM users 
                           WHERE department = ? AND role = 'student' AND created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
                           GROUP BY DATE_FORMAT(created_at, '%Y-%m') 
                           ORDER BY month DESC");
    $stmt->execute([$department]);
    $enrollment_trends = $stmt->fetchAll();

    // Get student–faculty–subject assignments (if table exists)
    $assignments = [];
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS student_faculty_subjects (
            id INT AUTO_INCREMENT PRIMARY KEY,
            student_user_id INT NOT NULL,
            faculty_user_id INT NOT NULL,
            subject_code VARCHAR(50) DEFAULT NULL,
            subject_name VARCHAR(255) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_assignment (student_user_id, faculty_user_id, subject_code, subject_name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

        $stmt = $pdo->prepare("SELECT su.full_name AS student_name, st.student_id AS student_no,
                                       fu.full_name AS faculty_name,
                                       sfs.subject_code, sfs.subject_name
                                FROM student_faculty_subjects sfs
                                JOIN users su ON su.id = sfs.student_user_id
                                LEFT JOIN students st ON st.user_id = su.id
                                JOIN users fu ON fu.id = sfs.faculty_user_id
                                WHERE su.department = ?
                                ORDER BY su.full_name, fu.full_name, sfs.subject_name");
        $stmt->execute([$department]);
        $assignments = $stmt->fetchAll();
    } catch (PDOException $e) {
        $assignments = [];
    }

} catch (PDOException $e) {
    $error = 'Database error: ' . $e->getMessage();
}

// Get department color scheme
$dept_colors = [
    'Technology' => ['primary' => '#800020', 'secondary' => '#a0002a', 'accent' => '#d4002f'],
    'Education' => ['primary' => '#1e3a8a', 'secondary' => '#3b82f6', 'accent' => '#60a5fa'],
    'Business' => ['primary' => '#ea580c', 'secondary' => '#f97316', 'accent' => '#fb923c']
];
$colors = $dept_colors[$department] ?? $dept_colors['Technology'];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($department); ?> Department Report</title>
    <link rel="stylesheet" href="../styles.css">
    <style>
        :root {
            --dept-primary: <?php echo $colors['primary']; ?>;
            --dept-secondary: <?php echo $colors['secondary']; ?>;
            --dept-accent: <?php echo $colors['accent']; ?>;
        }
        
        body {
            font-family: Arial, sans-serif;
            margin: 20px;
            background: linear-gradient(135deg, var(--dept-primary) 0%, var(--dept-accent) 100%);
            min-height: 100vh;
        }
        
        .report-container {
            max-width: 1400px;
            margin: 0 auto;
            background: white;
            padding: 30px;
            border-radius: 20px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
        }
        
        .report-header {
            text-align: center;
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 3px solid var(--dept-primary);
        }
        
        .report-header h1 {
            color: var(--dept-primary);
            font-size: 2.5rem;
            margin-bottom: 10px;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .stat-card {
            background: linear-gradient(135deg, var(--dept-primary), var(--dept-secondary));
            color: white;
            padding: 25px;
            border-radius: 15px;
            text-align: center;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }
        
        .stat-number {
            font-size: 2.5rem;
            font-weight: bold;
            margin-bottom: 5px;
        }
        
        .stat-label {
            font-size: 0.9rem;
            opacity: 0.9;
        }
        
        .section {
            margin-bottom: 30px;
            background: #f8f9fa;
            padding: 25px;
            border-radius: 15px;
            border-left: 5px solid var(--dept-primary);
        }
        
        .section h3 {
            color: var(--dept-primary);
            margin-bottom: 20px;
            font-size: 1.5rem;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 15px;
            background: white;
            border-radius: 10px;
            overflow: hidden;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        th, td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #e9ecef;
        }
        
        th {
            background: var(--dept-primary);
            color: white;
            font-weight: 600;
        }
        
        .print-btn {
            background: var(--dept-primary);
            color: white;
            padding: 12px 24px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            margin-bottom: 20px;
            font-weight: 600;
        }
        
        .print-btn:hover {
            background: var(--dept-secondary);
        }
        
        .rating {
            font-weight: bold;
            padding: 4px 8px;
            border-radius: 4px;
        }
        
        .rating-excellent { background: #d4edda; color: #155724; }
        .rating-good { background: #d1ecf1; color: #0c5460; }
        .rating-average { background: #fff3cd; color: #856404; }
        .rating-poor { background: #f8d7da; color: #721c24; }
        
        @media print {
            .print-btn { display: none; }
            body { margin: 0; background: white !important; }
            .report-container { box-shadow: none; }
        }
    </style>
</head>
<body>
    <div class="report-container">
        <button class="print-btn" onclick="window.print()">Print Department Report</button>
        
        <div class="report-header">
            <h1><?php echo htmlspecialchars($department); ?> Department Report</h1>
            <p>Faculty Performance Evaluation System</p>
            <p>Generated on: <?php echo date('F j, Y \a\t g:i A'); ?></p>
        </div>

        <?php if (isset($error)): ?>
            <div style="background: #f8d7da; color: #721c24; padding: 15px; border-radius: 5px; margin-bottom: 20px;">
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php else: ?>
            <!-- Department Overview -->
            <div class="section">
                <h3>Department Overview</h3>
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-number"><?php echo $dept_stats['student_count']; ?></div>
                        <div class="stat-label">Total Students</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-number"><?php echo $dept_stats['faculty_count']; ?></div>
                        <div class="stat-label">Faculty Members</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-number"><?php echo $dept_stats['new_users_30_days']; ?></div>
                        <div class="stat-label">New Users (30 days)</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-number"><?php echo $dept_stats['total_evaluations']; ?></div>
                        <div class="stat-label">Total Evaluations</div>
                    </div>
                </div>
            </div>

            <!-- Program Distribution -->
            <?php if (!empty($program_distribution)): ?>
            <div class="section">
                <h3>Program Distribution</h3>
                <table>
                    <thead>
                        <tr>
                            <th>Program</th>
                            <th>Student Count</th>
                            <th>Percentage</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $total_students = array_sum(array_column($program_distribution, 'count'));
                        foreach ($program_distribution as $program): 
                            $percentage = $total_students > 0 ? ($program['count'] / $total_students) * 100 : 0;
                        ?>
                            <tr>
                                <td><?php echo htmlspecialchars($program['program']); ?></td>
                                <td><?php echo $program['count']; ?></td>
                                <td><?php echo number_format($percentage, 1); ?>%</td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>

            <!-- Student–Faculty–Subject Assignments -->
            <div class="section">
                <h3>Student–Faculty–Subject Assignments</h3>
                <?php if (empty($assignments)): ?>
                    <p>No assignments found for this department.</p>
                <?php else: ?>
                    <?php
                    // Group by student then faculty
                    $grouped = [];
                    foreach ($assignments as $row) {
                        $sname = $row['student_name'];
                        $sno = $row['student_no'];
                        $fname = $row['faculty_name'];
                        $sub = ($row['subject_code'] ? $row['subject_code'].' - ' : '').$row['subject_name'];
                        if (!isset($grouped[$sname.'|'.$sno])) { $grouped[$sname.'|'.$sno] = []; }
                        if (!isset($grouped[$sname.'|'.$sno][$fname])) { $grouped[$sname.'|'.$sno][$fname] = []; }
                        $grouped[$sname.'|'.$sno][$fname][] = $sub;
                    }
                    ?>
                    <table>
                        <thead>
                            <tr>
                                <th>Student</th>
                                <th>Student ID</th>
                                <th>Faculty</th>
                                <th>Subjects</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($grouped as $stuKey => $facMap): list($studentName, $studentNo) = explode('|', $stuKey, 2); ?>
                                <?php $first = true; $rowspan = count($facMap); ?>
                                <?php foreach ($facMap as $facultyName => $subjects): ?>
                                    <tr>
                                        <?php if ($first): ?>
                                            <td rowspan="<?php echo $rowspan; ?>"><?php echo htmlspecialchars($studentName); ?></td>
                                            <td rowspan="<?php echo $rowspan; ?>"><?php echo htmlspecialchars($studentNo ?: 'N/A'); ?></td>
                                            <?php $first = false; ?>
                                        <?php endif; ?>
                                        <td><?php echo htmlspecialchars($facultyName); ?></td>
                                        <td><?php echo htmlspecialchars(implode(', ', $subjects)); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>

            <!-- Year Level Distribution -->
            <?php if (!empty($year_distribution)): ?>
            <div class="section">
                <h3>Year Level Distribution</h3>
                <table>
                    <thead>
                        <tr>
                            <th>Year Level</th>
                            <th>Student Count</th>
                            <th>Percentage</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $total_students = array_sum(array_column($year_distribution, 'count'));
                        foreach ($year_distribution as $year): 
                            $percentage = $total_students > 0 ? ($year['count'] / $total_students) * 100 : 0;
                        ?>
                            <tr>
                                <td><?php echo htmlspecialchars($year['year_level']); ?></td>
                                <td><?php echo $year['count']; ?></td>
                                <td><?php echo number_format($percentage, 1); ?>%</td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>

            <!-- Faculty Performance -->
            <div class="section">
                <h3>Faculty Performance Summary</h3>
                <?php if (empty($faculty)): ?>
                    <p>No faculty members found in this department.</p>
                <?php else: ?>
                    <table>
                        <thead>
                            <tr>
                                <th>Employee ID</th>
                                <th>Faculty Name</th>
                                <th>Position</th>
                                <th>Hire Date</th>
                                <th>Evaluations</th>
                                <th>Average Rating</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($faculty as $member): 
                                $rating = $member['avg_rating'];
                                $rating_class = '';
                                if ($rating >= 4.5) $rating_class = 'rating-excellent';
                                elseif ($rating >= 4.0) $rating_class = 'rating-good';
                                elseif ($rating >= 3.0) $rating_class = 'rating-average';
                                elseif ($rating > 0) $rating_class = 'rating-poor';
                            ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($member['employee_id'] ?? 'N/A'); ?></td>
                                    <td><?php echo htmlspecialchars($member['full_name']); ?></td>
                                    <td><?php echo htmlspecialchars($member['position'] ?? 'N/A'); ?></td>
                                    <td><?php echo $member['hire_date'] ? date('M j, Y', strtotime($member['hire_date'])) : 'N/A'; ?></td>
                                    <td><?php echo $member['evaluation_count']; ?></td>
                                    <td>
                                        <?php if ($member['avg_rating']): ?>
                                            <span class="rating <?php echo $rating_class; ?>">
                                                <?php echo number_format($member['avg_rating'], 2); ?>
                                            </span>
                                        <?php else: ?>
                                            <span style="color: #666;">No evaluations</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>

            <!-- Enrollment Trends -->
            <?php if (!empty($enrollment_trends)): ?>
            <div class="section">
                <h3>Student Enrollment Trends (Last 6 Months)</h3>
                <table>
                    <thead>
                        <tr>
                            <th>Month</th>
                            <th>New Enrollments</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($enrollment_trends as $trend): ?>
                            <tr>
                                <td><?php echo date('F Y', strtotime($trend['month'] . '-01')); ?></td>
                                <td><?php echo $trend['count']; ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>

            <!-- All Students -->
            <div class="section">
                <h3>All Students (<?php echo count($students); ?> total)</h3>
                <?php if (empty($students)): ?>
                    <p>No students enrolled in this department.</p>
                <?php else: ?>
                    <table>
                        <thead>
                            <tr>
                                <th>Student ID</th>
                                <th>Full Name</th>
                                <th>Username</th>
                                <th>Email</th>
                                <th>Year Level</th>
                                <th>Program</th>
                                <th>Enrolled Date</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($students as $student): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($student['student_id'] ?? 'N/A'); ?></td>
                                    <td><?php echo htmlspecialchars($student['full_name']); ?></td>
                                    <td><?php echo htmlspecialchars($student['username']); ?></td>
                                    <td><?php echo htmlspecialchars($student['email'] ?? 'N/A'); ?></td>
                                    <td><?php echo htmlspecialchars($student['year_level'] ?? 'N/A'); ?></td>
                                    <td><?php echo htmlspecialchars($student['program'] ?? 'N/A'); ?></td>
                                    <td><?php echo date('M j, Y', strtotime($student['created_at'])); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
