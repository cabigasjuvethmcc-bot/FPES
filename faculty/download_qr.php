<?php
require_once '../config.php';
requireRole('faculty');

$facultyId = isset($_GET['faculty_id']) ? (int)$_GET['faculty_id'] : 0;
$code = isset($_GET['subject_code']) ? trim($_GET['subject_code']) : '';
$name = isset($_GET['subject_name']) ? trim($_GET['subject_name']) : '';

if ($facultyId <= 0 || ($code === '' && $name === '')) {
    http_response_code(400);
    exit('Invalid QR download request.');
}

// Build absolute base URL so QR works on mobile devices
$scheme = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$baseUrl = $scheme . '://' . $host;

// App root is the parent of this script directory (handles /FPES locally and / in production)
$scriptDir = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/\\');
$appRoot = rtrim(dirname($scriptDir), '/\\');
if ($appRoot === '.' || $appRoot === '') { $appRoot = ''; }

// Build the QR landing URL so students can login or sign up before evaluation
$targetUrl = $baseUrl . $appRoot . '/qr_landing.php?faculty_id=' . $facultyId . '&subject_code=' . urlencode($code) . '&subject_name=' . urlencode($name);
$qrApiUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=220x220&data=' . urlencode($targetUrl);

$qrImage = @file_get_contents($qrApiUrl);
if ($qrImage === false) {
    http_response_code(500);
    exit('Unable to generate QR code image.');
}

$baseName = $code !== '' ? $code : preg_replace('/[^a-zA-Z0-9]+/', '_', strtolower($name));
if ($baseName === '') {
    $baseName = 'qr_code';
}
$filename = 'qr_' . $baseName . '.png';

header('Content-Type: image/png');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . strlen($qrImage));

echo $qrImage;
exit;
