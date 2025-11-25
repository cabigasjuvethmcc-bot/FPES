<?php
require_once '../../config.php';
requireRole('admin');

// Get faculty performance data
try {
    // Get faculty with their evaluation statistics
    $stmt = $pdo->prepare("SELECT 
                            u.id, u.full_name, u.department,
                            f.id AS faculty_id,
                            f.employee_id, f.position,
                            e.subject,
                            COUNT(e.id) as evaluation_count,
                            AVG(e.overall_rating) as avg_rating,
                            MAX(e.created_at) as last_evaluation,
                            MIN(e.created_at) as first_evaluation
                           FROM users u
                           JOIN faculty f ON u.id = f.user_id
                           LEFT JOIN evaluations e ON f.id = e.faculty_id AND e.status = 'submitted'
                           WHERE u.role = 'faculty'
                           GROUP BY u.id, u.full_name, u.department, f.id, f.employee_id, f.position, e.subject
                           ORDER BY u.department, u.full_name, e.subject");
    $stmt->execute();
    $faculty_performance = $stmt->fetchAll();

    // Get top performers
    $stmt = $pdo->prepare("SELECT 
                            u.full_name, u.department,
                            AVG(e.overall_rating) as avg_rating,
                            COUNT(e.id) as evaluation_count
                           FROM users u
                           JOIN faculty f ON u.id = f.user_id
                           JOIN evaluations e ON f.id = e.faculty_id
                           WHERE u.role = 'faculty' AND e.status = 'submitted'
                           GROUP BY u.id, u.full_name, u.department
                           HAVING COUNT(e.id) >= 3
                           ORDER BY avg_rating DESC
                           LIMIT 10");
    $stmt->execute();
    $top_performers = $stmt->fetchAll();

    // Get performance trends by department
    $stmt = $pdo->prepare("SELECT 
                            u.department,
                            f.id AS faculty_id,
                            DATE_FORMAT(e.created_at, '%Y-%m') as month,
                            AVG(e.overall_rating) as avg_rating,
                            COUNT(e.id) as evaluation_count
                           FROM evaluations e
                           JOIN faculty f ON e.faculty_id = f.id
                           JOIN users u ON f.user_id = u.id
                            WHERE e.status = 'submitted' AND e.created_at >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
                            GROUP BY u.department, f.id, DATE_FORMAT(e.created_at, '%Y-%m')
                            ORDER BY u.department, month DESC");
    $stmt->execute();
    $performance_trends = $stmt->fetchAll();

    // Aggregate student comments per faculty (admin-only view, keep students anonymous)
    $stmt = $pdo->prepare("SELECT 
                             e.faculty_id,
                             GROUP_CONCAT(TRIM(e.comments) SEPARATOR '\n\n') AS comments
                           FROM evaluations e
                           WHERE e.status = 'submitted'
                             AND e.comments IS NOT NULL
                             AND e.comments <> ''
                             AND (e.evaluator_role = 'student' OR e.student_id IS NOT NULL)
                           GROUP BY e.faculty_id");
    $stmt->execute();
    $faculty_comments = [];
    foreach ($stmt->fetchAll() as $row) {
        $faculty_comments[(int)$row['faculty_id']] = $row['comments'];
    }

} catch (PDOException $e) {
    $error = 'Database error: ' . $e->getMessage();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Faculty Performance Report - Faculty Performance Evaluation System</title>
    <link rel="stylesheet" href="../../styles.css">
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 20px;
            background: #ffffff;
        }
        .report-container {
            max-width: 900px;
            margin: 0 auto;
        }
        .print-btn {
            background: #000000;
            color: white;
            padding: 10px 20px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            margin-bottom: 20px;
        }
        .evaluation-page {
            border: 1px solid #000;
            padding: 15px;
            margin-bottom: 30px;
        }
        .form-header {
            text-align: center;
            font-weight: bold;
            margin-bottom: 10px;
            text-transform: uppercase;
        }
        .meta-table,
        .rating-scale-table,
        .teaching-params-table,
        .average-rating-table,
        .signatures-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 12px;
        }
        .meta-table td,
        .rating-scale-table th,
        .rating-scale-table td,
        .teaching-params-table th,
        .teaching-params-table td,
        .average-rating-table th,
        .average-rating-table td,
        .signatures-table th,
        .signatures-table td {
            border: 1px solid #000;
            padding: 4px 6px;
        }
        .rating-scale-table th {
            text-align: center;
        }
        .teaching-params-table th {
            text-align: center;
        }
        .teaching-params-table td:first-child {
            width: 80%;
        }
        .comments-section {
            margin-top: 10px;
            font-size: 12px;
        }
        .comments-label {
            font-weight: bold;
            margin-bottom: 4px;
        }
        .comments-box {
            border: 1px solid #000;
            height: 120px;
        }
        .signatures-table th {
            text-align: left;
        }
        .signatures-table td {
            height: 40px;
            vertical-align: bottom;
        }
        .page-number {
            text-align: center;
            margin-top: 5px;
            font-size: 11px;
        }
        .generated-on {
            font-size: 11px;
            text-align: right;
            margin-bottom: 8px;
        }
        @media print {
            .print-btn { display: none; }
            body { margin: 0; background: #ffffff; }
            .evaluation-page {
                page-break-after: always;
            }
        }
    </style>
</head>
<body>
    <div class="report-container">
        <button class="print-btn" onclick="window.print()">Print Report</button>
        <div class="generated-on">Generated on: <?php echo date('F j, Y \a\t g:i A'); ?></div>

        <?php if (isset($error)): ?>
            <div style="background: #f8d7da; color: #721c24; padding: 15px; border-radius: 5px; margin-bottom: 20px;">
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php else: ?>
            <?php if (empty($faculty_performance)): ?>
                <p>No faculty performance data available.</p>
            <?php else: ?>
                <?php $page = 1; ?>
                <?php foreach ($faculty_performance as $faculty): ?>
                    <?php if (!$faculty['evaluation_count']) continue; ?>
                    <div class="evaluation-page">
                        <div class="form-header">
                            Performance Evaluation for Teaching Effectiveness by the Student
                        </div>

                        <table class="meta-table">
                            <tr>
                                <td><strong>Instructor:</strong> <?php echo htmlspecialchars($faculty['full_name']); ?></td>
                                <td><strong>Department:</strong> <?php echo htmlspecialchars($faculty['department']); ?></td>
                            </tr>
                            <tr>
                                <td><strong>Subject:</strong> <?php echo htmlspecialchars($faculty['subject'] ?? ''); ?></td>
                                <td><strong>Evaluation Period:</strong>
                                    <?php
                                    $first = $faculty['first_evaluation'] ? date('M j, Y', strtotime($faculty['first_evaluation'])) : '';
                                    $last = $faculty['last_evaluation'] ? date('M j, Y', strtotime($faculty['last_evaluation'])) : '';
                                    echo trim($first . ($first && $last ? ' - ' : '') . $last);
                                    ?>
                                </td>
                            </tr>
                        </table>

                        <br>

                        <table class="rating-scale-table">
                            <tr>
                                <th colspan="5">Numerical Rating - Descriptive Rating</th>
                            </tr>
                            <tr>
                                <td>4.25 - 5.00 Outstanding</td>
                                <td>3.25 - 4.19 Very Satisfactory</td>
                                <td>2.25 - 3.19 Satisfactory</td>
                                <td>1.25 - 2.19 Fair</td>
                                <td>1.00 - 1.19 Poor</td>
                            </tr>
                        </table>

                        <br>

                        <table class="teaching-params-table">
                            <tr>
                                <th>Teaching Parameter</th>
                                <th style="width: 80px;">Rating</th>
                            </tr>
                            <tr>
                                <td>A. Commitment</td>
                                <td></td>
                            </tr>
                            <tr>
                                <td>B. Knowledge of Subject</td>
                                <td></td>
                            </tr>
                            <tr>
                                <td>C. Teaching for Independent Learning</td>
                                <td></td>
                            </tr>
                            <tr>
                                <td>D. Management of Learning</td>
                                <td></td>
                            </tr>
                            <tr>
                                <td>E. Student's Level of Satisfaction</td>
                                <td></td>
                            </tr>
                        </table>

                        <br>

                        <table class="average-rating-table">
                            <tr>
                                <th style="width: 70%;">Average Performance Rating</th>
                                <td>
                                    <?php echo $faculty['avg_rating'] ? number_format($faculty['avg_rating'], 2) : ''; ?>
                                </td>
                            </tr>
                        </table>

                        <div class="comments-section">
                            <div class="comments-label">Comments from Students</div>
                            <div class="comments-box">
                                <?php
                                $fid = isset($faculty['faculty_id']) ? (int)$faculty['faculty_id'] : 0;
                                $commentsText = $fid && isset($faculty_comments[$fid]) ? $faculty_comments[$fid] : '';
                                if ($commentsText):
                                    echo nl2br(htmlspecialchars($commentsText));
                                endif;
                                ?>
                            </div>
                        </div>

                        <br>

                        <table class="signatures-table">
                            <tr>
                                <th>Prepared by:</th>
                                <th>Noted by:</th>
                                <th>Reviewed by:</th>
                            </tr>
                            <tr>
                                <td></td>
                                <td></td>
                                <td></td>
                            </tr>
                            <tr>
                                <td>HRMO Staff</td>
                                <td>HR Coordinator</td>
                                <td>Dean</td>
                            </tr>
                        </table>

                        <div class="page-number">Page <?php echo $page++; ?></div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</body>
</html>
