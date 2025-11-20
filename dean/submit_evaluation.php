<?php
require_once '../config.php';
requireRole('dean');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Validate CSRF token
        if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
            throw new Exception('Invalid security token');
        }

        // Enforce evaluation schedule and active period for deans
        list($ok, $err, $period) = enforceActiveSemesterYear($pdo);
        if (!$ok) {
            throw new Exception($err);
        }

        // Gather inputs
        $faculty_id = (int)($_POST['faculty_id'] ?? 0);
        $subject = sanitizeInput($_POST['subject'] ?? '');
        // Force semester/year to active period
        $semester = $period['semester'];
        $academic_year = $period['academic_year'];
        $overall_comments = sanitizeInput($_POST['overall_comments'] ?? '');

        if (!$faculty_id || !$subject) {
            throw new Exception('All required fields must be filled');
        }

        // Verify faculty exists AND belongs to the dean's department
        $stmt = $pdo->prepare("SELECT f.id
                               FROM faculty f
                               JOIN users u ON f.user_id = u.id
                               WHERE f.id = ? AND u.department = ?");
        $stmt->execute([$faculty_id, $_SESSION['department'] ?? '']);
        if (!$stmt->fetch()) {
            throw new Exception('You can only evaluate faculty within your department.');
        }

        // Prevent duplicate evaluations: one evaluation per dean per faculty per subject+period
        try {
            $stmt = $pdo->prepare("SELECT id FROM evaluations
                                    WHERE faculty_id = ?
                                      AND subject = ?
                                      AND semester = ?
                                      AND academic_year = ?
                                      AND ((evaluator_user_id = ? AND evaluator_role = 'dean') OR (evaluator_user_id IS NULL AND COALESCE(evaluator_role,'') = ''))
                                      AND status = 'submitted'
                                    LIMIT 1");
            $stmt->execute([$faculty_id, $subject, $semester, $academic_year, $_SESSION['user_id']]);
            if ($stmt->fetch()) {
                throw new Exception('You have already evaluated this faculty for the selected subject and period.');
            }
        } catch (PDOException $e) {
            // Fallback for older schemas without evaluator columns: approximate by lack of evaluator fields
            $stmt = $pdo->prepare("SELECT id FROM evaluations
                                    WHERE faculty_id = ? AND subject = ? AND semester = ? AND academic_year = ?
                                      AND status = 'submitted' AND is_anonymous = 1
                                    LIMIT 1");
            $stmt->execute([$faculty_id, $subject, $semester, $academic_year]);
            if ($stmt->fetch()) {
                throw new Exception('You have already evaluated this faculty for the selected subject and period.');
            }
        }

        // Ensure evaluations table supports evaluator metadata
        try {
            $pdo->exec("ALTER TABLE evaluations 
                ADD COLUMN IF NOT EXISTS evaluator_user_id INT NULL,
                ADD COLUMN IF NOT EXISTS evaluator_role ENUM('student','faculty','dean') NULL,
                ADD COLUMN IF NOT EXISTS is_self BOOLEAN DEFAULT 0");
        } catch (PDOException $e) {
            // ignore if not supported or already exists
        }
        try {
            $pdo->exec("ALTER TABLE evaluations MODIFY student_id INT NULL");
        } catch (PDOException $e) {
            // ignore if already nullable
        }

        // Ensure unique indexes exist (safe no-op if already present)
        if (function_exists('ensureEvaluationUniqueIndexes')) {
            ensureEvaluationUniqueIndexes($pdo);
        }

        $pdo->beginTransaction();

        // Dean evaluations must be anonymous
        $is_anonymous = 1;

        // Insert evaluation; deans have no student_id
        try {
            $stmt = $pdo->prepare("INSERT INTO evaluations 
                (student_id, faculty_id, semester, academic_year, subject, comments, is_anonymous, evaluator_user_id, evaluator_role, is_self, status, submitted_at)
                VALUES (NULL, ?, ?, ?, ?, ?, ?, ?, 'dean', 0, 'submitted', NOW())");
            $stmt->execute([$faculty_id, $semester, $academic_year, $subject, $overall_comments, $is_anonymous, $_SESSION['user_id']]);
        } catch (PDOException $e) {
            // fallback without evaluator columns
            $stmt = $pdo->prepare("INSERT INTO evaluations 
                (student_id, faculty_id, semester, academic_year, subject, comments, is_anonymous, status, submitted_at)
                VALUES (NULL, ?, ?, ?, ?, ?, ?, 'submitted', NOW())");
            $stmt->execute([$faculty_id, $semester, $academic_year, $subject, $overall_comments, $is_anonymous]);
        }

        $evaluation_id = $pdo->lastInsertId();

        // Criteria ratings
        $total_rating = 0;
        $criteria_count = 0;

        foreach ($_POST as $key => $value) {
            if (strpos($key, 'rating_') === 0) {
                $criterion_id = (int)str_replace('rating_', '', $key);
                $rating = (int)$value;
                $comment_key = 'comment_' . $criterion_id;
                $comment = sanitizeInput($_POST[$comment_key] ?? '');

                if ($rating >= 1 && $rating <= 5) {
                    $stmt = $pdo->prepare("INSERT INTO evaluation_responses (evaluation_id, criterion_id, rating, comment) VALUES (?, ?, ?, ?)");
                    $stmt->execute([$evaluation_id, $criterion_id, $rating, $comment]);
                    $total_rating += $rating;
                    $criteria_count++;
                }
            }
        }

        if ($criteria_count > 0) {
            $overall_rating = round($total_rating / $criteria_count, 2);
            $stmt = $pdo->prepare("UPDATE evaluations SET overall_rating = ? WHERE id = ?");
            $stmt->execute([$overall_rating, $evaluation_id]);
        }

        $pdo->commit();

        echo json_encode([
            'success' => true,
            'message' => '✅ Evaluation submitted successfully. You cannot evaluate this faculty again for the same subject and period.'
        ]);
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
    }
} else {
    echo json_encode([
        'success' => false,
        'message' => 'Invalid request method'
    ]);
}
