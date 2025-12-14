<?php
require_once '../config.php';
requireRole('faculty');

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Debug: Log incoming data
        error_log("Self-evaluation submission received: " . print_r($_POST, true));
        
        // Validate CSRF token
        if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
            throw new Exception('Invalid security token');
        }

        // The faculty member can only self-evaluate themselves
        $faculty_id = (int)($_POST['faculty_id'] ?? 0);
        if (!$faculty_id || $faculty_id !== (int)$_SESSION['faculty_id']) {
            throw new Exception('Invalid faculty selection for self-evaluation');
        }

        $subject_code = sanitizeInput($_POST['subject_code'] ?? '');
        $subject_name = sanitizeInput($_POST['subject_name'] ?? '');
        // Align with student flow: enforce active period if available
        $semester = '';
        $academic_year = '';
        if (function_exists('enforceActiveSemesterYear')) {
            list($ok, $err, $period) = enforceActiveSemesterYear($pdo);
            if (!$ok) { 
                // For debugging: allow self-evaluation even if evaluations are closed
                error_log("Evaluations are closed, but allowing self-evaluation for debugging. Error: " . $err);
                // Use fallback values
                $semester = sanitizeInput($_POST['semester'] ?? '');
                $academic_year = sanitizeInput($_POST['academic_year'] ?? '');
                // If no values posted, use current semester/year as fallback
                if (empty($semester) || empty($academic_year)) {
                    $currentMonth = (int)date('n');
                    $semester = ($currentMonth >= 6 && $currentMonth <= 10) ? '1st Semester' : '2nd Semester';
                    $currentYear = (int)date('Y');
                    $nextYear = $currentMonth >= 6 && $currentMonth <= 10 ? $currentYear + 1 : $currentYear;
                    $academic_year = "$currentYear-$nextYear";
                }
            } else {
                $semester = $period['semester'];
                $academic_year = $period['academic_year'];
            }
        } else {
            // Fallback to posted values if helper is not available
            $semester = sanitizeInput($_POST['semester'] ?? '');
            $academic_year = sanitizeInput($_POST['academic_year'] ?? '');
        }
        $overall_comments = sanitizeInput($_POST['overall_comments'] ?? '');

        if (!$subject_code || !$subject_name || !$semester || !$academic_year) {
            throw new Exception('All required fields must be filled');
        }

        // Ensure self-evaluation tables exist
        $pdo->exec("CREATE TABLE IF NOT EXISTS self_evaluation (
            id INT AUTO_INCREMENT PRIMARY KEY,
            faculty_id INT NOT NULL,
            subject_code VARCHAR(50) NOT NULL,
            subject_name VARCHAR(255) NOT NULL,
            semester VARCHAR(20) NOT NULL,
            academic_year VARCHAR(10) NOT NULL,
            overall_rating DECIMAL(3,2) NULL,
            overall_comments TEXT NULL,
            submitted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_self_eval (faculty_id, subject_code, subject_name, semester, academic_year),
            FOREIGN KEY (faculty_id) REFERENCES faculty(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS self_evaluation_responses (
            id INT AUTO_INCREMENT PRIMARY KEY,
            self_eval_id INT NOT NULL,
            criterion_id INT NOT NULL,
            rating INT NOT NULL,
            comment TEXT NULL,
            FOREIGN KEY (self_eval_id) REFERENCES self_evaluation(id) ON DELETE CASCADE,
            FOREIGN KEY (criterion_id) REFERENCES evaluation_criteria(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // Verify subject assignment for this faculty (using faculty.user_id -> faculty_subjects.faculty_user_id)
        $assigned = false;
        try {
            $chk = $pdo->prepare("SELECT 1 FROM faculty f
                JOIN faculty_subjects fs ON fs.faculty_user_id = f.user_id
                WHERE f.id = ? AND fs.subject_code = ? AND fs.subject_name = ? LIMIT 1");
            $chk->execute([$faculty_id, $subject_code, $subject_name]);
            $assigned = (bool)$chk->fetchColumn();
        } catch (PDOException $e) {
            $assigned = false;
        }
        if (!$assigned) {
            throw new Exception('You can only self-evaluate subjects assigned to you.');
        }

        // Prevent duplicate self-evaluation for the same subject/term/year
        // Handle subjects that may not have a code (empty subject_code) by falling back to subject_name
        $stmt = $pdo->prepare("SELECT id FROM self_evaluation 
                               WHERE faculty_id = ? 
                                 AND ((subject_code <> '' AND subject_code = ?) OR (subject_code = '' AND subject_name = ?))
                                 AND semester = ? AND academic_year = ?
                               LIMIT 1");
        $stmt->execute([$faculty_id, $subject_code, $subject_name, $semester, $academic_year]);
        if ($stmt->fetch()) {
            throw new Exception('You have already submitted a self-evaluation for this subject and term.');
        }

        $pdo->beginTransaction();
        // Insert self-evaluation main record
        $stmt = $pdo->prepare("INSERT INTO self_evaluation (faculty_id, subject_code, subject_name, semester, academic_year, overall_comments) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$faculty_id, $subject_code, $subject_name, $semester, $academic_year, $overall_comments]);
        $self_eval_id = (int)$pdo->lastInsertId();

        // Criteria ratings
        $total_rating = 0;
        $criteria_count = 0;

        foreach ($_POST as $key => $value) {
            if (strpos($key, 'rating_') === 0) {
                $criterion_id = (int)str_replace('rating_', '', $key);
                $rating = (int)$value;

                if ($rating >= 1 && $rating <= 5) {
                    $stmt = $pdo->prepare("INSERT INTO self_evaluation_responses (self_eval_id, criterion_id, rating, comment) VALUES (?, ?, ?, NULL)");
                    $stmt->execute([$self_eval_id, $criterion_id, $rating]);
                    $total_rating += $rating;
                    $criteria_count++;
                }
            }
        }

        if ($criteria_count > 0) {
            $overall_rating = round($total_rating / $criteria_count, 2);
            $stmt = $pdo->prepare("UPDATE self_evaluation SET overall_rating = ? WHERE id = ?");
            $stmt->execute([$overall_rating, $self_eval_id]);
        }

        $pdo->commit();

        echo json_encode([
            'success' => true,
            'message' => 'Your self-evaluation for ' . htmlspecialchars($subject_code) . ' has been submitted successfully.'
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
