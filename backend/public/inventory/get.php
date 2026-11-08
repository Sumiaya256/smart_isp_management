<?php

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    send_json(['ok' => false, 'message' => 'Method not allowed'], 405);
}

$user = require_auth();

$itemId = (int) ($_GET['id'] ?? 0);
if ($itemId <= 0) {
    send_json(['ok' => false, 'message' => 'id is required'], 422);
}

try {
    $pdo = db();
    ensure_inventory_schema($pdo);
    enforce_inventory_scope_for_item($pdo, $user, $itemId);

    $stmt = $pdo->prepare(
        'SELECT id, item_code, item_name, category_name, unit_label,
                min_stock, current_stock, unit_cost, active_status,
                created_by_employee_id, assigned_to_employee_id,
                created_at, updated_at
         FROM inventory_items
         WHERE id = :id
         LIMIT 1'
    );
    $stmt->execute(['id' => $itemId]);
    $item = $stmt->fetch();

    if (!$item) {
        send_json(['ok' => false, 'message' => 'Inventory item not found'], 404);
    }

    $movStmt = $pdo->prepare(
        'SELECT id, movement_type, quantity, unit_cost, reference_label, notes,
                created_by_employee_id, assigned_to_employee_id, created_at
         FROM inventory_movements
         WHERE inventory_item_id = :inventory_item_id
         ORDER BY id DESC
         LIMIT 100'
    );
    $movStmt->execute(['inventory_item_id' => $itemId]);

    send_json([
        'ok' => true,
        'item' => $item,
        'movements' => $movStmt->fetchAll(),
    ]);
} catch (Throwable $e) {
    send_json([
        'ok' => false,
        'message' => 'Failed to load inventory item',
        'error' => $e->getMessage(),
    ], 500);
}
