<?php
require_once '../config.php';
requireRole('faculty');

// Validate ID from query string
$evalId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$evalId) {
    http_response_code(404);
    echo 'Evaluation not found.';
    exit;
}

// Validate ID

// Load evaluation ensuring it belongs to the logged-in faculty
$evaluation = null;
try {
    $stmt = $pdo->prepare("SELECT 
            e.*, 
            u.full_name AS faculty_name,
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
        JOIN users u ON f.user_id = u.id
        LEFT JOIN users ue ON e.evaluator_user_id = ue.id
        LEFT JOIN students s ON e.student_id = s.id
        LEFT JOIN users us ON s.user_id = us.id
        WHERE e.id = ? AND e.faculty_id = ?");
    $stmt->execute([$evalId, $_SESSION['faculty_id']]);
    $evaluation = $stmt->fetch();
} catch (PDOException $e) {
    $evaluation = null;
}

// Unreachable legacy not-found handling retained.

// Load criteria responses
$responses = [];
try {
    $stmt = $pdo->prepare("SELECT ec.category, ec.criterion, er.rating, er.comment
                           FROM evaluation_responses er
                           JOIN evaluation_criteria ec ON er.criterion_id = ec.id
                           WHERE er.evaluation_id = ?
                           ORDER BY ec.category, ec.id");
    $stmt->execute([$evalId]);
    $responses = $stmt->fetchAll();
} catch (PDOException $e) {
    $responses = [];
}

// Group responses by category
$grouped = [];
foreach ($responses as $r) {
    $grouped[$r['category']][] = $r;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Evaluation #<?php echo htmlspecialchars((string)$evalId); ?> - Details</title>
    <link rel="stylesheet" href="../styles.css" />
    <style>
        .container{max-width:1000px;margin:20px auto;background:#fff;border-radius:12px;box-shadow:var(--card-shadow);padding:24px}
        .header{display:flex;justify-content:space-between;align-items:center;margin-bottom:16px}
        .meta{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px;margin:12px 0}
        .meta-item{background:#f8fafc;border:1px solid #e5e7eb;border-radius:10px;padding:10px}
        .criteria{margin-top:18px}
        .category{background:#fff;border-radius:10px;border:1px solid #e5e7eb;margin-bottom:14px}
        .category h3{margin:0;padding:12px 14px;color:var(--primary-color);border-bottom:1px solid #e5e7eb}
        .row{display:grid;grid-template-columns:1fr 120px;gap:12px;padding:10px 14px;border-bottom:1px solid #f1f5f9}
        .row:last-child{border-bottom:none}
        .rating-badge{padding:2px 8px;border-radius:12px;font-weight:600;display:inline-block}
        .r5{background:#16a34a;color:#fff}.r4{background:#22c55e;color:#fff}.r3{background:#f59e0b;color:#fff}.r2{background:#ef4444;color:#fff}.r1{background:#b91c1c;color:#fff}
        .comment{color:#475569;font-size:.9rem;margin-top:4px}
        .back{display:inline-block;margin-bottom:10px}
        .btn{background:var(--primary-color);color:#fff;border:none;border-radius:8px;padding:8px 12px;text-decoration:none}
    </style>
</head>
<body>
    <div class="container">
        <div class="back"><a class="btn" href="faculty.php" onclick="history.back(); return false;">← Back</a></div>
        <div class="header">
            <h2>Evaluation Details</h2>
            <span class="rating-badge" style="background: var(--secondary-color); color:#fff;">Overall: <?php echo $evaluation['overall_rating'] ? number_format($evaluation['overall_rating'],2) : 'N/A'; ?></span>
        </div>

        <div class="meta">
            <div class="meta-item"><strong>Subject:</strong><br><?php echo htmlspecialchars($evaluation['subject']); ?></div>
            <div class="meta-item"><strong>Semester:</strong><br><?php echo htmlspecialchars($evaluation['semester']); ?></div>
            <div class="meta-item"><strong>Academic Year:</strong><br><?php echo htmlspecialchars($evaluation['academic_year']); ?></div>
            <div class="meta-item"><strong>Submitted:</strong><br><?php echo $evaluation['submitted_at'] ? date('M j, Y', strtotime($evaluation['submitted_at'])) : 'N/A'; ?></div>
            <div class="meta-item"><strong>Evaluator:</strong><br><?php echo htmlspecialchars($evaluation['evaluator_name']); ?> (<?php echo htmlspecialchars($evaluation['evaluator_role']); ?>)</div>
            <div class="meta-item"><strong>Status:</strong><br><?php echo htmlspecialchars(ucfirst($evaluation['status'])); ?></div>
        </div>

        <?php if (!empty($evaluation['comments'])): ?>
        <div class="meta" style="margin-top:8px;">
            <div class="meta-item" style="grid-column:1/-1;">
                <strong>Overall Comments:</strong><br>
                <span><?php echo nl2br(htmlspecialchars($evaluation['comments'])); ?></span>
            </div>
        </div>
        <?php endif; ?>

        <div class="criteria">
            <h2>Criteria Responses</h2>
            <?php if (empty($grouped)): ?>
                <p>No detailed responses recorded for this evaluation.</p>
            <?php else: ?>
                <?php foreach ($grouped as $category => $items): ?>
                    <div class="category">
                        <h3><?php echo htmlspecialchars($category); ?></h3>
                        <?php foreach ($items as $it): ?>
                            <div class="row">
                                <div>
                                    <div><?php echo htmlspecialchars($it['criterion']); ?></div>
                                    <?php if (!empty($it['comment'])): ?>
                                        <div class="comment">Comment: <?php echo nl2br(htmlspecialchars($it['comment'])); ?></div>
                                    <?php endif; ?>
                                </div>
                                <div style="text-align:right;align-self:center;">
                                    <?php $r = (int)$it['rating']; $cls = 'r'.max(1,min(5,$r)); ?>
                                    <span class="rating-badge <?php echo $cls; ?>"><?php echo $r; ?>/5</span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
