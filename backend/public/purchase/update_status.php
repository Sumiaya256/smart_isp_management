<?php

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json(['ok' => false, 'message' => 'Method not allowed'], 405);
}

$user = require_auth();
require_module_permission('Purchase', 'edit', true);

$input = read_json_input();
$orderId = (int) ($input['id'] ?? 0);
$statusLabel = trim((string) ($input['status_label'] ?? ''));

$allowedStatuses = ['Pending', 'Approved', 'Received', 'Partial', 'Cancelled'];
if ($orderId <= 0 || !in_array($statusLabel, $allowedStatuses, true)) {
    send_json(['ok' => false, 'message' => 'Valid id and status_label are required'], 422);
}

try {
    $pdo = db();
    ensure_purchase_schema($pdo);
    enforce_purchase_scope_for_order($pdo, $user, $orderId);

    $stmt = $pdo->prepare(
        'UPDATE purchase_orders
         SET status_label = :status_label,
             assigned_to_employee_id = :assigned_to_employee_id
         WHERE id = :id'
    );
    $stmt->execute([
        'status_label' => $statusLabel,
        'assigned_to_employee_id' => (int) ($user['id'] ?? 0) ?: null,
        'id' => $orderId,
    ]);

    send_json(['ok' => true, 'message' => 'Purchase order status updated']);
} catch (Throwable $e) {
    send_json([
        'ok' => false,
        'message' => 'Failed to update purchase status',
        'error' => $e->getMessage(),
    ], 500);
}
