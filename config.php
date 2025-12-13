<?php
// Database configuration (use environment variables in production, fall back to local defaults)
define('DB_HOST', getenv('DB_HOST') !== false ? getenv('DB_HOST') : 'localhost');
define('DB_USER', getenv('DB_USER') !== false ? getenv('DB_USER') : 'root');
define('DB_PASS', getenv('DB_PASS') !== false ? getenv('DB_PASS') : '');
define('DB_NAME', getenv('DB_NAME') !== false ? getenv('DB_NAME') : 'faculty_evaluation_system');
define('DB_PORT', getenv('DB_PORT') !== false ? getenv('DB_PORT') : null);

// Global timezone: Philippine Standard Time
// Ensures all PHP DateTime/date() calls use Asia/Manila across the app
date_default_timezone_set('Asia/Manila');

// Database connection
try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME;
    if (DB_PORT) {
        $dsn .= ";port=" . DB_PORT;
    }
    $pdo = new PDO($dsn, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    // Align MySQL session time zone with PHP (Asia/Manila is UTC+08:00)
    // This ensures NOW(), CURRENT_TIMESTAMP, and TIMESTAMP columns reflect Philippine time
    try { $pdo->exec("SET time_zone = '+08:00'"); } catch (PDOException $e) { /* ignore if not permitted */ }
    // Ensure critical tables that are referenced across pages always exist
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS student_faculty_subjects (
            id INT AUTO_INCREMENT PRIMARY KEY,
            student_user_id INT NOT NULL,
            faculty_user_id INT NOT NULL,
            subject_code VARCHAR(50) DEFAULT NULL,
            subject_name VARCHAR(255) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_assignment (student_user_id, faculty_user_id, subject_code, subject_name),
            INDEX idx_student (student_user_id),
            INDEX idx_faculty (faculty_user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    } catch (PDOException $e) { /* ignore create error; page-level logic may also ensure */ }
} catch(PDOException $e) {
    die("Connection failed: " . $e->getMessage());
}

// ---------------- Database-backed Sessions ----------------
// Render/hosting environments may run multiple instances or ephemeral filesystems.
// Storing sessions in MySQL ensures login state persists reliably across redirects.
if (!class_exists('DbSessionHandler')) {
    class DbSessionHandler implements SessionHandlerInterface {
        private PDO $pdo;
        private string $table;

        public function __construct(PDO $pdo, string $table = 'app_sessions') {
            $this->pdo = $pdo;
            $this->table = $table;
        }

        public function open($savePath, $sessionName): bool { return true; }
        public function close(): bool { return true; }

        public function read($id): string {
            try {
                $stmt = $this->pdo->prepare("SELECT data FROM {$this->table} WHERE id = ? LIMIT 1");
                $stmt->execute([(string)$id]);
                $stmt->bindColumn(1, $data, PDO::PARAM_LOB);
                $stmt->fetch(PDO::FETCH_BOUND);
                
                // Convert LOB resource to string if needed
                if (is_resource($data)) {
                    $data = stream_get_contents($data);
                }
                $data = $data ? (string)$data : '';
                
                // Decode base64 session data
                $decodedData = base64_decode($data);
                $decodedData = $decodedData !== false ? $decodedData : '';
                
                // Debug: log full session data being read
                file_put_contents(__DIR__ . '/signup_debug.log', "[" . date('Y-m-d H:i:s') . "] SESSION READ: id=$id, stored_length=" . strlen($data) . ", decoded_length=" . strlen($decodedData) . ", data=" . $decodedData . "\n", FILE_APPEND);
                
                return $decodedData;
            } catch (PDOException $e) {
                file_put_contents(__DIR__ . '/signup_debug.log', "[" . date('Y-m-d H:i:s') . "] SESSION READ ERROR: " . $e->getMessage() . "\n", FILE_APPEND);
                return '';
            }
        }

        public function write($id, $data): bool {
            try {
                // Encode session data to prevent truncation issues
                $encodedData = base64_encode($data);
                
                // Debug: log full session data being written
                file_put_contents(__DIR__ . '/signup_debug.log', "[" . date('Y-m-d H:i:s') . "] SESSION WRITE: id=$id, original_length=" . strlen($data) . ", encoded_length=" . strlen($encodedData) . ", data=" . $data . "\n", FILE_APPEND);
                
                $stmt = $this->pdo->prepare(
                    "INSERT INTO {$this->table} (id, data, last_activity) VALUES (?, ?, ?)\n" .
                    "ON DUPLICATE KEY UPDATE data = VALUES(data), last_activity = VALUES(last_activity)"
                );
                $result = $stmt->execute([(string)$id, $encodedData, time()]);
                
                // Debug: verify what was actually written
                if ($result) {
                    $verify = $this->pdo->prepare("SELECT data FROM {$this->table} WHERE id = ? LIMIT 1");
                    $verify->execute([(string)$id]);
                    $written = $verify->fetch(PDO::FETCH_ASSOC);
                    file_put_contents(__DIR__ . '/signup_debug.log', "[" . date('Y-m-d H:i:s') . "] SESSION WRITE VERIFY: stored_length=" . strlen($written['data'] ?? '') . ", stored_data=" . ($written['data'] ?? '') . "\n", FILE_APPEND);
                }
                
                return $result;
            } catch (PDOException $e) {
                file_put_contents(__DIR__ . '/signup_debug.log', "[" . date('Y-m-d H:i:s') . "] SESSION WRITE ERROR: " . $e->getMessage() . "\n", FILE_APPEND);
                return false;
            }
        }

        public function destroy($id): bool {
            try {
                $stmt = $this->pdo->prepare("DELETE FROM {$this->table} WHERE id = ?");
                return $stmt->execute([(string)$id]);
            } catch (PDOException $e) {
                return false;
            }
        }

        public function gc($max_lifetime): int|false {
            try {
                $cutoff = time() - (int)$max_lifetime;
                $stmt = $this->pdo->prepare("DELETE FROM {$this->table} WHERE last_activity < ?");
                $stmt->execute([$cutoff]);
                return $stmt->rowCount();
            } catch (PDOException $e) {
                return false;
            }
        }
    }
}

// Create session table if needed
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS app_sessions (
        id VARCHAR(128) NOT NULL PRIMARY KEY,
        data MEDIUMBLOB NOT NULL,
        last_activity INT NOT NULL,
        INDEX idx_last_activity (last_activity)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
} catch (PDOException $e) {
    // If table creation fails, PHP will fall back to file sessions once session_start runs.
}

// Register handler before starting session
try {
    $handler = new DbSessionHandler($pdo);
    session_set_save_handler($handler, true);
} catch (Throwable $e) {
    // ignore; fall back to default handler
}

// Start session (after configuring handler + cookie params)
if (session_status() === PHP_SESSION_NONE) {
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

// ---------------- Semester/Academic Year Helpers ----------------
// Returns array ['semester' => '1st Semester'|'2nd Semester', 'academic_year' => 'YYYY-YYYY+1'] based on current date
if (!function_exists('getCurrentSemesterYear')) {
    function getCurrentSemesterYear(DateTime $now = null) {
        $now = $now ?: new DateTime('now');
        $y = (int)$now->format('Y');
        $m = (int)$now->format('n');
        if ($m >= 8 && $m <= 12) { // Aug-Dec
            $semester = '1st Semester';
            $academic_year = sprintf('%d-%d', $y, $y + 1);
        } else { // Jan-Jun
            $semester = '2nd Semester';
            $academic_year = sprintf('%d-%d', $y - 1, $y);
        }
        return ['semester' => $semester, 'academic_year' => $academic_year];
    }
}

// Derive the active semester/year tied to evaluation schedule. If schedule is open, returns current period.
// If schedule is closed or unscheduled, returns null to indicate evaluations are unavailable.
if (!function_exists('getActiveSemesterYear')) {
    function getActiveSemesterYear($pdo) {
        list($openNow,,,$sch) = isEvaluationOpenForStudents($pdo);
        if (!$openNow) { return null; }
        // Optionally, we could anchor to schedule window boundaries, but current date suffices while open
        return getCurrentSemesterYear(new DateTime('now'));
    }
}

// Enforce that evaluations use the active semester/year. Returns [bool ok, string error|null, array period|null]
if (!function_exists('enforceActiveSemesterYear')) {
    function enforceActiveSemesterYear($pdo) {
        $period = getActiveSemesterYear($pdo);
        if (!$period) {
            return [false, 'Evaluation is not available for this semester. Please wait for the current evaluation schedule.', null];
        }
        return [true, null, $period];
    }
}

// Helper functions
function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

function requireLogin() {
    if (!isLoggedIn()) {
        if (isJsonRequest() && $_SERVER['REQUEST_METHOD'] === 'POST') {
            http_response_code(401);
            echo json_encode([
                'success' => false,
                'message' => 'Authentication required. Please log in again.'
            ]);
            exit();
        }
        header('Location: index.php');
        exit();
    }
}

function hasRole($role) {
    return isset($_SESSION['role']) && $_SESSION['role'] === $role;
}

function isJsonRequest() {
    $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
    $xhr    = $_SERVER['HTTP_X_REQUESTED_WITH'] ?? '';
    return (stripos($accept, 'application/json') !== false)
        || (strtolower($xhr) === 'xmlhttprequest');
}

function requireRole($role) {
    requireLogin();
    if (!hasRole($role)) {
        if (isJsonRequest() && $_SERVER['REQUEST_METHOD'] === 'POST') {
            http_response_code(403);
            echo json_encode([
                'success' => false,
                'message' => 'Admin access required.'
            ]);
            exit();
        }
        header('Location: dashboard.php');
        exit();
    }
}

function sanitizeInput($input) {
    return htmlspecialchars(strip_tags(trim($input)));
}

function classifySentiment($text) {
    $clean = strtolower($text);
    $clean = preg_replace('/[^a-z0-9\s]+/i', ' ', $clean);
    $words = preg_split('/\s+/', $clean, -1, PREG_SPLIT_NO_EMPTY);

    $positives = [
        'excellent','great','good','helpful','kind','clear','organized','fair','respectful','approachable','amazing','outstanding','supportive','patient','encouraging','inspiring','nice','friendly',
        // school-related / taglish
        'maayos','mabait','mabuti','magaling','matalino','masipag','maunawain','maayos','approachabl','helpful','friendly','top','best','galing','ayos','ok','okay','cool','thebest'
    ];
    $negatives = [
        'bad','rude','unclear','confusing','late','boring','unprepared','unfair','disrespectful','strict','terrible','worst','lazy','arrogant','inconsiderate','unavailable','useless','annoying',
        // school-related / taglish
        'panget','pangit','bastos','salbahe','unfair','hindi','walang','tamad','sobrang','galit','sungit','terror','harsh','bully','bias','biased','grabe','worst','abusive'
    ];

    $pos = 0;
    $neg = 0;
    foreach ($words as $w) {
        if (in_array($w, $positives, true)) {
            $pos++;
        } elseif (in_array($w, $negatives, true)) {
            $neg++;
        }
    }

    if ($pos === 0 && $neg === 0) {
        return 'neutral';
    }
    if ($pos > $neg) {
        return 'positive';
    }
    if ($neg > $pos) {
        return 'negative';
    }
    return 'neutral';
}

function generateCSRFToken() {
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function validateCSRFToken($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

// ---------------- Evaluation Schedule Helpers ----------------
// Global schedule, applies to all departments/students
function ensureEvaluationScheduleTable($pdo) {
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS evaluation_schedule (
            id INT PRIMARY KEY,
            start_at DATETIME NULL,
            end_at DATETIME NULL,
            override_mode ENUM('auto','open','closed') DEFAULT 'auto',
            notice VARCHAR(255) NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
        // Ensure singleton row exists
        $stmt = $pdo->prepare("SELECT COUNT(*) AS c FROM evaluation_schedule WHERE id = 1");
        $stmt->execute();
        $row = $stmt->fetch();
        if (!$row || (int)$row['c'] === 0) {
            $pdo->prepare("INSERT INTO evaluation_schedule (id, start_at, end_at, override_mode, notice) VALUES (1, NULL, NULL, 'auto', NULL)")->execute();
        }
    } catch (PDOException $e) {
        // ignore creation errors; callers should handle absence gracefully
    }
}

function getEvaluationSchedule($pdo) {
    ensureEvaluationScheduleTable($pdo);
    try {
        $stmt = $pdo->prepare("SELECT * FROM evaluation_schedule WHERE id = 1 LIMIT 1");
        $stmt->execute();
        $row = $stmt->fetch();
        return $row ?: [
            'id' => 1,
            'start_at' => null,
            'end_at' => null,
            'override_mode' => 'auto',
            'notice' => null,
            'updated_at' => null
        ];
    } catch (PDOException $e) {
        return [
            'id' => 1,
            'start_at' => null,
            'end_at' => null,
            'override_mode' => 'auto',
            'notice' => null,
            'updated_at' => null
        ];
    }
}

// Returns [is_open(bool), state(string: 'open'|'closed'), reason(string: 'override'|'schedule'|'unscheduled'), schedule(array)]
function isEvaluationOpenForStudents($pdo) {
    $sch = getEvaluationSchedule($pdo);
    $override = $sch['override_mode'] ?? 'auto';
    $now = new DateTime('now');

    if ($override === 'open') {
        return [true, 'open', 'override', $sch];
    }
    if ($override === 'closed') {
        return [false, 'closed', 'override', $sch];
    }
    // auto mode: rely on schedule window
    $startAt = !empty($sch['start_at']) ? new DateTime($sch['start_at']) : null;
    $endAt = !empty($sch['end_at']) ? new DateTime($sch['end_at']) : null;
    if ($startAt && $endAt) {
        if ($now >= $startAt && $now <= $endAt) {
            return [true, 'open', 'schedule', $sch];
        }
        return [false, 'closed', 'schedule', $sch];
    }
    // no schedule set
    return [false, 'closed', 'unscheduled', $sch];
}

function saveEvaluationSchedule($pdo, $startAt, $endAt, $notice = null) {
    ensureEvaluationScheduleTable($pdo);
    $stmt = $pdo->prepare("UPDATE evaluation_schedule SET start_at = ?, end_at = ?, notice = ? WHERE id = 1");
    $stmt->execute([$startAt ?: null, $endAt ?: null, $notice]);
}

function setEvaluationOverride($pdo, $mode) {
    ensureEvaluationScheduleTable($pdo);
    // $mode must be one of auto|open|closed
    if (!in_array($mode, ['auto','open','closed'], true)) { $mode = 'auto'; }
    $stmt = $pdo->prepare("UPDATE evaluation_schedule SET override_mode = ? WHERE id = 1");
    $stmt->execute([$mode]);
}

// Ensure unique indexes to enforce one evaluation per faculty per period
function ensureEvaluationUniqueIndexes($pdo) {
    try {
        // Check and create uniq_student_eval
        $stmt = $pdo->prepare("SELECT COUNT(*) AS c FROM information_schema.statistics WHERE table_schema = ? AND table_name = 'evaluations' AND index_name = 'uniq_student_eval'");
        $stmt->execute([DB_NAME]);
        $row = $stmt->fetch();
        if ((int)($row['c'] ?? 0) === 0) {
            $pdo->exec("CREATE UNIQUE INDEX uniq_student_eval ON evaluations (student_id, faculty_id, subject, semester, academic_year)");
        }
    } catch (PDOException $e) { /* ignore */ }

    try {
        // Check and create uniq_dean_eval
        $stmt = $pdo->prepare("SELECT COUNT(*) AS c FROM information_schema.statistics WHERE table_schema = ? AND table_name = 'evaluations' AND index_name = 'uniq_dean_eval'");
        $stmt->execute([DB_NAME]);
        $row = $stmt->fetch();
        if ((int)($row['c'] ?? 0) === 0) {
            $pdo->exec("CREATE UNIQUE INDEX uniq_dean_eval ON evaluations (evaluator_user_id, evaluator_role, faculty_id, subject, semester, academic_year)");
        }
    } catch (PDOException $e) { /* ignore */ }
}

// One-time migration: replace legacy default evaluation criteria with
// standardized AD Google Form-based criteria if old categories are detected
if (!function_exists('ensureNewEvaluationCriteria')) {
    function ensureNewEvaluationCriteria(PDO $pdo) {
        try {
            // Check if table exists
            $check = $pdo->query("SHOW TABLES LIKE 'evaluation_criteria'");
            if ($check->rowCount() === 0) { return; }

            // Count rows and detect presence of legacy categories
            $total = (int)$pdo->query('SELECT COUNT(*) AS c FROM evaluation_criteria')->fetch()['c'];
            $legacy = (int)$pdo->query("SELECT COUNT(*) AS c FROM evaluation_criteria WHERE category IN ('Teaching Effectiveness','Student Engagement','Assessment','Professional Conduct','Course Content')")->fetch()['c'];
            // Only skip reseeding if table already matches the new template size
            // and contains no legacy categories. Otherwise, auto-fix.
            if ($total === 20 && $legacy === 0) {
                return;
            }

            $pdo->beginTransaction();
            $pdo->exec('DELETE FROM evaluation_criteria');
            $stmt = $pdo->prepare('INSERT INTO evaluation_criteria (category, criterion, description, weight, is_active) VALUES (?, ?, NULL, 1.00, 1)');

            $rows = [
                // A. COMMITMENT
                ['A. COMMITMENT', "Demonstrates sensitivity to student's ability to attend and absorb content information"],
                ['A. COMMITMENT', "Integrates sensitively her/his learning objectives with those of the students in a collaborative process"],
                ['A. COMMITMENT', "Makes her/himself available to students beyond official time."],
                ['A. COMMITMENT', "Regularly comes to class on time, well-groomed and well-prepared to complete assigned responsibilities"],
                ['A. COMMITMENT', "Keeps accurate records of student's performance and prompt submission of the same."],

                // B. KNOWLEDGE OF THE SUBJECT
                ['B. KNOWLEDGE OF THE SUBJECT', 'Demonstrates mastery of the subject matter (Explain the subject matter without relying solely on the prescribed textbook)'],
                ['B. KNOWLEDGE OF THE SUBJECT', 'Draws and share information on the state on the art of theory and practice in her/his discipline'],
                ['B. KNOWLEDGE OF THE SUBJECT', 'Integrates subjects to practical circumstances and learning intents/purposes of students.'],
                ['B. KNOWLEDGE OF THE SUBJECT', 'Explains the relevance of present topics to the previous lessons, and relates the subject matter to relevant current issues and/or daily life activities.'],
                ['B. KNOWLEDGE OF THE SUBJECT', 'Demonstrates up to date knowledge and/or awareness on current trends and issues of the subject.'],

                // C. TEACHING FOR INDEPENDENT LEARNING
                ['C. TEACHING FOR INDEPENDENT LEARNING', 'Creates teaching strategies that allow students to practice using concepts they need to understand (interactive discussion)'],
                ['C. TEACHING FOR INDEPENDENT LEARNING', "Enhances students self-esteem and/or gives due recognition to student's performance/potentials."],
                ['C. TEACHING FOR INDEPENDENT LEARNING', 'Allows students to create their own course with objectives and realistically defined student-professor rules and make them accountable for their performance'],
                ['C. TEACHING FOR INDEPENDENT LEARNING', 'Allows students to think independently and make their own decisions and holds them accountable for their performance based largely on their success in executing decisions.'],
                ['C. TEACHING FOR INDEPENDENT LEARNING', 'Encourages students to learn beyond what is required and helps/guides the students how to apply the concepts learned.'],

                // D. MANAGEMENT OF LEARNING
                ['D. MANAGEMENT OF LEARNING', 'Creates opportunities for intensive and/or extensive contribution of students in the class activities (e.g. breaks class into dyads, triads or buzz/task groups).'],
                ['D. MANAGEMENT OF LEARNING', 'Drawing students to contribute to knowledge and understanding of the concepts at hand.'],
                ['D. MANAGEMENT OF LEARNING', 'Designs and implements learning conditions and experiences that promote healthy exchange and/or confrontations.'],
                ['D. MANAGEMENT OF LEARNING', 'Structures/re-structures learning and teaching-learning context to enhance attainment of collective learning objectives.'],
                ['D. MANAGEMENT OF LEARNING', 'Use of instructional materials (audio/video materials: fieldtrips, film showing, computer-aided instruction, etc.) to reinforce learning processes.'],
            ];

            foreach ($rows as $r) {
                $stmt->execute([$r[0], $r[1]]);
            }
            $pdo->commit();
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            // Silent failure; pages will continue using existing criteria
        }
    }
}
?>
