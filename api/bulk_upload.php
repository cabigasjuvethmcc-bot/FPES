<?php
require_once __DIR__ . '/../config.php';
requireRole('admin');

header('Content-Type: application/json');

// Helpers
function json_fail($message) {
  echo json_encode(['success' => false, 'message' => $message]);
  exit;
}

function normalize_header($h) { return strtolower(trim($h)); }

// Password policy: at least 8 chars, include letters and numbers
function is_password_valid($pw) {
  if (strlen($pw) < 8) return false;
  if (!preg_match('/[A-Za-z]/', $pw)) return false;
  if (!preg_match('/\d/', $pw)) return false;
  return true;
}

function generate_temp_password($length = 10) {
  $letters = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
  $numbers = '0123456789';
  $all = $letters . $numbers;
  $pw = '';
  // ensure at least one letter and one number
  $pw .= $letters[random_int(0, strlen($letters)-1)];
  $pw .= $numbers[random_int(0, strlen($numbers)-1)];
  for ($i = 2; $i < $length; $i++) {
    $pw .= $all[random_int(0, strlen($all)-1)];
  }
  // shuffle
  return str_shuffle($pw);
}

function read_csv_rows($tmpPath) {
  $rows = [];
  if (($handle = fopen($tmpPath, 'r')) !== false) {
    $headers = null;
    // Find the header row: skip notes and blanks; look for a row containing 'firstname'
    while (($line = fgetcsv($handle)) !== false) {
      if ($line === null) { continue; }
      // Trim values
      $trimmed = array_map(function($v){ return strtolower(trim((string)$v)); }, $line);
      // Skip comment/note lines that start with '#'
      if (isset($trimmed[0]) && strlen($trimmed[0]) > 0 && $trimmed[0][0] === '#') { continue; }
      // Skip blank-only lines
      $allBlank = true; foreach ($trimmed as $t) { if ($t !== '') { $allBlank = false; break; } }
      if ($allBlank) { continue; }
      // Header candidate
      if (in_array('firstname', $trimmed, true)) {
        $headers = array_map('normalize_header', $line);
        break;
      }
      // If not a header, continue scanning
    }
    if ($headers === null) { fclose($handle); return [[], []]; }
    // Read data rows
    while (($data = fgetcsv($handle)) !== false) {
      $row = [];
      foreach ($headers as $i => $key) { $row[$key] = $data[$i] ?? null; }
      $rows[] = $row;
    }
    fclose($handle);
    return [$headers, $rows];
  }
  return [[], []];
}

// Very basic XLSX reader for first worksheet (best effort)
function read_xlsx_rows($tmpPath) {
  if (!class_exists('ZipArchive')) { return [[], []]; }
  $zip = new ZipArchive();
  if ($zip->open($tmpPath) !== true) { return [[], []]; }
  $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
  $sharedStringsXml = $zip->getFromName('xl/sharedStrings.xml');
  $shared = [];
  if ($sharedStringsXml) {
    $sx = simplexml_load_string($sharedStringsXml);
    foreach ($sx->si as $si) {
      // concatenate t nodes within si
      $texts = [];
      foreach ($si->t as $t) { $texts[] = (string)$t; }
      if (empty($texts) && isset($si->r)) {
        foreach ($si->r as $r) { $texts[] = (string)$r->t; }
      }
      $shared[] = implode('', $texts);
    }
  }
  if (!$sheetXml) { $zip->close(); return [[], []]; }
  $sx = simplexml_load_string($sheetXml);
  $sx->registerXPathNamespace('a', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
  $rows = [];
  foreach ($sx->sheetData->row as $r) {
    $row = [];
    foreach ($r->c as $c) {
      $t = (string)$c['t'];
      $v = isset($c->v) ? (string)$c->v : '';
      if ($t === 's') { // shared string
        $idx = is_numeric($v) ? intval($v) : -1;
        $row[] = $idx >= 0 && isset($shared[$idx]) ? $shared[$idx] : '';
      } else {
        $row[] = $v;
      }
    }
    $rows[] = $row;
  }
  $zip->close();
  if (empty($rows)) { return [[], []]; }
  // First row headers
  $headers = array_map('normalize_header', $rows[0]);
  $assocRows = [];
  for ($i = 1; $i < count($rows); $i++) {
    $assoc = [];
    foreach ($headers as $idx => $key) { $assoc[$key] = $rows[$i][$idx] ?? null; }
    $assocRows[] = $assoc;
  }
  return [$headers, $assocRows];
}

// ID generation helpers for Faculty/Deans
function extract_numeric_from_id($id, $prefix) {
  $pattern = '/^' . preg_quote($prefix, '/') . '0*(\d+)$/i';
  if (preg_match($pattern, $id, $m)) { return (int)$m[1]; }
  return null;
}

function collect_used_numbers(PDO $pdo, $role) {
  // Determine source table and prefix (hyphenated)
  $prefix = ($role === 'faculty') ? 'FAC-' : 'DEAN-';
  $table  = ($role === 'faculty') ? 'faculty' : 'deans';
  $nums = [];
  // From role table
  try {
    $stmt = $pdo->query("SELECT employee_id FROM $table");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
      $n = extract_numeric_from_id((string)$row['employee_id'], $prefix);
      if ($n !== null) { $nums[$n] = true; }
    }
  } catch (PDOException $e) { /* ignore if table missing; created later */ }
  // From users.username as well (since username == employee_id for these roles)
  try {
    $stmt = $pdo->prepare("SELECT username FROM users WHERE role = ? AND username LIKE ?");
    $stmt->execute([$role, $prefix.'%']);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
      $n = extract_numeric_from_id((string)$row['username'], $prefix);
      if ($n !== null) { $nums[$n] = true; }
    }
  } catch (PDOException $e) { /* ignore */ }
  // Return sorted unique numeric keys
  $keys = array_keys($nums);
  sort($keys, SORT_NUMERIC);
  return $keys;
}

