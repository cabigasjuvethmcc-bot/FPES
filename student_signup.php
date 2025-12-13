<?php
require_once 'config.php';
require_once 'catalog.php';

try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS pending_registrations (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NULL,
        role ENUM('student') NOT NULL DEFAULT 'student',
        full_name VARCHAR(100) NOT NULL,
        student_id VARCHAR(20) NULL,
        gender ENUM('Male','Female') NULL,
        year_level VARCHAR(20) NOT NULL,
        program VARCHAR(100) NOT NULL,
        department VARCHAR(100) NOT NULL,
        email VARCHAR(100) NOT NULL,
        phone VARCHAR(30) NULL,
        password_hash VARCHAR(255) NOT NULL,
        status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
} catch (PDOException $e) {
}

// Ensure user_id column exists for older deployments
try {
    $pdo->exec("ALTER TABLE pending_registrations ADD COLUMN user_id INT NULL AFTER id");
} catch (PDOException $e) {
    // ignore if already exists
}

$errors = [];
$submitted = false;

$fieldErrors = [];

// Initialize form values for repopulation
$full_name = '';
$student_id = '';
$gender = '';
$year_level = '';
$program = '';
$department = '';
$email = '';
$phone = '';

// Restore QR target from query string if present (helps when session isn't preserved)
$qrFacultyId = isset($_GET['faculty_id']) ? (int)$_GET['faculty_id'] : 0;
$qrSubjectCode = isset($_GET['subject_code']) ? trim((string)($_GET['subject_code'] ?? '')) : '';
$qrSubjectName = isset($_GET['subject_name']) ? trim((string)($_GET['subject_name'] ?? '')) : '';

function buildQuickEvalRedirectUrl($target) {
    $url = 'student/quick_evaluate.php';
    if (!is_array($target) || empty($target)) {
        return $url;
    }
    $qs = http_build_query([
        'faculty_id' => (int)($target['faculty_id'] ?? 0),
        'subject_code' => (string)($target['subject_code'] ?? ''),
        'subject_name' => (string)($target['subject_name'] ?? ''),
    ]);
    if ($qs !== '') {
        $url .= '?' . $qs;
    }
    return $url;
}

if ($qrFacultyId > 0 && ($qrSubjectCode !== '' || $qrSubjectName !== '')) {
    $_SESSION['quick_eval_target'] = [
        'faculty_id'   => $qrFacultyId,
        'subject_code' => $qrSubjectCode,
        'subject_name' => $qrSubjectName,
    ];
}

// Normalize QR target for this request so the signup form can always persist it
$qrTarget = null;
if (!empty($_SESSION['quick_eval_target']) && is_array($_SESSION['quick_eval_target'])) {
    $qrTarget = $_SESSION['quick_eval_target'];
} elseif ($qrFacultyId > 0 && ($qrSubjectCode !== '' || $qrSubjectName !== '')) {
    $qrTarget = [
        'faculty_id'   => $qrFacultyId,
        'subject_code' => $qrSubjectCode,
        'subject_name' => $qrSubjectName,
    ];
}

$quickEvalRedirectUrl = buildQuickEvalRedirectUrl($qrTarget);

$isQrContext = (is_array($qrTarget) && !empty($qrTarget));

