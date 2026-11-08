<?php

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $_SERVER['REQUEST_METHOD'] !== 'DELETE') {
    send_json(['ok' => false, 'message' => 'Method not allowed'], 405);
}

$user = require_auth();
require_module_permission('Purchase', 'edit', true);

$input = read_json_input();
$orderId = (int) ($input['id'] ?? 0);

if ($orderId <= 0) {
    send_json(['ok' => false, 'message' => 'id is required'], 422);
}

try {
    $pdo = db();
    ensure_purchase_schema($pdo);
    enforce_purchase_scope_for_order($pdo, $user, $orderId);

    $stmt = $pdo->prepare('DELETE FROM purchase_orders WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $orderId]);

    if ($stmt->rowCount() <= 0) {
        send_json(['ok' => false, 'message' => 'Purchase order not found'], 404);
    }

    send_json(['ok' => true, 'message' => 'Purchase order deleted successfully']);
} catch (Throwable $e) {
    send_json([
        'ok' => false,
        'message' => 'Failed to delete purchase order',
        'error' => $e->getMessage(),
    ], 500);
}
