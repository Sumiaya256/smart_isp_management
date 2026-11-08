<?php

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json(['ok' => false, 'message' => 'Method not allowed'], 405);
}

$user = require_auth();
require_module_permission('Task Management', 'edit', true);

$input = read_json_input();
$id = (int) ($input['id'] ?? 0);
$status = trim((string) ($input['status_label'] ?? $input['status'] ?? ''));
$progress = isset($input['progress_percent']) ? (int) $input['progress_percent'] : null;

$allowedStatuses = ['Pending', 'In Progress', 'On Hold', 'Completed', 'Overdue'];
if ($id <= 0 || !in_array($status, $allowedStatuses, true)) {
    send_json(['ok' => false, 'message' => 'Valid id and status_label are required'], 422);
}

try {
    $pdo = db();
    ensure_tasks_schema($pdo);
    enforce_task_scope($pdo, $user, $id);

    if ($progress === null) {
        $progress = $status === 'Completed' ? 100 : null;
    }

    $sql = 'UPDATE task_items SET status_label = :status_label';
    $params = [
        'status_label' => $status,
        'id' => $id,
    ];

    if ($progress !== null) {
        $sql .= ', progress_percent = :progress_percent';
        $params['progress_percent'] = max(0, min(100, $progress));
    }

    $sql .= ' WHERE id = :id';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    send_json(['ok' => true, 'message' => 'Task status updated']);
} catch (Throwable $e) {
    send_json(['ok' => false, 'message' => 'Failed to update task status', 'error' => $e->getMessage()], 500);
}
