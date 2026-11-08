<?php

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $_SERVER['REQUEST_METHOD'] !== 'DELETE') {
    send_json(['ok' => false, 'message' => 'Method not allowed'], 405);
}

$user = require_auth();
require_module_permission('Inventory', 'edit', true);

$input = read_json_input();
$itemId = (int) ($input['id'] ?? 0);

if ($itemId <= 0) {
    send_json(['ok' => false, 'message' => 'id is required'], 422);
}

try {
    $pdo = db();
    ensure_inventory_schema($pdo);
    enforce_inventory_scope_for_item($pdo, $user, $itemId);

    $stmt = $pdo->prepare('DELETE FROM inventory_items WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $itemId]);

    if ($stmt->rowCount() <= 0) {
        send_json(['ok' => false, 'message' => 'Inventory item not found'], 404);
    }

    send_json(['ok' => true, 'message' => 'Inventory item deleted successfully']);
} catch (Throwable $e) {
    send_json([
        'ok' => false,
        'message' => 'Failed to delete inventory item',
        'error' => $e->getMessage(),
    ], 500);
}
