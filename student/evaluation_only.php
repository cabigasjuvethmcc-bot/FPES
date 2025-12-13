<?php
require_once '../config.php';
requireRole('student');

// Get student info
$stmt = $pdo->prepare("SELECT s.*, u.full_name, u.department FROM students s 
                       JOIN users u ON s.user_id = u.id 
                       WHERE s.id = ?");
$stmt->execute([$_SESSION['student_id']]);
$student = $stmt->fetch();

// Get evaluation criteria
$criteria = [];
try {
    $stmt = $pdo->prepare("SELECT * FROM evaluation_criteria ORDER BY category, criterion");
    $stmt->execute();
    $criteria = $stmt->fetchAll();
} catch (PDOException $e) {
    die('Unable to load evaluation criteria.');
}

// Group criteria by category
$grouped_criteria = [];
foreach ($criteria as $criterion) {
    $grouped_criteria[$criterion['category']][] = $criterion;
}

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
    <title>Faculty Evaluation - Faculty Performance Evaluation</title>
    <link rel="icon" href="../img/loginlogo.png?v=2" type="image/png">
    <link rel="stylesheet" href="../styles.css">
    <link rel="stylesheet" href="student.css">
    <style>
        body {
            background: #f3f4f6;
            margin: 0;
            padding: 20px;
        }
        .evaluation-container {
            max-width: 800px;
            margin: 0 auto;
            background: white;
            padding: 30px;
            border-radius: 12px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }
        .header-info {
            text-align: center;
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 1px solid #e5e7eb;
        }
        .header-info h1 {
            color: #1f2937;
            margin: 0 0 10px 0;
        }
        .header-info p {
            color: #6b7280;
            margin: 5px 0;
        }
        .success-message {
            background: #d1fae5;
            color: #065f46;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            border: 1px solid #10b981;
        }
        .error-message {
            background: #fee2e2;
            color: #991b1b;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            border: 1px solid #ef4444;
        }
        .criteria-category {
            margin-bottom: 30px;
        }
        .criteria-category h4 {
            color: #374151;
            margin-bottom: 15px;
            padding-bottom: 8px;
            border-bottom: 2px solid #e5e7eb;
        }
        .criterion-item {
            margin-bottom: 20px;
            padding: 15px;
            background: #f9fafb;
            border-radius: 8px;
        }
        .criterion-item label {
            display: block;
            font-weight: 500;
            color: #374151;
            margin-bottom: 10px;
        }
        .rating-scale {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }
        .rating-option {
            display: flex;
            flex-direction: column;
            align-items: center;
            cursor: pointer;
            padding: 10px;
            border: 2px solid #e5e7eb;
            border-radius: 8px;
            background: white;
            transition: all 0.2s;
        }
        .rating-option:hover {
            border-color: #3b82f6;
            background: #eff6ff;
        }
        .rating-option input[type="radio"] {
            margin-bottom: 5px;
        }
        .rating-option span {
            font-weight: bold;
            color: #374151;
        }
        .rating-option input[type="radio"]:checked + span {
            color: #3b82f6;
        }
        .rating-option:has(input[type="radio"]:checked) {
            border-color: #3b82f6;
            background: #eff6ff;
        }
        .form-group {
            margin-bottom: 20px;
        }
        .form-group label {
            display: block;
            font-weight: 500;
            color: #374151;
            margin-bottom: 8px;
        }
        .form-group textarea {
            width: 100%;
            padding: 12px;
            border: 1px solid #d1d5db;
            border-radius: 8px;
            resize: vertical;
            font-family: inherit;
        }
        .submit-btn {
            background: #3b82f6;
            color: white;
            padding: 15px 30px;
            border: none;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 500;
            cursor: pointer;
            transition: background 0.2s;
        }
        .submit-btn:hover {
            background: #2563eb;
        }
        .back-link {
            display: inline-block;
            margin-top: 20px;
            color: #6b7280;
            text-decoration: none;
        }
        .back-link:hover {
            color: #374151;
        }
    </style>
</head>
<body>
    <div class="evaluation-container">
        <div class="header-info">
            <h1>Faculty Evaluation</h1>
            <p><?php echo htmlspecialchars($student['full_name']); ?> | <?php echo htmlspecialchars($student['program']); ?></p>
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

        <?php if ($evalOpen): ?>
            <?php if ($quickEval && !empty($quickEval['faculty_id'])): ?>
                <div class="success-message">
                    This evaluation was started from a QR code. Please complete the form below.
                </div>
            <?php endif; ?>

            <form method="post" action="submit_evaluation.php">
                <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                
                <?php if ($quickEval && !empty($quickEval['faculty_id'])): ?>
                    <div class="form-group">
                        <label>Subject &amp; Faculty:</label>
                        <div style="padding:15px; background:#f9fafb; border-radius:8px; border:1px solid #e5e7eb;">
                            <strong><?php echo htmlspecialchars(($quickEval['subject_code'] ? $quickEval['subject_code'] . ' - ' : '') . ($quickEval['subject_name'] ?: 'Subject')); ?></strong><br>
                            <small>Faculty ID: <?php echo (int)$quickEval['faculty_id']; ?> (via QR)</small>
                        </div>
                        <input type="hidden" name="faculty_id" value="<?php echo (int)$quickEval['faculty_id']; ?>">
                        <input type="hidden" name="subject" value="<?php echo htmlspecialchars($quickEval['subject_name'] ?: $quickEval['subject_code']); ?>">
                    </div>
                <?php else: ?>
                    <div class="error-message">
                        No evaluation context found. Please scan a QR code to start an evaluation.
                    </div>
                <?php endif; ?>

                <?php if ($quickEval && !empty($quickEval['faculty_id'])): ?>
                    <div class="form-group">
                        <h3>Evaluation Criteria</h3>
                        <p style="color: #6b7280; margin-bottom: 20px;">
                            Please rate the faculty member's performance using the scale below:<br>
                            <strong>5</strong> = Outstanding | <strong>4</strong> = Very Satisfactory | <strong>3</strong> = Satisfactory | <strong>2</strong> = Fair | <strong>1</strong> = Poor
                        </p>
                        
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

                    <div class="form-group">
                        <label for="overall_comments">Additional Comments:</label>
                        <textarea id="overall_comments" name="overall_comments" rows="4" placeholder="Share your overall thoughts about this faculty member's performance"></textarea>
                    </div>

                    <button type="submit" class="submit-btn">Submit Evaluation</button>
                <?php endif; ?>
            </form>
        <?php endif; ?>

        <a href="student.php" class="back-link">← Back to Dashboard</a>
    </div>
</body>
</html>
