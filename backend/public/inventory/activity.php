<?php

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    send_json(['ok' => false, 'message' => 'Method not allowed'], 405);
}

$user = require_auth();

$movementType = strtoupper(trim((string) ($_GET['movement_type'] ?? '')));
$limit = max(20, min(500, (int) ($_GET['limit'] ?? 200)));

try {
    $pdo = db();
    ensure_inventory_schema($pdo);

    $sql =
        'SELECT m.id, m.inventory_item_id, i.item_code, i.item_name, m.movement_type,
                m.quantity, m.unit_cost, m.reference_label, m.notes,
                m.created_by_employee_id, m.assigned_to_employee_id, m.created_at
         FROM inventory_movements m
         INNER JOIN inventory_items i ON i.id = m.inventory_item_id
         WHERE 1=1';

    $params = [];

    if (in_array($movementType, ['IN', 'OUT', 'ADJUST'], true)) {
        $sql .= ' AND m.movement_type = :movement_type';
        $params['movement_type'] = $movementType;
    }

    $scope = inventory_scope_sql($user, 'm', 'scope_employee_id');
    $sql .= $scope['sql'];
    $params = array_merge($params, $scope['params']);

    $sql .= ' ORDER BY m.id DESC LIMIT ' . (int) $limit;

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    send_json([
        'ok' => true,
        'movements' => $stmt->fetchAll(),
    ]);
} catch (Throwable $e) {
    send_json([
        'ok' => false,
        'message' => 'Failed to load inventory activity',
        'error' => $e->getMessage(),
    ], 500);
}
