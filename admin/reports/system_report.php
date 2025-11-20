<?php
require_once '../../config.php';
requireRole('admin');

// Get system statistics
try {
    $stmt = $pdo->prepare("SELECT 
                            (SELECT COUNT(*) FROM users WHERE role = 'student') as student_count,
                            (SELECT COUNT(*) FROM users WHERE role = 'faculty') as faculty_count,
                            (SELECT COUNT(*) FROM users WHERE role = 'dean') as dean_count,
                            (SELECT COUNT(*) FROM users WHERE role = 'admin') as admin_count,
                            (SELECT COUNT(*) FROM evaluations) as total_evaluations,
                            (SELECT COUNT(*) FROM evaluation_criteria WHERE is_active = 1) as active_criteria,
                            (SELECT COUNT(*) FROM users WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)) as new_users_30_days,
                            (SELECT COUNT(*) FROM evaluations WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)) as new_evaluations_30_days");
    $stmt->execute();
    $stats = $stmt->fetch();

    // Get department breakdown
    $stmt = $pdo->prepare("SELECT department, 
                            COUNT(CASE WHEN role = 'student' THEN 1 END) as students,
                            COUNT(CASE WHEN role = 'faculty' THEN 1 END) as faculty
                           FROM users 
                           WHERE department IS NOT NULL 
                           GROUP BY department 
                           ORDER BY department");
    $stmt->execute();
    $departments = $stmt->fetchAll();

    // Get recent activity
    $stmt = $pdo->prepare("SELECT 'User Registration' as activity_type, full_name as description, created_at 
                           FROM users 
                           WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                           UNION ALL
                           SELECT 'Evaluation Submitted' as activity_type, 
                                  CONCAT('Evaluation ID: ', id) as description, 
                                  created_at
                           FROM evaluations 
                           WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                           ORDER BY created_at DESC 
                           LIMIT 20");
    $stmt->execute();
    $recent_activity = $stmt->fetchAll();

} catch (PDOException $e) {
    $error = 'Database error: ' . $e->getMessage();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>System Report - Faculty Performance Evaluation System</title>
    <link rel="stylesheet" href="../../styles.css">
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 20px;
            background: #f5f5f5;
        }
        .report-container {
            max-width: 1200px;
            margin: 0 auto;
            background: white;
            padding: 30px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        .report-header {
            text-align: center;
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 2px solid #007bff;
        }
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        .stat-card {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 8px;
            text-align: center;
            border-left: 4px solid #007bff;
        }
        .stat-number {
            font-size: 2rem;
            font-weight: bold;
            color: #007bff;
        }
        .stat-label {
            color: #666;
            margin-top: 5px;
        }
        .section {
            margin-bottom: 30px;
        }
        .section h3 {
            color: #007bff;
            border-bottom: 1px solid #ddd;
            padding-bottom: 10px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 15px;
        }
        th, td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #ddd;
        }
        th {
            background: #f8f9fa;
            font-weight: 600;
        }
        .print-btn {
            background: #007bff;
            color: white;
            padding: 10px 20px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            margin-bottom: 20px;
        }
        @media print {
            .print-btn { display: none; }
            body { margin: 0; background: white; }
        }
    </style>
</head>
<body>
    <div class="report-container">
        <button class="print-btn" onclick="window.print()">Print Report</button>
        
        <div class="report-header">
            <h1>System Report</h1>
            <p>Faculty Performance Evaluation System</p>
            <p>Generated on: <?php echo date('F j, Y \a\t g:i A'); ?></p>
        </div>

        <?php if (isset($error)): ?>
            <div style="background: #f8d7da; color: #721c24; padding: 15px; border-radius: 5px; margin-bottom: 20px;">
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php else: ?>
            <!-- System Statistics -->
            <div class="section">
                <h3>System Overview</h3>
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-number"><?php echo $stats['student_count']; ?></div>
                        <div class="stat-label">Total Students</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-number"><?php echo $stats['faculty_count']; ?></div>
                        <div class="stat-label">Faculty Members</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-number"><?php echo $stats['dean_count']; ?></div>
                        <div class="stat-label">Deans</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-number"><?php echo $stats['admin_count']; ?></div>
                        <div class="stat-label">Administrators</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-number"><?php echo $stats['total_evaluations']; ?></div>
                        <div class="stat-label">Total Evaluations</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-number"><?php echo $stats['active_criteria']; ?></div>
                        <div class="stat-label">Active Criteria</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-number"><?php echo $stats['new_users_30_days']; ?></div>
                        <div class="stat-label">New Users (30 days)</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-number"><?php echo $stats['new_evaluations_30_days']; ?></div>
                        <div class="stat-label">New Evaluations (30 days)</div>
                    </div>
                </div>
            </div>

            <!-- Department Breakdown -->
            <div class="section">
                <h3>Department Breakdown</h3>
                <table>
                    <thead>
                        <tr>
                            <th>Department</th>
                            <th>Students</th>
                            <th>Faculty</th>
                            <th>Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($departments as $dept): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($dept['department']); ?></td>
                                <td><?php echo $dept['students']; ?></td>
                                <td><?php echo $dept['faculty']; ?></td>
                                <td><?php echo $dept['students'] + $dept['faculty']; ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Recent Activity -->
            <div class="section">
                <h3>Recent Activity (Last 7 Days)</h3>
                <?php if (empty($recent_activity)): ?>
                    <p>No recent activity found.</p>
                <?php else: ?>
                    <table>
                        <thead>
                            <tr>
                                <th>Activity Type</th>
                                <th>Description</th>
                                <th>Date</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recent_activity as $activity): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($activity['activity_type']); ?></td>
                                    <td><?php echo htmlspecialchars($activity['description']); ?></td>
                                    <td><?php echo date('M j, Y g:i A', strtotime($activity['created_at'])); ?></td>
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
