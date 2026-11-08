<?php

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    send_json(['ok' => false, 'message' => 'Method not allowed'], 405);
}

$user = require_auth();

$orderId = (int) ($_GET['id'] ?? 0);
if ($orderId <= 0) {
    send_json(['ok' => false, 'message' => 'id is required'], 422);
}

try {
    $pdo = db();
    ensure_purchase_schema($pdo);
    enforce_purchase_scope_for_order($pdo, $user, $orderId);

    $stmt = $pdo->prepare(
        'SELECT id, po_number, order_date, vendor_name, category_name, requested_by_name,
                delivery_date, status_label, notes, total_amount,
                created_by_employee_id, assigned_to_employee_id,
                created_at, updated_at
         FROM purchase_orders
         WHERE id = :id
         LIMIT 1'
    );
    $stmt->execute(['id' => $orderId]);
    $order = $stmt->fetch();

    if (!$order) {
        send_json(['ok' => false, 'message' => 'Purchase order not found'], 404);
    }

    $itemStmt = $pdo->prepare(
        'SELECT id, item_name, quantity, unit_price, line_total
         FROM purchase_order_items
         WHERE purchase_order_id = :purchase_order_id
         ORDER BY id ASC'
    );
    $itemStmt->execute(['purchase_order_id' => $orderId]);

    $order['items'] = $itemStmt->fetchAll();

    send_json([
        'ok' => true,
        'order' => $order,
    ]);
} catch (Throwable $e) {
    send_json([
        'ok' => false,
        'message' => 'Failed to load purchase order',
        'error' => $e->getMessage(),
    ], 500);
}
