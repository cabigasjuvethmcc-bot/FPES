<?php
require_once '../config.php';
header('Content-Type: application/json');

// Support both /api/faculties.php?department=Technology and pretty path /api/faculties/Technology
$department = $_GET['department'] ?? '';
// If a dean is logged in, always scope to their department regardless of provided param
if (hasRole('dean')) {
    $department = $_SESSION['department'] ?? '';
}
if (!$department && isset($_SERVER['REQUEST_URI'])) {
    $uri = $_SERVER['REQUEST_URI']; // e.g., /FPES/api/faculties.php or /FPES/api/faculties/Technology
    $parts = explode('/', trim($uri, '/'));
    // Find segment after 'faculties'
    $idx = array_search('faculties', $parts);
    if ($idx !== false && isset($parts[$idx + 1])) {
        $department = urldecode($parts[$idx + 1]);
    }
}

$department = sanitizeInput($department);

if ($department === '') {
    echo json_encode(['success' => false, 'message' => 'department is required']);
    exit();
}

try {
    // Return faculty user_id, username, full_name for the department
    $stmt = $pdo->prepare("SELECT u.id AS user_id, u.username, u.full_name
                            FROM faculty f
                            JOIN users u ON f.user_id = u.id
                            WHERE u.department = ?
                            ORDER BY u.full_name");
    $stmt->execute([$department]);
    $rows = $stmt->fetchAll();

    echo json_encode(['success' => true, 'data' => $rows]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error']);
}
