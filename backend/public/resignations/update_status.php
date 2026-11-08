<?php

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json(['ok' => false, 'message' => 'Method not allowed'], 405);
}

$user = require_explicit_page_permission('Resignation', 'edit');
$input = read_json_input();

$resignationId = clean_i($input, 'resignationId');
$statusLabel = clean_s($input, 'statusLabel', 30);
$remarksText = clean_s($input, 'remarksText', 4000);

if ($resignationId <= 0 || $statusLabel === '') {
    send_json([
        'ok' => false,
        'message' => 'resignationId and statusLabel are required',
    ], 422);
}

if (!in_array($statusLabel, ['Submitted', 'Approved', 'Rejected', 'Relieved', 'Cancelled'], true)) {
    send_json(['ok' => false, 'message' => 'Invalid statusLabel'], 422);
}

try {
    $pdo = db();
    ensure_resignation_schema($pdo);

    $pdo->beginTransaction();

    $stmt = $pdo->prepare('SELECT id, employee_id FROM hr_resignations WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $resignationId]);
    $row = $stmt->fetch();

    if (!$row) {
        $pdo->rollBack();
        send_json(['ok' => false, 'message' => 'Resignation not found'], 404);
    }

    $employeeId = $row['employee_id'] !== null ? (int) $row['employee_id'] : 0;

    $approvedOn = null;
    $relievedOn = null;
    if ($statusLabel === 'Approved') {
        $approvedOn = date('Y-m-d');
    }
    if ($statusLabel === 'Relieved') {
        $relievedOn = date('Y-m-d');
    }

    $update = $pdo->prepare(
        'UPDATE hr_resignations
         SET status_label = :status_label,
             remarks_text = :remarks_text,
             approved_on = COALESCE(:approved_on, approved_on),
             relieved_on = COALESCE(:relieved_on, relieved_on)
         WHERE id = :id'
    );
    $update->execute([
        'status_label' => $statusLabel,
        'remarks_text' => null_if_empty($remarksText),
        'approved_on' => $approvedOn,
        'relieved_on' => $relievedOn,
        'id' => $resignationId,
    ]);

    if ($employeeId > 0 && ($statusLabel === 'Approved' || $statusLabel === 'Relieved')) {
        $empUpdate = $pdo->prepare('UPDATE employees SET employment_status = :status WHERE id = :id');
        $empUpdate->execute([
            'status' => 'resigned',
            'id' => $employeeId,
        ]);

        $profileUpdate = $pdo->prepare('UPDATE employee_profiles SET status_label = :status WHERE employee_id = :employee_id');
        $profileUpdate->execute([
            'status' => 'Resigned',
            'employee_id' => $employeeId,
        ]);
    }

    $pdo->commit();

    send_json([
        'ok' => true,
        'message' => 'Resignation status updated',
    ]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    send_json([
        'ok' => false,
        'message' => 'Failed to update resignation status',
        'error' => $e->getMessage(),
    ], 500);
}
