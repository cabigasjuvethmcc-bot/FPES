<?php
require_once __DIR__ . '/../config.php';
requireRole('admin');

$role = strtolower($_GET['role'] ?? '');
$format = strtolower($_GET['format'] ?? 'csv');

// Determine admin scope
$deptAdminList = ['Business','Education','Technology'];
$adminDept = $_SESSION['department'] ?? '';
$isSystemAdmin = !in_array($adminDept, $deptAdminList, true);

// Base templates (System Admin defaults)
$templates = [
  // Updated headers with optional Password column
  // Note: EmployeeID is optional for Faculty/Dean; system will auto-generate if left blank
  'student' => ['FirstName','LastName','Gender','Course','YearLevel','Password'],
  'faculty' => ['FirstName','LastName','EmployeeID','Department','AssignedSubjects','Password'],
  'dean'    => ['FirstName','LastName','EmployeeID','Department','Password'],
];

// Department Admin locked templates
if (!$isSystemAdmin) {
  // Students: remove Course; program is auto-assigned by department
  $templates['student'] = ['FirstName','LastName','Gender','YearLevel','Password'];
  // Faculty: remove Department; department is auto-assigned
  $templates['faculty'] = ['FirstName','LastName','EmployeeID','AssignedSubjects','Password'];
}

if (!isset($templates[$role])) {
  http_response_code(400);
  echo 'Invalid role';
  exit;
}

// Only System Admins can get dean template
if ($role === 'dean' && !$isSystemAdmin) {
  http_response_code(403);
  echo 'Forbidden';
  exit;
}

if ($format !== 'csv') { $format = 'csv'; }
$filename = $role . '_template.' . $format;
header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename=' . $filename);

$out = fopen('php://output', 'w');
// Write instruction notes above headers (prefixed with # for clarity)
$notes = [
  'student' => [
    !$isSystemAdmin
      ? "Department-locked: All students will be assigned to the {$adminDept} department and program auto-set (SOE→EDUCATION, SOB→BUSINESS, SOT→TECHNOLOGY)."
      : "System Admin: Optionally set 'Apply Department' in the UI; program will map to that department.",
    "Password column is optional. If left blank, the system will assign the default password 'password123'.",
    !$isSystemAdmin
      ? "Columns: FirstName,LastName,Gender,YearLevel,Password (optional)."
      : "Columns: FirstName,LastName,Gender,Course,YearLevel,Password (optional)."
  ],
  'faculty' => [
    !$isSystemAdmin
      ? "Department-locked: All faculty will be assigned to the {$adminDept} department. CSV 'Department' column is not needed and will be ignored."
      : "Include Department column. System Admin can upload across departments.",
    "Password column is optional. If left blank, the system will assign the default password 'password123'.",
    !$isSystemAdmin
      ? "Columns: FirstName,LastName,EmployeeID (optional),AssignedSubjects (optional),Password (optional)."
      : "Columns: FirstName,LastName,EmployeeID (optional),Department,AssignedSubjects (optional),Password (optional).",
    "If EmployeeID is blank, the system will auto-generate one."
  ],
  'dean' => [
    "Password column is optional. If left blank, the system will assign the default password 'password123'.",
    "Columns: FirstName,LastName,EmployeeID (optional),Department,Password (optional). If EmployeeID is blank, the system will auto-generate one. If Password is blank, 'password123' will be used."
  ],
];
foreach (($notes[$role] ?? []) as $line) { fputcsv($out, ['# ' . $line]); }
// Blank line between notes and header
fputcsv($out, ['#']);

// Headers
fputcsv($out, $templates[$role]);

// Provide one sample row as a guide for formatting
$deptSample = $adminDept !== '' ? $adminDept : 'Technology';
$sample = [
  'student' => !$isSystemAdmin
    ? ['Juan','Dela Cruz','Male','1st Year','']
    : ['Juan','Dela Cruz','Male','BSIT','1st Year',''],
  'faculty' => !$isSystemAdmin
    ? ['Maria','Santos','FAC-001','CS101; CS102','']
    : ['Maria','Santos','FAC-001',$deptSample,'CS101; CS102',''],
  'dean'    => ['Jose','Rizal','DEAN-001',$deptSample,''],
];
if (isset($sample[$role])) { fputcsv($out, $sample[$role]); }
fclose($out);
exit;