function allocate_next_id(PDO $pdo, $role) {
  $prefix = ($role === 'faculty') ? 'FAC-' : 'DEAN-';
  $pad = 3;
  $used = collect_used_numbers($pdo, $role);
  // Find smallest missing positive integer
  $candidate = 1;
  $i = 0; $len = count($used);
  while ($i < $len) {
    if ($used[$i] === $candidate) { $candidate++; $i++; }
    elseif ($used[$i] < $candidate) { $i++; }
    else { break; } // found a gap at $candidate
  }
  // Compose ID
  $id = sprintf('%s%0'.(int)$pad.'d', $prefix, $candidate);
  return [$id, $candidate, $prefix, $pad];
}

function format_id_ranges(array $numbers, $prefix, $pad = 3) {
  if (empty($numbers)) return '';
  sort($numbers, SORT_NUMERIC);
  $ranges = [];
  $start = $numbers[0];
  $prev = $start;
  for ($i = 1; $i < count($numbers); $i++) {
    $n = $numbers[$i];
    if ($n === $prev + 1) { $prev = $n; continue; }
    // close range
    if ($start === $prev) { $ranges[] = sprintf('%s%0'.(int)$pad.'d', $prefix, $start); }
    else { $ranges[] = sprintf('%s%0'.(int)$pad.'d–%s%0'.(int)$pad.'d', $prefix, $start, $prefix, $prev); }
    $start = $prev = $n;
  }
  // last range
  if ($start === $prev) { $ranges[] = sprintf('%s%0'.(int)$pad.'d', $prefix, $start); }
  else { $ranges[] = sprintf('%s%0'.(int)$pad.'d–%s%0'.(int)$pad.'d', $prefix, $start, $prefix, $prev); }
  return implode(', ', $ranges);
}

