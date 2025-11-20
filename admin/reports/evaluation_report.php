<?php
require_once '../../config.php';
requireRole('admin');

// Get evaluation statistics and data
try {
    // Get evaluation summary statistics
    $stmt = $pdo->prepare("SELECT 
                            COUNT(*) as total_evaluations,
                            COUNT(CASE WHEN status = 'submitted' THEN 1 END) as submitted_evaluations,
                            COUNT(CASE WHEN status = 'draft' THEN 1 END) as draft_evaluations,
                            AVG(overall_rating) as avg_rating,
                            COUNT(DISTINCT faculty_id) as evaluated_faculty
                           FROM evaluations");
    $stmt->execute();
    $eval_stats = $stmt->fetch();

    // Get evaluations by department
    $stmt = $pdo->prepare("SELECT 
                            uf.department,
                            COUNT(e.id) as evaluation_count,
                            AVG(e.overall_rating) as avg_rating,
                            COUNT(DISTINCT e.faculty_id) as faculty_count
                           FROM evaluations e
                           JOIN faculty f ON e.faculty_id = f.id
                           JOIN users uf ON f.user_id = uf.id
                           GROUP BY uf.department
                           ORDER BY evaluation_count DESC");
    $stmt->execute();
    $dept_evaluations = $stmt->fetchAll();

    // Get recent evaluations with details
    // Prefer evaluator metadata if present; otherwise fallback to legacy student mapping
    try {
        $stmt = $pdo->prepare("SELECT 
                                e.id, e.overall_rating, e.status, e.created_at,
                                uf.full_name AS faculty_name,
                                uf.department AS faculty_dept,
                                CASE 
                                    WHEN COALESCE(e.is_self, 0) = 1 THEN 'Self Evaluation'
                                    WHEN COALESCE(e.is_anonymous, 0) = 1 THEN CONCAT('Anonymous ', UPPER(LEFT(COALESCE(e.evaluator_role, 'student'),1)), SUBSTRING(COALESCE(e.evaluator_role, 'student'),2))
                                    ELSE COALESCE(ue.full_name, us.full_name, 'Unknown')
                                END AS evaluator_name,
                                CASE 
                                    WHEN COALESCE(e.is_self, 0) = 1 THEN 'faculty'
                                    ELSE COALESCE(e.evaluator_role, CASE WHEN s.id IS NOT NULL THEN 'student' END, 'unknown')
                                END AS evaluator_role
                               FROM evaluations e
                               JOIN faculty f ON e.faculty_id = f.id
                               JOIN users uf ON f.user_id = uf.id
                               LEFT JOIN users ue ON e.evaluator_user_id = ue.id
                               LEFT JOIN students s ON e.student_id = s.id
                               LEFT JOIN users us ON s.user_id = us.id
                               ORDER BY e.created_at DESC
                               LIMIT 50");
        $stmt->execute();
        $recent_evaluations = $stmt->fetchAll();
    } catch (PDOException $ex) {
        $stmt = $pdo->prepare("SELECT 
                                e.id, e.overall_rating, e.status, e.created_at,
                                uf.full_name AS faculty_name,
                                uf.department AS faculty_dept,
                                CASE WHEN e.is_anonymous = 1 THEN 'Anonymous Student' ELSE us.full_name END AS evaluator_name,
                                'student' AS evaluator_role
                               FROM evaluations e
                               JOIN faculty f ON e.faculty_id = f.id
                               JOIN users uf ON f.user_id = uf.id
                               LEFT JOIN students s ON e.student_id = s.id
                               LEFT JOIN users us ON s.user_id = us.id
                               ORDER BY e.created_at DESC
                               LIMIT 50");
        $stmt->execute();
        $recent_evaluations = $stmt->fetchAll();
    }

    // Get evaluation criteria usage
    $stmt = $pdo->prepare("SELECT 
                            ec.category,
                            ec.criterion,
                            COUNT(er.id) as usage_count,
                            AVG(er.rating) as avg_rating
                           FROM evaluation_criteria ec
                           LEFT JOIN evaluation_responses er ON ec.id = er.criterion_id
                           WHERE ec.is_active = 1
                           GROUP BY ec.id, ec.category, ec.criterion
                           ORDER BY ec.category, usage_count DESC");
    $stmt->execute();
    $criteria_usage = $stmt->fetchAll();

} catch (PDOException $e) {
    $error = 'Database error: ' . $e->getMessage();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Evaluation Summary Report - Faculty Performance Evaluation System</title>
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
            border-bottom: 2px solid #6f42c1;
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
            border-left: 4px solid #6f42c1;
        }
        .stat-number {
            font-size: 2rem;
            font-weight: bold;
            color: #6f42c1;
        }
        .stat-label {
            color: #666;
            margin-top: 5px;
        }
        .section {
            margin-bottom: 30px;
        }
        .section h3 {
            color: #6f42c1;
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
        .status-badge {
            padding: 3px 8px;
            border-radius: 12px;
            font-size: 0.8rem;
            font-weight: 600;
        }
        .status-submitted { background: #28a745; color: white; }
        .status-draft { background: #ffc107; color: #212529; }
        .rating {
            font-weight: bold;
            color: #6f42c1;
        }
        .print-btn {
            background: #6f42c1;
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
            <h1>Evaluation Summary Report</h1>
            <p>Faculty Performance Evaluation System</p>
            <p>Generated on: <?php echo date('F j, Y \a\t g:i A'); ?></p>
        </div>

        <?php if (isset($error)): ?>
            <div style="background: #f8d7da; color: #721c24; padding: 15px; border-radius: 5px; margin-bottom: 20px;">
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php else: ?>
            <!-- Evaluation Statistics -->
            <div class="section">
                <h3>Evaluation Overview</h3>
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-number"><?php echo $eval_stats['total_evaluations'] ?? 0; ?></div>
                        <div class="stat-label">Total Evaluations</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-number"><?php echo $eval_stats['submitted_evaluations'] ?? 0; ?></div>
                        <div class="stat-label">Submitted</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-number"><?php echo $eval_stats['draft_evaluations'] ?? 0; ?></div>
                        <div class="stat-label">Drafts</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-number"><?php echo number_format($eval_stats['avg_rating'] ?? 0, 1); ?></div>
                        <div class="stat-label">Average Rating</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-number"><?php echo $eval_stats['evaluated_faculty'] ?? 0; ?></div>
                        <div class="stat-label">Faculty Evaluated</div>
                    </div>
                </div>
            </div>

            <!-- Department Performance -->
            <div class="section">
                <h3>Department Performance</h3>
                <?php if (empty($dept_evaluations)): ?>
                    <p>No evaluation data available by department.</p>
                <?php else: ?>
                    <table>
                        <thead>
                            <tr>
                                <th>Department</th>
                                <th>Total Evaluations</th>
                                <th>Faculty Evaluated</th>
                                <th>Average Rating</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($dept_evaluations as $dept): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($dept['department']); ?></td>
                                    <td><?php echo $dept['evaluation_count']; ?></td>
                                    <td><?php echo $dept['faculty_count']; ?></td>
                                    <td class="rating"><?php echo number_format($dept['avg_rating'], 1); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>

            <!-- Criteria Usage -->
            <div class="section">
                <h3>Evaluation Criteria Usage</h3>
                <?php if (empty($criteria_usage)): ?>
                    <p>No criteria usage data available.</p>
                <?php else: ?>
                    <table>
                        <thead>
                            <tr>
                                <th>Category</th>
                                <th>Criterion</th>
                                <th>Times Used</th>
                                <th>Average Rating</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $current_category = '';
                            foreach ($criteria_usage as $criterion): 
                                $show_category = $criterion['category'] !== $current_category;
                                $current_category = $criterion['category'];
                            ?>
                                <tr>
                                    <td><?php echo $show_category ? htmlspecialchars($criterion['category']) : ''; ?></td>
                                    <td><?php echo htmlspecialchars($criterion['criterion']); ?></td>
                                    <td><?php echo $criterion['usage_count'] ?? 0; ?></td>
                                    <td class="rating">
                                        <?php echo $criterion['avg_rating'] ? number_format($criterion['avg_rating'], 1) : 'N/A'; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>

            <!-- Recent Evaluations -->
            <div class="section">
                <h3>Recent Evaluations</h3>
                <?php if (empty($recent_evaluations)): ?>
                    <p>No recent evaluations found.</p>
                <?php else: ?>
                    <table>
                        <thead>
                            <tr>
                                <th>Evaluation ID</th>
                                <th>Faculty</th>
                                <th>Department</th>
                                <th>Evaluator</th>
                                <th>Evaluator Role</th>
                                <th>Rating</th>
                                <th>Status</th>
                                <th>Date</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recent_evaluations as $eval): ?>
                                <tr>
                                    <td>#<?php echo $eval['id']; ?></td>
                                    <td><?php echo htmlspecialchars($eval['faculty_name']); ?></td>
                                    <td><?php echo htmlspecialchars($eval['faculty_dept']); ?></td>
                                    <td><?php echo htmlspecialchars($eval['evaluator_name']); ?></td>
                                    <td><?php echo ucfirst($eval['evaluator_role']); ?></td>
                                    <td class="rating"><?php echo $eval['overall_rating'] ? number_format($eval['overall_rating'], 1) : 'N/A'; ?></td>
                                    <td>
                                        <span class="status-badge status-<?php echo $eval['status']; ?>">
                                            <?php echo ucfirst($eval['status']); ?>
                                        </span>
                                    </td>
                                    <td><?php echo date('M j, Y', strtotime($eval['created_at'])); ?></td>
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
