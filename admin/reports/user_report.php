<?php
require_once '../../config.php';
requireRole('admin');

// Get user statistics and data
try {
    // Get all users with their role-specific information
    $stmt = $pdo->prepare("SELECT 
                            u.id, u.username, u.full_name, u.email, u.department, u.role, u.created_at,
                            f.employee_id, f.position, f.hire_date,
                            s.student_id, s.year_level, s.program
                           FROM users u
                           LEFT JOIN faculty f ON u.id = f.user_id
                           LEFT JOIN students s ON u.id = s.user_id
                           ORDER BY u.department, u.role, u.full_name");
    $stmt->execute();
    $users = $stmt->fetchAll();

    // Get user registration trends (last 12 months)
    $stmt = $pdo->prepare("SELECT 
                            DATE_FORMAT(created_at, '%Y-%m') as month,
                            COUNT(*) as user_count,
                            role
                           FROM users 
                           WHERE created_at >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
                           GROUP BY DATE_FORMAT(created_at, '%Y-%m'), role
                           ORDER BY month DESC");
    $stmt->execute();
    $registration_trends = $stmt->fetchAll();

    // Get department-wise user distribution
    $stmt = $pdo->prepare("SELECT 
                            department,
                            role,
                            COUNT(*) as count
                           FROM users 
                           WHERE department IS NOT NULL
                           GROUP BY department, role
                           ORDER BY department, role");
    $stmt->execute();
    $dept_distribution = $stmt->fetchAll();

} catch (PDOException $e) {
    $error = 'Database error: ' . $e->getMessage();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Activity Report - Faculty Performance Evaluation System</title>
    <link rel="stylesheet" href="../../styles.css">
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 20px;
            background: #f5f5f5;
        }
        .report-container {
            max-width: 1400px;
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
            border-bottom: 2px solid #28a745;
        }
        .section {
            margin-bottom: 30px;
        }
        .section h3 {
            color: #28a745;
            border-bottom: 1px solid #ddd;
            padding-bottom: 10px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 15px;
            font-size: 0.9rem;
        }
        th, td {
            padding: 8px;
            text-align: left;
            border-bottom: 1px solid #ddd;
        }
        th {
            background: #f8f9fa;
            font-weight: 600;
            position: sticky;
            top: 0;
        }
        .role-badge {
            padding: 3px 8px;
            border-radius: 12px;
            font-size: 0.8rem;
            font-weight: 600;
        }
        .role-student { background: #007bff; color: white; }
        .role-faculty { background: #6f42c1; color: white; }
        .role-dean { background: #fd7e14; color: white; }
        .role-admin { background: #dc3545; color: white; }
        .print-btn {
            background: #28a745;
            color: white;
            padding: 10px 20px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            margin-bottom: 20px;
        }
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }
        .stat-card {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 8px;
            text-align: center;
            border-left: 4px solid #28a745;
        }
        .stat-number {
            font-size: 1.5rem;
            font-weight: bold;
            color: #28a745;
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
            <h1>User Activity Report</h1>
            <p>Faculty Performance Evaluation System</p>
            <p>Generated on: <?php echo date('F j, Y \a\t g:i A'); ?></p>
        </div>

        <?php if (isset($error)): ?>
            <div style="background: #f8d7da; color: #721c24; padding: 15px; border-radius: 5px; margin-bottom: 20px;">
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php else: ?>
            <!-- User Summary -->
            <div class="section">
                <h3>User Summary</h3>
                <div class="stats-grid">
                    <?php
                    $role_counts = [];
                    foreach ($users as $user) {
                        $role_counts[$user['role']] = ($role_counts[$user['role']] ?? 0) + 1;
                    }
                    foreach ($role_counts as $role => $count):
                    ?>
                        <div class="stat-card">
                            <div class="stat-number"><?php echo $count; ?></div>
                            <div class="stat-label"><?php echo ucfirst($role) . 's'; ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Department Distribution -->
            <div class="section">
                <h3>Department Distribution</h3>
                <table>
                    <thead>
                        <tr>
                            <th>Department</th>
                            <th>Students</th>
                            <th>Faculty</th>
                            <th>Deans</th>
                            <th>Admins</th>
                            <th>Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $dept_summary = [];
                        foreach ($dept_distribution as $row) {
                            $dept = $row['department'];
                            $role = $row['role'];
                            $count = $row['count'];
                            
                            if (!isset($dept_summary[$dept])) {
                                $dept_summary[$dept] = ['student' => 0, 'faculty' => 0, 'dean' => 0, 'admin' => 0];
                            }
                            $dept_summary[$dept][$role] = $count;
                        }
                        
                        foreach ($dept_summary as $dept => $counts):
                            $total = array_sum($counts);
                        ?>
                            <tr>
                                <td><?php echo htmlspecialchars($dept); ?></td>
                                <td><?php echo $counts['student']; ?></td>
                                <td><?php echo $counts['faculty']; ?></td>
                                <td><?php echo $counts['dean']; ?></td>
                                <td><?php echo $counts['admin']; ?></td>
                                <td><strong><?php echo $total; ?></strong></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- All Users -->
            <div class="section">
                <h3>All Users (<?php echo count($users); ?> total)</h3>
                <table>
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Username</th>
                            <th>Role</th>
                            <th>Department</th>
                            <th>Email</th>
                            <th>Additional Info</th>
                            <th>Registered</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($users as $user): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($user['full_name']); ?></td>
                                <td><?php echo htmlspecialchars($user['username']); ?></td>
                                <td>
                                    <span class="role-badge role-<?php echo $user['role']; ?>">
                                        <?php echo ucfirst($user['role']); ?>
                                    </span>
                                </td>
                                <td><?php echo htmlspecialchars($user['department']); ?></td>
                                <td><?php echo htmlspecialchars($user['email'] ?? 'N/A'); ?></td>
                                <td>
                                    <?php if ($user['role'] === 'faculty'): ?>
                                        ID: <?php echo htmlspecialchars($user['employee_id'] ?? 'N/A'); ?><br>
                                        Position: <?php echo htmlspecialchars($user['position'] ?? 'N/A'); ?>
                                    <?php elseif ($user['role'] === 'student'): ?>
                                        ID: <?php echo htmlspecialchars($user['student_id'] ?? 'N/A'); ?><br>
                                        Year: <?php echo htmlspecialchars($user['year_level'] ?? 'N/A'); ?>
                                    <?php else: ?>
                                        -
                                    <?php endif; ?>
                                </td>
                                <td><?php echo date('M j, Y', strtotime($user['created_at'])); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Registration Trends -->
            <?php if (!empty($registration_trends)): ?>
            <div class="section">
                <h3>Registration Trends (Last 12 Months)</h3>
                <table>
                    <thead>
                        <tr>
                            <th>Month</th>
                            <th>Role</th>
                            <th>New Users</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($registration_trends as $trend): ?>
                            <tr>
                                <td><?php echo date('F Y', strtotime($trend['month'] . '-01')); ?></td>
                                <td>
                                    <span class="role-badge role-<?php echo $trend['role']; ?>">
                                        <?php echo ucfirst($trend['role']); ?>
                                    </span>
                                </td>
                                <td><?php echo $trend['user_count']; ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</body>
</html>