try {
  if ($_SERVER['REQUEST_METHOD'] !== 'POST') { json_fail('Invalid method'); }
  if (!validateCSRFToken($_POST['csrf_token'] ?? '')) { json_fail('Invalid CSRF token'); }

  $role = strtolower(trim($_POST['role'] ?? ''));
  if (!in_array($role, ['student','faculty','dean'], true)) { json_fail('Invalid role'); }

  // Determine admin scope
  $deptAdminList = ['Business','Education','Technology'];
  $adminDept = $_SESSION['department'] ?? '';
  $isSystemAdmin = !in_array($adminDept, $deptAdminList, true);
  if (!$isSystemAdmin && $role === 'dean') { json_fail('Department Admins cannot upload Deans'); }

  $applyDept = '';
  if ($isSystemAdmin) {
    $applyDept = trim($_POST['department'] ?? '');
    if ($role === 'student' && $applyDept === '') {
      // optional; students may also include department in file, but template doesn't have it
      // keep empty and will fallback per-row if provided (ignored for student)
    }
  } else {
    $applyDept = $adminDept; // Enforce department for dept admins
  }

  if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) { json_fail('No file uploaded'); }
  $file = $_FILES['file'];
  $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
  $tmpPath = $file['tmp_name'];

  // Parse
  if ($ext === 'csv') {
    list($headers, $rows) = read_csv_rows($tmpPath);
  } elseif ($ext === 'xlsx') {
    list($headers, $rows) = read_xlsx_rows($tmpPath);
  } else {
    json_fail('Unsupported file type. Use .csv or .xlsx');
  }
  if (empty($headers)) { json_fail('File appears empty or unreadable'); }

  // Ensure role-specific required columns (dynamic by admin scope)
  // Headers normalized to lowercase by parser
  $required = [];
  if ($role === 'student') {
    // For students, we no longer require 'course' because program is auto-set from department context
    $required = ['firstname','lastname','gender','yearlevel'];
  } elseif ($role === 'faculty' || $role === 'dean') {
    // System Admin must provide department; Department Admin's department is auto-assigned
    $required = $isSystemAdmin ? ['firstname','lastname','department'] : ['firstname','lastname'];
  }
  $req = $required;
  $missing = array_diff($req, $headers);
  if (!empty($missing)) { json_fail('Missing required columns: ' . implode(', ', $missing)); }

  // Ensure role tables exist (best effort)
  try {
    $GLOBALS['pdo']->exec("CREATE TABLE IF NOT EXISTS deans ( id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, employee_id VARCHAR(20) UNIQUE, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    // Ensure must_change_password exists on users
    $GLOBALS['pdo']->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS must_change_password TINYINT(1) NOT NULL DEFAULT 0");
    // Shared ID sequence tracker (keeps last allocated number). Note: we still fill gaps by scanning.
    $GLOBALS['pdo']->exec("CREATE TABLE IF NOT EXISTS id_sequences (
      role ENUM('faculty','dean') PRIMARY KEY,
      last_num INT NOT NULL DEFAULT 0,
      updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
  } catch (PDOException $e) { /* ignore */ }

  $pdo->beginTransaction();
  $ok = 0; $skipped = 0; $errors = [];
  $providedPwCount = 0; $generatedPwCount = 0;
  $credentials = []; // [username, role, full_name, department, initial_password, source]
  $summaryData = [
    'student' => ['male' => 0, 'female' => 0, 'male_first' => null, 'male_last' => null, 'female_first' => null, 'female_last' => null],
    'faculty' => ['count' => 0, 'nums' => []],
    'dean' => ['count' => 0, 'nums' => []],
  ];

  // Helpers for student ID generation by gender
  $formatStudentId = function($prefix, $num) {
    return sprintf('%s-%03d', $prefix, (int)$num);
  };
  $nextStudentNumber = function($gender) use ($pdo) {
    $prefix = (strcasecmp($gender, 'male') === 0) ? '222' : '221';
    $stmt = $pdo->prepare("SELECT MAX(CAST(SUBSTRING(student_id, 5) AS UNSIGNED)) AS maxn FROM students WHERE student_id LIKE ?");
    $stmt->execute([$prefix . '-%']);
    $row = $stmt->fetch();
    $max = (int)($row['maxn'] ?? 0);
    return [$prefix, $max + 1];
  };

  foreach ($rows as $index => $row) {
    // Trim all values
    foreach ($row as $k => $v) { $row[$k] = trim((string)$v); }

    // Row-level validation
    $reason = '';
    foreach ($req as $col) { if ($col !== 'password' && empty($row[$col])) { $reason = 'Missing field: ' . $col; break; } }

    // Determine department
    // For Department Admins: force to their department regardless of CSV content
    // For System Admins: allow per-row Department (for faculty/dean), students may have empty and rely on applyDept
    if ($isSystemAdmin) {
      if ($role === 'faculty' || $role === 'dean') {
        $rowDept = trim($row['department'] ?? $applyDept);
      } else { // student
        $rowDept = $applyDept; // may be empty; will still map program by department if provided
      }
    } else {
      $rowDept = $adminDept;
    }

    // If system admin processing faculty/dean, ensure department is present
    if ($isSystemAdmin && ($role === 'faculty' || $role === 'dean')) {
      if ($rowDept === '') { $reason = 'Missing field: department'; }
    }

    if ($reason !== '') { $skipped++; $errors[] = [$index+2, $reason]; continue; }

    try {
      // Prepare role-specific IDs and username rules
      $fullName = trim(($row['firstname'] ?? '') . ' ' . ($row['lastname'] ?? ''));
      $emailVal = $row['email'] ?? null; // optional/unused in new templates
      $passwordPlain = trim($row['password'] ?? '');
      $pwSource = 'generated';
      if ($passwordPlain !== '') {
        // Validate provided password
        if (!is_password_valid($passwordPlain)) {
          throw new Exception('Password does not meet policy (min 8 chars, include letters and numbers)');
        }
        $pwSource = 'provided';
      } else {
        // Default password when none provided
        $passwordPlain = 'password123';
      }
      $mustChange = 1; // require change on first login for all bulk-created accounts

      if ($role === 'student') {
        $gender = strtolower($row['gender'] ?? '');
        if (!in_array($gender, ['male','female'], true)) { throw new Exception('Invalid gender (must be Male or Female)'); }
        list($prefix, $next) = $nextStudentNumber($gender);
        $studentId = $formatStudentId($prefix, $next);

        // Uniqueness checks
        $stmt = $pdo->prepare('SELECT COUNT(*) c FROM students WHERE student_id = ?');
        $stmt->execute([$studentId]);
        if ($stmt->fetch()['c'] > 0) { throw new Exception('Duplicate generated student_id'); }

        $username = $studentId; // username equals Student ID
        $stmt = $pdo->prepare('SELECT COUNT(*) c FROM users WHERE username = ?');
        $stmt->execute([$username]);
        if ($stmt->fetch()['c'] > 0) { throw new Exception('Duplicate username'); }

        $hash = password_hash($passwordPlain, PASSWORD_BCRYPT);
        $insertUser = $pdo->prepare('INSERT INTO users (username, password, role, full_name, email, department, must_change_password) VALUES (?,?,?,?,?,?,?)');
        $insertUser->execute([$username, $hash, $role, $fullName, $emailVal, $rowDept, $mustChange]);
        $userId = (int)$pdo->lastInsertId();
        // Auto-assign program by department context, ignoring CSV content
        $deptToProgram = [
          'Education' => 'EDUCATION',
          'Business' => 'BUSINESS',
          'Technology' => 'TECHNOLOGY',
        ];
        $program = $deptToProgram[$rowDept] ?? ($deptToProgram[ucfirst(strtolower($rowDept))] ?? '');
        if ($program === '') {
          // If department not recognized or empty, require it (system admin should set Apply Department)
          if ($isSystemAdmin) { throw new Exception('Department not specified for student (set Apply Department)'); }
          // For dept admins, this should not happen since $rowDept is forced
        }
        $yr = $row['yearlevel'];
        $stmt = $pdo->prepare('INSERT INTO students (user_id, student_id, year_level, program) VALUES (?,?,?,?)');
        $stmt->execute([$userId, $studentId, $yr, $program]);

        // Update summary
        if (strcasecmp($gender,'male')===0) {
          $summaryData['student']['male']++;
          $summaryData['student']['male_first'] = $summaryData['student']['male_first'] ?: $studentId;
          $summaryData['student']['male_last'] = $studentId;
        } else {
          $summaryData['student']['female']++;
          $summaryData['student']['female_first'] = $summaryData['student']['female_first'] ?: $studentId;
          $summaryData['student']['female_last'] = $studentId;
        }
      } elseif ($role === 'faculty' || $role === 'dean') {
        // Accept optional EmployeeID; otherwise auto-generate next available (fill gaps first)
        $providedId = trim($row['employeeid'] ?? '');
        if ($providedId !== '') {
          // Basic format normalization: allow e.g., FAC-001 or DEAN-001 or raw
          $prefix = ($role === 'faculty') ? 'FAC-' : 'DEAN-';
          // If it doesn't start with prefix, prepend
          if (stripos($providedId, $prefix) !== 0) {
            // extract numeric part
            if (preg_match('/^(\d+)$/', $providedId)) {
              $providedId = $prefix . str_pad($providedId, 3, '0', STR_PAD_LEFT);
            } else {
              // Try to extract number part
              if (preg_match('/(\d+)/', $providedId, $m)) {
                $providedId = $prefix . str_pad($m[1], 3, '0', STR_PAD_LEFT);
              } else {
                throw new Exception('Invalid EmployeeID format');
              }
            }
          }
          $genId = strtoupper($providedId);
          // Ensure uniqueness
          $stmt = $pdo->prepare('SELECT COUNT(*) c FROM users WHERE username = ?');
          $stmt->execute([$genId]);
          if (($stmt->fetch()['c'] ?? 0) > 0) { throw new Exception('Duplicate EmployeeID/username'); }
          if ($role === 'faculty') {
            $stmt = $pdo->prepare('SELECT COUNT(*) c FROM faculty WHERE employee_id = ?');
          } else {
            $stmt = $pdo->prepare('SELECT COUNT(*) c FROM deans WHERE employee_id = ?');
          }
          $stmt->execute([$genId]);
          if (($stmt->fetch()['c'] ?? 0) > 0) { throw new Exception('Duplicate EmployeeID'); }
          // Try to parse numeric part for summary range
          $num = extract_numeric_from_id($genId, ($role === 'faculty') ? 'FAC-' : 'DEAN-') ?? null;
          $prefix = ($role === 'faculty') ? 'FAC-' : 'DEAN-';
          $pad = 3;
        } else {
          // Auto-generate
          list($genId, $num, $prefix, $pad) = allocate_next_id($pdo, $role);
          // Ensure uniqueness at time of allocation; if collision, iterate forward
          while (true) {
            // Check users
            $stmt = $pdo->prepare('SELECT COUNT(*) c FROM users WHERE username = ?');
            $stmt->execute([$genId]);
            $userDup = ($stmt->fetch()['c'] ?? 0) > 0;
            // Check role table
            if ($role === 'faculty') {
              $stmt = $pdo->prepare('SELECT COUNT(*) c FROM faculty WHERE employee_id = ?');
            } else {
              $stmt = $pdo->prepare('SELECT COUNT(*) c FROM deans WHERE employee_id = ?');
            }
            $stmt->execute([$genId]);
            $roleDup = ($stmt->fetch()['c'] ?? 0) > 0;
            if (!$userDup && !$roleDup) { break; }
            // advance to next number
            $num++;
            $genId = sprintf('%s%0'.(int)$pad.'d', $prefix, $num);
          }
        }

        // Ensure uniqueness at time of allocation; if collision, iterate forward
        $username = $genId;

        $hash = password_hash($passwordPlain, PASSWORD_BCRYPT);
        $insertUser = $pdo->prepare('INSERT INTO users (username, password, role, full_name, email, department, must_change_password) VALUES (?,?,?,?,?,?,?)');
        $insertUser->execute([$username, $hash, $role, $fullName, $emailVal, $rowDept, $mustChange]);
        $userId = (int)$pdo->lastInsertId();

        if ($role === 'faculty') {
          $stmt = $pdo->prepare('INSERT INTO faculty (user_id, employee_id) VALUES (?,?)');
          $stmt->execute([$userId, $genId]);
          // Update shared sequence tracker
          try {
            $pdo->prepare("INSERT INTO id_sequences (role, last_num) VALUES ('faculty', ?) ON DUPLICATE KEY UPDATE last_num = GREATEST(last_num, VALUES(last_num))")
                ->execute([$num]);
          } catch (PDOException $e) { /* non-fatal */ }
          $summaryData['faculty']['count']++;
          $summaryData['faculty']['nums'][] = $num;
        } else {
          $stmt = $pdo->prepare('INSERT INTO deans (user_id, employee_id) VALUES (?,?)');
          $stmt->execute([$userId, $genId]);
          // Update shared sequence tracker
          try {
            $pdo->prepare("INSERT INTO id_sequences (role, last_num) VALUES ('dean', ?) ON DUPLICATE KEY UPDATE last_num = GREATEST(last_num, VALUES(last_num))")
                ->execute([$num]);
          } catch (PDOException $e) { /* non-fatal */ }
          $summaryData['dean']['count']++;
          $summaryData['dean']['nums'][] = $num;
        }
      }

      // Track password counters and credentials listing
      if ($pwSource === 'provided') { $providedPwCount++; } else { $generatedPwCount++; }
      $credentials[] = [$username, $role, $fullName, $rowDept, $passwordPlain, $pwSource];

      $ok++;
    } catch (Exception $ex) {
      $skipped++;
      $errors[] = [$index+2, $ex->getMessage()];
      continue;
    }
  }

  $pdo->commit();

  // Error report file if any
  $errorReportPath = '';
  if ($skipped > 0) {
    $dir = __DIR__ . '/../reports';
    if (!is_dir($dir)) { @mkdir($dir, 0777, true); }
    $fname = 'bulk_errors_' . date('Ymd_His') . '.csv';
    $full = $dir . '/' . $fname;
    $fp = fopen($full, 'w');
    fputcsv($fp, ['row_number','reason']);
    foreach ($errors as $er) { fputcsv($fp, $er); }
    fclose($fp);
    // Build a web path like '/FPES/reports/<file>' to avoid incorrect '../' resolution
    $apiWebDir = rtrim(str_replace('\\','/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/'); // e.g., /FPES/api
    $baseWeb = rtrim(str_replace('\\','/', dirname($apiWebDir)), '/'); // -> /FPES
    if ($baseWeb === '' || $baseWeb === '.') { $baseWeb = '/'; }
    $errorReportPath = $baseWeb . '/reports/' . $fname;
  }

  // Credentials report for admins (sensitive)
  $credentialsReportPath = '';
  if ($ok > 0 && !empty($credentials)) {
    $dir = __DIR__ . '/../reports';
    if (!is_dir($dir)) { @mkdir($dir, 0777, true); }
    $fname = 'bulk_credentials_' . date('Ymd_His') . '.csv';
    $full = $dir . '/' . $fname;
    $fp = fopen($full, 'w');
    fputcsv($fp, ['username','role','full_name','department','initial_password','source']);
    foreach ($credentials as $row) { fputcsv($fp, $row); }
    fclose($fp);
    $apiWebDir = rtrim(str_replace('\\','/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
    $baseWeb = rtrim(str_replace('\\','/', dirname($apiWebDir)), '/');
    if ($baseWeb === '' || $baseWeb === '.') { $baseWeb = '/'; }
    $credentialsReportPath = $baseWeb . '/reports/' . $fname;
  }

  // Build detailed summary
  $summary = sprintf('%d records uploaded successfully, %d errors found', $ok, $skipped);
  if ($ok > 0) {
    $summary .= sprintf(' — Passwords: %d provided, %d generated', $providedPwCount, $generatedPwCount);
  }
  if ($role === 'student') {
    $m = $summaryData['student']['male'];
    $f = $summaryData['student']['female'];
    if ($m > 0 || $f > 0) {
      $details = [];
      if ($m > 0) { $details[] = sprintf('%d Male: IDs %s to %s', $m, $summaryData['student']['male_first'] ?? '-', $summaryData['student']['male_last'] ?? '-'); }
      if ($f > 0) { $details[] = sprintf('%d Female: IDs %s to %s', $f, $summaryData['student']['female_first'] ?? '-', $summaryData['student']['female_last'] ?? '-'); }
      $summary .= ' — ' . implode(', ', $details);
    }
  } elseif ($role === 'faculty') {
    $ranges = format_id_ranges($summaryData['faculty']['nums'], 'FAC-', 3);
    if ($summaryData['faculty']['count'] > 0) {
      $summary .= sprintf(' — %d Faculty added (IDs %s)', $summaryData['faculty']['count'], $ranges);
    } else {
      $summary .= ' — 0 Faculty added';
    }
  } elseif ($role === 'dean') {
    $ranges = format_id_ranges($summaryData['dean']['nums'], 'DEAN-', 3);
    if ($summaryData['dean']['count'] > 0) {
      $summary .= sprintf(' — %d Deans added (IDs %s)', $summaryData['dean']['count'], $ranges);
    } else {
      $summary .= ' — 0 Deans added';
    }
  }
  echo json_encode([
    'success' => true,
    'summary' => $summary,
    'error_report' => $errorReportPath,
    'credentials_report' => $credentialsReportPath,
    'provided_passwords' => $providedPwCount,
    'generated_passwords' => $generatedPwCount,
  ]);
  exit;
} catch (Exception $e) {
  if ($pdo->inTransaction()) { $pdo->rollBack(); }
  json_fail('Server error');
}
