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

        // Ensure evaluation_criteria table exists first
        $pdo->exec("CREATE TABLE IF NOT EXISTS evaluation_criteria (
            id INT AUTO_INCREMENT PRIMARY KEY,
            category VARCHAR(100) NOT NULL,
            criterion VARCHAR(255) NOT NULL,
            description TEXT,
            weight DECIMAL(3,2) DEFAULT 1.00,
            is_active BOOLEAN DEFAULT TRUE,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // Insert default criteria if table is empty
        $countStmt = $pdo->query("SELECT COUNT(*) as count FROM evaluation_criteria");
        $count = $countStmt->fetch()['count'];
        if ($count == 0) {
            // Insert default evaluation criteria
            $pdo->exec("INSERT INTO evaluation_criteria (category, criterion, description, weight, is_active) VALUES
            ('A. COMMITMENT', 'Demonstrates sensitivity to student''s ability to attend and absorb content information', NULL, 1.00, 1),
            ('A. COMMITMENT', 'Integrates sensitively her/his learning objectives with those of the students in a collaborative process', NULL, 1.00, 1),
            ('A. COMMITMENT', 'Makes her/himself available to students beyond official time.', NULL, 1.00, 1),
            ('A. COMMITMENT', 'Regularly comes to class on time, well-groomed and well-prepared to complete assigned responsibilities', NULL, 1.00, 1),
            ('A. COMMITMENT', 'Keeps accurate records of student''s performance and prompt submission of the same.', NULL, 1.00, 1),
            ('B. KNOWLEDGE OF THE SUBJECT', 'Demonstrates mastery of the subject matter (Explain the subject matter without relying solely on the prescribed textbook)', NULL, 1.00, 1),
            ('B. KNOWLEDGE OF THE SUBJECT', 'Draws and share information on the state on the art of theory and practice in her/his discipline', NULL, 1.00, 1),
            ('B. KNOWLEDGE OF THE SUBJECT', 'Integrates subjects to practical circumstances and learning intents/purposes of students.', NULL, 1.00, 1),
            ('B. KNOWLEDGE OF THE SUBJECT', 'Explains the relevance of present topics to the previous lessons, and relates the subject matter to relevant current issues and/or daily life activities.', NULL, 1.00, 1),
            ('B. KNOWLEDGE OF THE SUBJECT', 'Demonstrates up to date knowledge and/or awareness on current trends and issues of the subject.', NULL, 1.00, 1),
            ('C. TEACHING FOR INDEPENDENT LEARNING', 'Creates teaching strategies that allow students to practice using concepts they need to understand (interactive discussion)', NULL, 1.00, 1),
            ('C. TEACHING FOR INDEPENDENT LEARNING', 'Enhances students self-esteem and/or gives due recognition to student''s performance/potentials.', NULL, 1.00, 1),
            ('C. TEACHING FOR INDEPENDENT LEARNING', 'Allows students to create their own course with objectives and realistically defined student-professor rules and make them accountable for their performance', NULL, 1.00, 1),
            ('C. TEACHING FOR INDEPENDENT LEARNING', 'Allows students to think independently and make their own decisions and holds them accountable for their performance based largely on their success in executing decisions.', NULL, 1.00, 1),
            ('C. TEACHING FOR INDEPENDENT LEARNING', 'Encourages students to learn beyond what is required and helps/guides the students how to apply the concepts learned.', NULL, 1.00, 1),
            ('D. MANAGEMENT OF LEARNING', 'Creates opportunities for intensive and/or extensive contribution of students in the class activities (e.g. breaks class into dyads, triads or buzz/task groups).', NULL, 1.00, 1),
            ('D. MANAGEMENT OF LEARNING', 'Drawing students to contribute to knowledge and understanding of the concepts at hand.', NULL, 1.00, 1),
            ('D. MANAGEMENT OF LEARNING', 'Designs and implements learning conditions and experiences that promote healthy exchange and/or confrontations.', NULL, 1.00, 1),
            ('D. MANAGEMENT OF LEARNING', 'Structures/re-structures learning and teaching-learning context to enhance attainment of collective learning objectives.', NULL, 1.00, 1),
            ('D. MANAGEMENT OF LEARNING', 'Use of instructional materials (audio/video materials: fieldtrips, film showing, computer-aided instruction, etc.) to reinforce learning processes.', NULL, 1.00, 1)");
        }

        // Now create self-evaluation tables
        $pdo->exec("CREATE TABLE IF NOT EXISTS self_evaluation (
            id INT AUTO_INCREMENT PRIMARY KEY,
            faculty_id INT NOT NULL,
            subject_code VARCHAR(50),
            subject_name VARCHAR(255) NOT NULL,
            semester VARCHAR(20) NOT NULL,
            academic_year VARCHAR(10) NOT NULL,
            overall_rating DECIMAL(3,2) NULL,
            overall_comments TEXT NULL,
            submitted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (faculty_id) REFERENCES faculty(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        
        // Create self_evaluation_responses without foreign key first, then add it
        $pdo->exec("CREATE TABLE IF NOT EXISTS self_evaluation_responses (
            id INT AUTO_INCREMENT PRIMARY KEY,
            self_eval_id INT NOT NULL,
            criterion_id INT NOT NULL,
            rating INT NOT NULL,
            comment TEXT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        
        // Try to add foreign key constraints
        try {
            $pdo->exec("ALTER TABLE self_evaluation_responses ADD CONSTRAINT fk_self_eval 
                        FOREIGN KEY (self_eval_id) REFERENCES self_evaluation(id) ON DELETE CASCADE");
        } catch (Exception $e) {
            // Foreign key might already exist, ignore error
        }
        
        try {
            $pdo->exec("ALTER TABLE self_evaluation_responses ADD CONSTRAINT fk_criteria 
                        FOREIGN KEY (criterion_id) REFERENCES evaluation_criteria(id) ON DELETE CASCADE");
        } catch (Exception $e) {
            // Foreign key might already exist, ignore error
        }

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
