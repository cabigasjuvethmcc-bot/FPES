<?php
require_once '../config.php';
requireRole('admin');

header('Content-Type: application/json');

$action = $_POST['action'] ?? $_GET['action'] ?? '';

try {
    if ($action === 'get') {
        $sch = getEvaluationSchedule($pdo);
        echo json_encode(['success' => true, 'data' => $sch]);
        exit;
    }

    // All mutating actions require CSRF
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        throw new Exception('Invalid security token');
    }

    switch ($action) {
        case 'save_schedule': {
            $start = trim($_POST['start_at'] ?? '');
            $end = trim($_POST['end_at'] ?? '');
            $notice = trim($_POST['notice'] ?? '');
            // Normalize empty -> NULL
            $startAt = $start !== '' ? (new DateTime($start))->format('Y-m-d H:i:s') : null;
            $endAt = $end !== '' ? (new DateTime($end))->format('Y-m-d H:i:s') : null;
            // Validate window if both provided
            if ($startAt && $endAt) {
                if (new DateTime($startAt) >= new DateTime($endAt)) {
                    throw new Exception('Start date/time must be before End date/time.');
                }
            }
            saveEvaluationSchedule($pdo, $startAt, $endAt, $notice !== '' ? $notice : null);
            echo json_encode(['success' => true, 'message' => 'Evaluation schedule saved successfully!']);
            break;
        }
        case 'open_now': {
            setEvaluationOverride($pdo, 'open');
            echo json_encode(['success' => true, 'message' => 'Evaluations opened (override).']);
            break;
        }
        case 'close_now': {
            setEvaluationOverride($pdo, 'closed');
            echo json_encode(['success' => true, 'message' => 'Evaluations closed (override).']);
            break;
        }
        case 'set_auto': {
            setEvaluationOverride($pdo, 'auto');
            echo json_encode(['success' => true, 'message' => 'Evaluation schedule is now active.']);
            break;
        }
        default:
            throw new Exception('Unknown action');
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
