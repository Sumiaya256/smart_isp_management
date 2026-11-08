<?php

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    send_json(['ok' => false, 'message' => 'Method not allowed'], 405);
}

$user = require_auth();

try {
    $pdo = db();
    ensure_inventory_schema($pdo);

    $itemScope = inventory_scope_sql($user, 'i', 'scope_employee_id');

    $summarySql =
        'SELECT
            COUNT(*) AS total_items,
            COALESCE(SUM(CASE WHEN i.active_status = 1 THEN 1 ELSE 0 END), 0) AS active_items,
            COALESCE(SUM(CASE WHEN i.current_stock <= i.min_stock THEN 1 ELSE 0 END), 0) AS low_stock_items,
            COALESCE(SUM(i.current_stock * i.unit_cost), 0) AS stock_value
         FROM inventory_items i
         WHERE 1=1' . $itemScope['sql'];

    $summaryStmt = $pdo->prepare($summarySql);
    $summaryStmt->execute($itemScope['params']);
    $summary = $summaryStmt->fetch() ?: [
        'total_items' => 0,
        'active_items' => 0,
        'low_stock_items' => 0,
        'stock_value' => 0,
    ];

    $movementScope = inventory_scope_sql($user, 'm', 'scope_employee_id');
    $movementSql =
        'SELECT
            COALESCE(SUM(CASE WHEN m.movement_type = "IN" THEN m.quantity ELSE 0 END), 0) AS total_in,
            COALESCE(SUM(CASE WHEN m.movement_type = "OUT" THEN m.quantity ELSE 0 END), 0) AS total_out,
            COALESCE(SUM(CASE WHEN m.created_at >= (NOW() - INTERVAL 30 DAY) THEN 1 ELSE 0 END), 0) AS last_30_days_entries
         FROM inventory_movements m
         WHERE 1=1' . $movementScope['sql'];

    $movementStmt = $pdo->prepare($movementSql);
    $movementStmt->execute($movementScope['params']);
    $movement = $movementStmt->fetch() ?: [
        'total_in' => 0,
        'total_out' => 0,
        'last_30_days_entries' => 0,
    ];

    send_json([
        'ok' => true,
        'summary' => $summary,
        'movement' => $movement,
    ]);
} catch (Throwable $e) {
    send_json([
        'ok' => false,
        'message' => 'Failed to load inventory stats',
        'error' => $e->getMessage(),
    ], 500);
}
