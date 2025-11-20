<?php
require_once '../config.php';
header('Content-Type: application/json');

// Normalize department acronyms and labels
$deptMap = [
    'SOT' => 'Technology',
    'SOB' => 'Business',
    'SOE' => 'Education',
    'School of Technology' => 'Technology',
    'School of Business' => 'Business',
    'School of Education' => 'Education',
];

$department = $_GET['department'] ?? '';
// If a dean is logged in, always scope to their department regardless of provided param
if (hasRole('dean')) {
    $department = $_SESSION['department'] ?? '';
}
if (!$department && isset($_SERVER['REQUEST_URI'])) {
    $uri = $_SERVER['REQUEST_URI'];
    $parts = explode('/', trim($uri, '/'));
    $idx = array_search('subjects', $parts);
    if ($idx !== false && isset($parts[$idx + 1])) {
        $department = urldecode($parts[$idx + 1]);
    }
}

$department = sanitizeInput($department);
if ($department === '') {
    echo json_encode(['success' => false, 'message' => 'department is required']);
    exit();
}

if (isset($deptMap[$department])) {
    $department = $deptMap[$department];
}

try {
    // Ensure subjects table exists
    $pdo->exec("CREATE TABLE IF NOT EXISTS subjects (
        id INT AUTO_INCREMENT PRIMARY KEY,
        department VARCHAR(100) NOT NULL,
        code VARCHAR(50) NOT NULL,
        name VARCHAR(255) NOT NULL,
        UNIQUE KEY unique_subject (department, code)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    // If table is empty for this department, seed with default subjects
    $check = $pdo->prepare("SELECT COUNT(*) FROM subjects WHERE department = ?");
    $check->execute([$department]);
    $count = (int)$check->fetchColumn();
    if ($count === 0) {
        // Default seeds per department
        $seed = [
            'Technology' => [
                ['IT101', 'Introduction to Information Technology'],
                ['CS201', 'Data Structures and Algorithms'],
                ['SE210', 'Software Engineering Principles'],
                ['NET220', 'Computer Networks'],
                ['SEC230', 'Cybersecurity Fundamentals'],
                ['DS240', 'Data Science Basics'],
                ['WD250', 'Web Development'],
            ],
            'Business' => [
                ['BA101', 'Principles of Management'],
                ['ACC110', 'Financial Accounting'],
                ['MKT120', 'Marketing Management'],
                ['FIN130', 'Corporate Finance'],
                ['HR140', 'Human Resource Management'],
                ['ENT150', 'Entrepreneurship'],
                ['IB160', 'International Business'],
            ],
            'Education' => [
                ['EDU101', 'Foundations of Education'],
                ['EDU120', 'Curriculum Development'],
                ['EDU130', 'Educational Technology'],
                ['PED140', 'Pedagogy and Instructional Strategies'],
                ['ASM150', 'Assessment and Evaluation'],
                ['SPED160', 'Special and Inclusive Education'],
            ],
        ];
        if (isset($seed[$department])) {
            $ins = $pdo->prepare("INSERT INTO subjects (department, code, name) VALUES (?, ?, ?)");
            foreach ($seed[$department] as $row) {
                $ins->execute([$department, $row[0], $row[1]]);
            }
        }
    }

    // Fetch subjects for department
    $stmt = $pdo->prepare("SELECT code, name FROM subjects WHERE department = ? ORDER BY name");
    $stmt->execute([$department]);
    $rows = $stmt->fetchAll();

    echo json_encode(['success' => true, 'data' => $rows]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error']);
}
