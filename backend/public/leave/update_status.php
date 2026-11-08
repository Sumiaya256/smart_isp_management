<?php

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json(['ok' => false, 'message' => 'Method not allowed'], 405);
}

$user = require_auth();
require_module_permission('Leave Management', 'edit', true);

$input = read_json_input();
$id = (int) ($input['id'] ?? 0);
$statusLabel = trim((string) ($input['status_label'] ?? ''));
$rejectReason = trim((string) ($input['reject_reason'] ?? ''));

$allowed = ['pending', 'approved', 'rejected', 'cancelled'];
if ($id <= 0 || !in_array($statusLabel, $allowed, true)) {
    send_json(['ok' => false, 'message' => 'Valid id and status_label are required'], 422);
}

try {
    $pdo = db();
    ensure_leave_schema($pdo);
    enforce_leave_scope($pdo, $user, $id);

    $stmt = $pdo->prepare(
        'UPDATE leave_requests
         SET status_label = :status_label,
             reject_reason = :reject_reason,
             assigned_to_employee_id = :assigned_to_employee_id
         WHERE id = :id'
    );
    $stmt->execute([
        'status_label' => $statusLabel,
        'reject_reason' => $rejectReason !== '' ? $rejectReason : null,
        'assigned_to_employee_id' => (int) ($user['id'] ?? 0) ?: null,
        'id' => $id,
    ]);

    send_json(['ok' => true, 'message' => 'Leave status updated']);
} catch (Throwable $e) {
    send_json(['ok' => false, 'message' => 'Failed to update leave status', 'error' => $e->getMessage()], 500);
}
