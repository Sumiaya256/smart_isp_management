<?php

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json(['ok' => false, 'message' => 'Method not allowed'], 405);
}

$user = require_auth();
require_module_permission('Inventory', 'edit', true);

$input = read_json_input();
$itemId = (int) ($input['inventory_item_id'] ?? $input['id'] ?? 0);
$movementType = strtoupper(trim((string) ($input['movement_type'] ?? $input['type'] ?? '')));
$quantity = (float) ($input['quantity'] ?? 0);
$unitCost = (float) ($input['unit_cost'] ?? 0);
$referenceLabel = trim((string) ($input['reference_label'] ?? $input['reference'] ?? ''));
$notes = trim((string) ($input['notes'] ?? ''));

if ($itemId <= 0 || $quantity <= 0 || !in_array($movementType, ['IN', 'OUT', 'ADJUST'], true)) {
    send_json(['ok' => false, 'message' => 'Valid inventory_item_id, movement_type and quantity are required'], 422);
}

try {
    $pdo = db();
    ensure_inventory_schema($pdo);
    enforce_inventory_scope_for_item($pdo, $user, $itemId);

    $stmt = $pdo->prepare('SELECT current_stock FROM inventory_items WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $itemId]);
    $currentStock = (float) ($stmt->fetchColumn() ?: 0.0);

    if ($movementType === 'OUT' && $quantity > $currentStock) {
        send_json(['ok' => false, 'message' => 'Insufficient stock for OUT movement'], 422);
    }

    $employeeId = (int) ($user['id'] ?? 0);

    $ins = $pdo->prepare(
        'INSERT INTO inventory_movements (
            inventory_item_id, movement_type, quantity, unit_cost, reference_label, notes,
            created_by_employee_id, assigned_to_employee_id
         ) VALUES (
            :inventory_item_id, :movement_type, :quantity, :unit_cost, :reference_label, :notes,
            :created_by_employee_id, :assigned_to_employee_id
         )'
    );
    $ins->execute([
        'inventory_item_id' => $itemId,
        'movement_type' => $movementType,
        'quantity' => $quantity,
        'unit_cost' => $unitCost > 0 ? $unitCost : null,
        'reference_label' => $referenceLabel !== '' ? mb_substr($referenceLabel, 0, 80) : null,
        'notes' => $notes !== '' ? $notes : null,
        'created_by_employee_id' => $employeeId > 0 ? $employeeId : null,
        'assigned_to_employee_id' => $employeeId > 0 ? $employeeId : null,
    ]);

    recalculate_item_stock($pdo, $itemId);

    send_json([
        'ok' => true,
        'message' => 'Stock movement recorded successfully',
    ]);
} catch (Throwable $e) {
    send_json([
        'ok' => false,
        'message' => 'Failed to record stock movement: ' . $e->getMessage(),
        'error' => $e->getMessage(),
    ], 500);
}