$redirectAfterSignup = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Debug: log that POST was received
    file_put_contents(__DIR__ . '/signup_debug.log', "[" . date('Y-m-d H:i:s') . "] POST RECEIVED - student_signup.php\n", FILE_APPEND);
    $full_name = sanitizeInput($_POST['full_name'] ?? '');
    $student_id = sanitizeInput($_POST['student_id'] ?? '');
    $gender = sanitizeInput($_POST['gender'] ?? '');
    $year_level = sanitizeInput($_POST['year_level'] ?? '');
    $program = sanitizeInput($_POST['program'] ?? '');
    $department = sanitizeInput($_POST['department'] ?? '');
    $email = sanitizeInput($_POST['email'] ?? '');
    $phone = sanitizeInput($_POST['phone'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    // Restore QR target from form post if session isn't preserved
    $qrCandidate = null;
    if (!empty($_SESSION['quick_eval_target']) && is_array($_SESSION['quick_eval_target'])) {
        $qrCandidate = $_SESSION['quick_eval_target'];
    } else {
        $pFacultyId = (int)($_POST['qr_faculty_id'] ?? 0);
        $pSubjectCode = trim((string)($_POST['qr_subject_code'] ?? ''));
        $pSubjectName = trim((string)($_POST['qr_subject_name'] ?? ''));
        if ($pFacultyId > 0 && ($pSubjectCode !== '' || $pSubjectName !== '')) {
            $qrCandidate = [
                'faculty_id'   => $pFacultyId,
                'subject_code' => $pSubjectCode,
                'subject_name' => $pSubjectName,
            ];
        } elseif ($qrFacultyId > 0 && ($qrSubjectCode !== '' || $qrSubjectName !== '')) {
            $qrCandidate = [
                'faculty_id'   => $qrFacultyId,
                'subject_code' => $qrSubjectCode,
                'subject_name' => $qrSubjectName,
            ];
        }
    }

    if (is_array($qrCandidate) && !empty($qrCandidate)) {
        $_SESSION['quick_eval_target'] = $qrCandidate;
    }

    $isQrSignup = (is_array($qrCandidate) && !empty($qrCandidate));
    $quickEvalRedirectUrl = buildQuickEvalRedirectUrl($qrCandidate);
    $redirectAfterSignup = $isQrSignup ? ($quickEvalRedirectUrl !== '' ? $quickEvalRedirectUrl : 'student/quick_evaluate.php') : '';
    // Debug: log QR signup detection
    file_put_contents(__DIR__ . '/signup_debug.log', "[" . date('Y-m-d H:i:s') . "] QR DETECTION: qrCandidate=" . json_encode($qrCandidate) . " isQrSignup=" . ($isQrSignup ? 'YES' : 'NO') . " redirectAfterSignup=" . $redirectAfterSignup . "\n", FILE_APPEND);

    if ($full_name === '') {
        $fieldErrors['full_name'] = 'Full Name is required.';
    }
    if ($year_level === '') {
        $fieldErrors['year_level'] = 'Year Level is required.';
    }
    if ($program === '') {
        $fieldErrors['program'] = 'Program is required.';
    }
    if ($department === '') {
        $fieldErrors['department'] = 'Department is required.';
    }
    if ($email === '') {
        $fieldErrors['email'] = 'Email is required.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $fieldErrors['email'] = 'Please enter a valid email address.';
    }
    if ($password === '') {
        $fieldErrors['password'] = 'Password is required.';
    } elseif (strlen($password) < 8) {
        $fieldErrors['password'] = 'Password must be at least 8 characters.';
    }
    if ($confirm_password === '') {
        $fieldErrors['confirm_password'] = 'Please confirm your password.';
    } elseif ($password !== '' && $password !== $confirm_password) {
        $fieldErrors['confirm_password'] = 'Passwords do not match.';
    }

    if (empty($fieldErrors)) {
        try {
            // For QR signup, allow existing emails and handle via auto-login logic later
            if (!$isQrSignup) {
                $emailCheck = $pdo->prepare("SELECT id FROM users WHERE email = ? OR username = ? LIMIT 1");
                $emailCheck->execute([$email, $email]);
                $emailExists = $emailCheck->fetch();

                $pendingEmailCheck = $pdo->prepare("SELECT id FROM pending_registrations WHERE email = ? AND status IN ('pending','approved') LIMIT 1");
                $pendingEmailCheck->execute([$email]);
                $pendingEmailExists = $pendingEmailCheck->fetch();

                if ($emailExists || $pendingEmailExists) {
                    $fieldErrors['email'] = 'This email is already in use. Please use another email or login instead.';
                }
            }

            if ($student_id !== '') {
                $usernameCheck = $pdo->prepare("SELECT id FROM users WHERE username = ? LIMIT 1");
                $usernameCheck->execute([$student_id]);
                $usernameExists = $usernameCheck->fetch();

                $pendingUsernameCheck = $pdo->prepare("SELECT id FROM pending_registrations WHERE student_id = ? AND status IN ('pending','approved') LIMIT 1");
                $pendingUsernameCheck->execute([$student_id]);
                $pendingUsernameExists = $pendingUsernameCheck->fetch();

                if ($usernameExists || $pendingUsernameExists) {
                    $fieldErrors['student_id'] = 'This Student ID is already taken. Please use another one.';
                }
            }
        } catch (PDOException $e) {
            $errors[] = 'Unable to validate your details at the moment. Please try again.';
        }
    }

    // Debug: log any validation errors
    if (!empty($errors) || !empty($fieldErrors)) {
        file_put_contents(__DIR__ . '/signup_debug.log', "[" . date('Y-m-d H:i:s') . "] VALIDATION ERRORS: errors=" . json_encode($errors) . " fieldErrors=" . json_encode($fieldErrors) . "\n", FILE_APPEND);
    }

    if (empty($errors) && empty($fieldErrors)) {
        file_put_contents(__DIR__ . '/signup_debug.log', "[" . date('Y-m-d H:i:s') . "] ERRORS EMPTY - proceeding to signup\n", FILE_APPEND);
        $password_hash = password_hash($password, PASSWORD_DEFAULT);
        try {
            if ($isQrSignup) {
                file_put_contents(__DIR__ . '/signup_debug.log', "[" . date('Y-m-d H:i:s') . "] QR SIGNUP BRANCH ENTERED\n", FILE_APPEND);
                // QR signup: create a real student account immediately, but keep it pending until admin approval
                try {
                    $pdo->exec("ALTER TABLE users ADD COLUMN account_status ENUM('active','pending','blocked') NOT NULL DEFAULT 'active'");
                } catch (PDOException $e2) {
                    // ignore if already exists
                }
                try {
                    $pdo->exec("ALTER TABLE students MODIFY student_id VARCHAR(20) NULL");
                } catch (PDOException $e2) {
                    // ignore if already nullable
                }

                // Use email as temporary username until admin assigns the real student_id
                $username = $email;
                $check = $pdo->prepare("SELECT id FROM users WHERE username = ? LIMIT 1");
                $check->execute([$username]);
                $existing = $check->fetch();
                if ($existing) {
                    file_put_contents(__DIR__ . '/signup_debug.log', "[" . date('Y-m-d H:i:s') . "] Existing user found with email username, checking password\n", FILE_APPEND);
                    // If the student already has a QR-created pending account, allow them to continue
                    // by logging them in (only if the password matches).
                    $uStmt = $pdo->prepare("SELECT u.id, u.username, u.password, u.role, u.full_name, u.department, s.id AS student_id
                                            FROM users u
                                            LEFT JOIN students s ON s.user_id = u.id
                                            WHERE u.id = ? AND u.role = 'student'
                                            LIMIT 1");
                    $uStmt->execute([(int)$existing['id']]);
                    $u = $uStmt->fetch();
                    if ($u && password_verify($password, (string)$u['password'])) {
                        // Debug log: auto-login success
                        file_put_contents(__DIR__ . '/signup_debug.log', "[" . date('Y-m-d H:i:s') . "] QR AUTO-LOGIN SUCCESS for user ID {$u['id']}\n", FILE_APPEND);
                        $_SESSION['user_id'] = (int)$u['id'];
                        $_SESSION['username'] = $u['username'];
                        $_SESSION['role'] = $u['role'];
                        $_SESSION['full_name'] = $u['full_name'];
                        $_SESSION['department'] = $u['department'];
                        $_SESSION['student_id'] = isset($u['student_id']) ? (int)$u['student_id'] : 0;

                        if (!headers_sent()) {
                            // Ensure session is written before redirect
                            session_write_close();
                            header('Location: ' . $quickEvalRedirectUrl);
                            exit;
                        }
                    }
                    file_put_contents(__DIR__ . '/signup_debug.log', "[" . date('Y-m-d H:i:s') . "] Password mismatch for existing user, redirecting to login\n", FILE_APPEND);
                    // Password mismatch: send the student to login so they can continue the QR evaluation
                    $_SESSION['login_error'] = 'This email is already registered. The password you entered is incorrect. Please login with your correct password or reset it.';
                    $_SESSION['login_prefill_username'] = $email;
                    if (!headers_sent()) {
                        session_write_close();
                        header('Location: index.php');
                        exit;
                    }
                    throw new Exception('An account with this email already exists. Please login instead.');
                }

                $pdo->beginTransaction();

                file_put_contents(__DIR__ . '/signup_debug.log', "[" . date('Y-m-d H:i:s') . "] Creating new user with email as username: $username\n", FILE_APPEND);
                $insUser = $pdo->prepare("INSERT INTO users (username, password, role, full_name, email, department, account_status) VALUES (?, ?, 'student', ?, ?, ?, 'pending')");
                $insUser->execute([$username, $password_hash, $full_name, $email, $department]);
                $user_id = (int)$pdo->lastInsertId();
                file_put_contents(__DIR__ . '/signup_debug.log', "[" . date('Y-m-d H:i:s') . "] User created with ID: $user_id\n", FILE_APPEND);

                // Ensure gender column exists in students (also used elsewhere)
                try {
                    $pdo->exec("ALTER TABLE students ADD COLUMN gender ENUM('Male','Female') NULL AFTER user_id");
                } catch (PDOException $e2) {
                    // ignore if already exists
                }

                $insStudent = $pdo->prepare("INSERT INTO students (user_id, student_id, year_level, program, gender) VALUES (?, NULL, ?, ?, ?)");
                $insStudent->execute([$user_id, $year_level, $program, $gender]);
                $student_table_id = (int)$pdo->lastInsertId();

                $stmt = $pdo->prepare("INSERT INTO pending_registrations
                    (user_id, role, full_name, student_id, gender, year_level, program, department, email, phone, password_hash)
                    VALUES (?, 'student', ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$user_id, $full_name, $student_id, $gender, $year_level, $program, $department, $email, $phone, $password_hash]);

                $pdo->commit();
                // Auto-login and continue to the QR evaluation that the student scanned
                try {
                    // Debug log: attempting auto-login after creating new user
                    file_put_contents(__DIR__ . '/signup_debug.log', "[" . date('Y-m-d H:i:s') . "] QR NEW USER CREATED ID $user_id - attempting auto-login\n", FILE_APPEND);
                    $sstmt = $pdo->prepare("SELECT u.id, u.username, u.role, u.full_name, u.department, s.id AS student_id
                                            FROM users u
                                            JOIN students s ON s.user_id = u.id
                                            WHERE u.id = ? AND u.role = 'student'
                                            LIMIT 1");
                    $sstmt->execute([$user_id]);
                    $u = $sstmt->fetch();
                    if ($u) {
                        $_SESSION['user_id'] = (int)$u['id'];
                        $_SESSION['username'] = $u['username'];
                        $_SESSION['role'] = $u['role'];
                        $_SESSION['full_name'] = $u['full_name'];
                        $_SESSION['department'] = $u['department'];
                        $_SESSION['student_id'] = (int)$u['student_id'];
                        file_put_contents(__DIR__ . '/signup_debug.log', "[" . date('Y-m-d H:i:s') . "] QR NEW USER AUTO-LOGIN SUCCESS: " . json_encode($_SESSION) . "\n", FILE_APPEND);
                    } else {
                        file_put_contents(__DIR__ . '/signup_debug.log', "[" . date('Y-m-d H:i:s') . "] QR NEW USER AUTO-LOGIN FAILED: user not found in join\n", FILE_APPEND);
                    }
                } catch (PDOException $e2) {
                    file_put_contents(__DIR__ . '/signup_debug.log', "[" . date('Y-m-d H:i:s') . "] QR NEW USER AUTO-LOGIN EXCEPTION: " . $e2->getMessage() . "\n", FILE_APPEND);
                    // If session setup fails, fall back to showing the success message below
                }

                // Final safety: ensure student_id is set in session so student dashboard can load
                if (empty($_SESSION['student_id']) && !empty($_SESSION['user_id']) && $student_table_id > 0) {
                    $_SESSION['student_id'] = $student_table_id;
                }

                if (!headers_sent()) {
                    $finalRedirect = ($redirectAfterSignup !== '' ? $redirectAfterSignup : $quickEvalRedirectUrl);
                    if ($finalRedirect === '') {
                        $finalRedirect = 'student/quick_evaluate.php';
                    }
                    session_write_close();
                    header('Location: ' . $finalRedirect);
                    exit;
                }

                $submitted = true;
            } else {
                $stmt = $pdo->prepare("INSERT INTO pending_registrations
                    (role, full_name, student_id, gender, year_level, program, department, email, phone, password_hash)
                    VALUES ('student', ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$full_name, $student_id, $gender, $year_level, $program, $department, $email, $phone, $password_hash]);
                $submitted = true;
            }
        } catch (PDOException $e) {
            error_log('STUDENT SIGNUP PDO ERROR: ' . $e->getMessage());

            $sqlState = (string)($e->getCode() ?? '');
            $driverCode = isset($e->errorInfo[1]) ? (int)$e->errorInfo[1] : 0;
            $driverMsg = isset($e->errorInfo[2]) ? (string)$e->errorInfo[2] : '';

            // Friendly handling for common MySQL duplicate-entry errors
            if ($sqlState === '23000' || $driverCode === 1062 || stripos($driverMsg, 'Duplicate entry') !== false) {
                // If duplicate happens during QR signup, it usually means the email(username) already exists.
                $fieldErrors['email'] = 'This email is already registered. Please login instead.';
                $errors[] = 'Account already exists. Please login using your email and password.';
            } else {
                $errors[] = 'Registration failed. Please double-check your details and try again. If this continues, contact the administrator.';
            }
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        } catch (Exception $e) {
            $errors[] = $e->getMessage();
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        }
    }
}
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Signup</title>
    <link rel="icon" href="img/loginlogo.png?v=2" type="image/png">
    <link rel="stylesheet" href="styles.css">
</head>
<body>
    <div class="container" id="login-container">
        <h1 class="app-title">Faculty Performance Evaluation System</h1>
        <div class="login-form" role="region" aria-labelledby="signup-title">
            <h2 id="signup-title" class="login-title">Student Signup</h2>
            <hr class="divider" aria-hidden="true" />
            <?php if ($submitted && empty($errors)): ?>
                <?php if ($redirectAfterSignup !== ''): ?>
                    <div class="success-message">Account created. Redirecting you to your evaluation...</div>
                    <div style="margin-top:0.75rem; font-size:0.95rem; text-align:center;">
                        <a href="<?php echo htmlspecialchars($redirectAfterSignup); ?>" class="btn-primary" style="display:inline-block; text-decoration:none; padding:0.6rem 1.25rem;">Continue</a>
                    </div>
                    <script>
                        setTimeout(function(){ window.location.href = <?php echo json_encode($redirectAfterSignup, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>; }, 400);
                    </script>
                <?php else: ?>
                    <div class="success-message">Your registration has been submitted and is awaiting admin approval.</div>
                <?php endif; ?>
            <?php else: ?>
                <?php
                // If a POST happened but no success and no errors, show a generic message
                if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($errors) && !$submitted && empty($fieldErrors)) {
                    $errors[] = 'Registration could not be completed. Please check your inputs or contact the administrator.';
                }
                if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$submitted && !empty($fieldErrors) && empty($errors)) {
                    $errors[] = 'Please fix the highlighted fields below.';
                }
                ?>
                <?php if (!empty($errors)): ?>
                    <div class="error-message" style="display: block;">
                        <?php foreach ($errors as $err): ?>
                            <div><?php echo htmlspecialchars($err); ?></div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
                <?php
                    $signupAction = 'student_signup.php';
                    if (is_array($qrTarget) && !empty($qrTarget)) {
                        $qs = http_build_query([
                            'faculty_id'   => (int)($qrTarget['faculty_id'] ?? 0),
                            'subject_code' => (string)($qrTarget['subject_code'] ?? ''),
                            'subject_name' => (string)($qrTarget['subject_name'] ?? ''),
                        ]);
                        if ($qs !== '') {
                            $signupAction .= '?' . $qs;
                        }
                    }
                ?>
                <form method="post" action="<?php echo htmlspecialchars($signupAction); ?>">
                    <?php if (is_array($qrTarget) && !empty($qrTarget)): ?>
                        <input type="hidden" name="qr_faculty_id" value="<?php echo htmlspecialchars((string)($qrTarget['faculty_id'] ?? '')); ?>">
                        <input type="hidden" name="qr_subject_code" value="<?php echo htmlspecialchars((string)($qrTarget['subject_code'] ?? '')); ?>">
                        <input type="hidden" name="qr_subject_name" value="<?php echo htmlspecialchars((string)($qrTarget['subject_name'] ?? '')); ?>">
                    <?php endif; ?>
                    <div class="form-group">
                        <label for="full_name">Full Name</label>
                        <input type="text" id="full_name" name="full_name" class="<?php echo !empty($fieldErrors['full_name']) ? 'input-error' : ''; ?>" value="<?php echo htmlspecialchars($full_name); ?>" required>
                        <?php if (!empty($fieldErrors['full_name'])): ?><div class="field-error"><?php echo htmlspecialchars($fieldErrors['full_name']); ?></div><?php endif; ?>
                    </div>
                    <div class="form-group">
                        <label for="student_id">Student ID (optional)</label>
                        <input type="text" id="student_id" name="student_id" class="<?php echo !empty($fieldErrors['student_id']) ? 'input-error' : ''; ?>" value="<?php echo htmlspecialchars($student_id); ?>">
                        <?php if (!empty($fieldErrors['student_id'])): ?><div class="field-error"><?php echo htmlspecialchars($fieldErrors['student_id']); ?></div><?php endif; ?>
                    </div>
                    <div class="form-group">
                        <label for="gender">Gender</label>
                        <select id="gender" name="gender">
                            <option value="">-- Select Gender (optional) --</option>
                            <option value="Male" <?php echo $gender === 'Male' ? 'selected' : ''; ?>>Male</option>
                            <option value="Female" <?php echo $gender === 'Female' ? 'selected' : ''; ?>>Female</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="year_level">Year Level</label>
                        <input type="text" id="year_level" name="year_level" class="<?php echo !empty($fieldErrors['year_level']) ? 'input-error' : ''; ?>" value="<?php echo htmlspecialchars($year_level); ?>" required>
                        <?php if (!empty($fieldErrors['year_level'])): ?><div class="field-error"><?php echo htmlspecialchars($fieldErrors['year_level']); ?></div><?php endif; ?>
                    </div>
                    <div class="form-group">
                        <label for="program">Program</label>
                        <select id="program" name="program" class="<?php echo !empty($fieldErrors['program']) ? 'input-error' : ''; ?>" required>
                            <option value="">-- Select Program --</option>
                            <?php
                            $deptKey = normalize_department_key($department);
                            $list = $PROGRAMS_BY_DEPT[$deptKey] ?? [];
                            foreach ($list as $p): ?>
                                <option value="<?php echo htmlspecialchars($p); ?>" <?php echo $program === $p ? 'selected' : ''; ?>><?php echo htmlspecialchars($p); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?php if (!empty($fieldErrors['program'])): ?><div class="field-error"><?php echo htmlspecialchars($fieldErrors['program']); ?></div><?php endif; ?>
                    </div>
                    <div class="form-group">
                        <label for="department">Department</label>
                        <select id="department" name="department" class="<?php echo !empty($fieldErrors['department']) ? 'input-error' : ''; ?>" required>
                            <option value="">-- Select Department --</option>
                            <?php foreach ($DEPT_LABELS as $key => $label): ?>
                                <option value="<?php echo htmlspecialchars($key); ?>" <?php echo $department === $key ? 'selected' : ''; ?>><?php echo htmlspecialchars($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?php if (!empty($fieldErrors['department'])): ?><div class="field-error"><?php echo htmlspecialchars($fieldErrors['department']); ?></div><?php endif; ?>
                    </div>
                    <div class="form-group">
                        <label for="email">Email</label>
                        <input type="email" id="email" name="email" class="<?php echo !empty($fieldErrors['email']) ? 'input-error' : ''; ?>" value="<?php echo htmlspecialchars($email); ?>" required>
                        <?php if (!empty($fieldErrors['email'])): ?><div class="field-error"><?php echo htmlspecialchars($fieldErrors['email']); ?></div><?php endif; ?>
                    </div>
                    <div class="form-group">
                        <label for="phone">Phone (optional)</label>
                        <input type="text" id="phone" name="phone" value="<?php echo htmlspecialchars($phone); ?>">
                    </div>
                    <div class="form-group">
                        <label for="password">Password</label>
                        <input type="password" id="password" name="password" class="<?php echo !empty($fieldErrors['password']) ? 'input-error' : ''; ?>" required>
                        <div class="field-hint">At least 8 characters.</div>
                        <?php if (!empty($fieldErrors['password'])): ?><div class="field-error"><?php echo htmlspecialchars($fieldErrors['password']); ?></div><?php endif; ?>
                    </div>
                    <div class="form-group">
                        <label for="confirm_password">Confirm Password</label>
                        <input type="password" id="confirm_password" name="confirm_password" class="<?php echo !empty($fieldErrors['confirm_password']) ? 'input-error' : ''; ?>" required>
                        <?php if (!empty($fieldErrors['confirm_password'])): ?><div class="field-error"><?php echo htmlspecialchars($fieldErrors['confirm_password']); ?></div><?php endif; ?>
                    </div>
                    <button type="submit" class="btn-primary btn-full">Submit Registration</button>
                </form>
            <?php endif; ?>
        </div>
    </div>
    <script>
      const PROGRAMS_BY_DEPT = <?php echo json_encode($PROGRAMS_BY_DEPT, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
      function updateProgramOptions() {
        const deptSel = document.getElementById('department');
        const progSel = document.getElementById('program');
        if (!deptSel || !progSel) return;
        const dept = deptSel.value || '';
        const list = PROGRAMS_BY_DEPT[dept] || [];
        const current = '<?php echo htmlspecialchars($program, ENT_QUOTES); ?>';
        progSel.innerHTML = '';
        const placeholder = document.createElement('option');
        placeholder.value = '';
        placeholder.textContent = '-- Select Program --';
        progSel.appendChild(placeholder);
        list.forEach(function(p) {
          const opt = document.createElement('option');
          opt.value = p;
          opt.textContent = p;
          if (p === current) opt.selected = true;
          progSel.appendChild(opt);
        });
      }
      document.addEventListener('DOMContentLoaded', function() {
        const deptSel = document.getElementById('department');
        if (deptSel) {
          deptSel.addEventListener('change', updateProgramOptions);
        }
        updateProgramOptions();
      });
    </script>
</body>
</html>
