<?php

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json(['ok' => false, 'message' => 'Method not allowed'], 405);
}

$user = require_auth();
require_module_permission('Inventory', 'edit', true);

$input = read_json_input();
$itemId = (int) ($input['id'] ?? 0);
$itemName = trim((string) ($input['item_name'] ?? ''));
$categoryName = trim((string) ($input['category_name'] ?? $input['category'] ?? ''));
$unitLabel = trim((string) ($input['unit_label'] ?? $input['unit'] ?? 'pcs'));
$minStock = max(0, (float) ($input['min_stock'] ?? 0));
$openingQty = max(0, (float) ($input['opening_qty'] ?? 0));
$openingUnitCost = max(0, (float) ($input['opening_unit_cost'] ?? 0));
$activeStatus = (int) ($input['active_status'] ?? 1) ? 1 : 0;

if ($itemName === '') {
    send_json(['ok' => false, 'message' => 'item_name is required'], 422);
}

try {
    $pdo = db();
    ensure_inventory_schema($pdo);
    $pdo->beginTransaction();

    $employeeId = (int) ($user['id'] ?? 0);

    if ($itemId > 0) {
        enforce_inventory_scope_for_item($pdo, $user, $itemId);

        $stmt = $pdo->prepare(
            'UPDATE inventory_items
             SET item_name = :item_name,
                 category_name = :category_name,
                 unit_label = :unit_label,
                 min_stock = :min_stock,
                 active_status = :active_status,
                 assigned_to_employee_id = :assigned_to_employee_id
             WHERE id = :id'
        );
        $stmt->execute([
            'item_name' => mb_substr($itemName, 0, 180),
            'category_name' => $categoryName !== '' ? mb_substr($categoryName, 0, 120) : null,
            'unit_label' => mb_substr($unitLabel, 0, 40),
            'min_stock' => $minStock,
            'active_status' => $activeStatus,
            'assigned_to_employee_id' => $employeeId > 0 ? $employeeId : null,
            'id' => $itemId,
        ]);
    } else {
        $itemCode = next_item_code($pdo);

        $stmt = $pdo->prepare(
            'INSERT INTO inventory_items (
                item_code, item_name, category_name, unit_label, min_stock, current_stock, unit_cost,
                active_status, created_by_employee_id, assigned_to_employee_id
             ) VALUES (
                :item_code, :item_name, :category_name, :unit_label, :min_stock, 0, 0,
                :active_status, :created_by_employee_id, :assigned_to_employee_id
             )'
        );
        $stmt->execute([
            'item_code' => $itemCode,
            'item_name' => mb_substr($itemName, 0, 180),
            'category_name' => $categoryName !== '' ? mb_substr($categoryName, 0, 120) : null,
            'unit_label' => mb_substr($unitLabel, 0, 40),
            'min_stock' => $minStock,
            'active_status' => $activeStatus,
            'created_by_employee_id' => $employeeId > 0 ? $employeeId : null,
            'assigned_to_employee_id' => $employeeId > 0 ? $employeeId : null,
        ]);

        $itemId = (int) $pdo->lastInsertId();

        if ($openingQty > 0) {
            $mov = $pdo->prepare(
                'INSERT INTO inventory_movements (
                    inventory_item_id, movement_type, quantity, unit_cost, reference_label, notes,
                    created_by_employee_id, assigned_to_employee_id
                 ) VALUES (
                    :inventory_item_id, :movement_type, :quantity, :unit_cost, :reference_label, :notes,
                    :created_by_employee_id, :assigned_to_employee_id
                 )'
            );
            $mov->execute([
                'inventory_item_id' => $itemId,
                'movement_type' => 'IN',
                'quantity' => $openingQty,
                'unit_cost' => $openingUnitCost > 0 ? $openingUnitCost : null,
                'reference_label' => 'OPENING',
                'notes' => 'Opening stock',
                'created_by_employee_id' => $employeeId > 0 ? $employeeId : null,
                'assigned_to_employee_id' => $employeeId > 0 ? $employeeId : null,
            ]);

            recalculate_item_stock($pdo, $itemId);
        }
    }

    if ($pdo->inTransaction()) {
        $pdo->commit();
    }

    send_json([
        'ok' => true,
        'message' => 'Inventory item saved successfully',
        'id' => $itemId,
    ]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    send_json([
        'ok' => false,
        'message' => 'Failed to save inventory item: ' . $e->getMessage(),
        'error' => $e->getMessage(),
    ], 500);
}
