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
$orderDate = trim((string) ($input['order_date'] ?? ''));
$vendorName = trim((string) ($input['vendor_name'] ?? $input['vendor'] ?? ''));
$categoryName = trim((string) ($input['category_name'] ?? $input['cat'] ?? ''));
$requestedByName = trim((string) ($input['requested_by_name'] ?? $input['reqby'] ?? ''));
$deliveryDate = trim((string) ($input['delivery_date'] ?? $input['delivery'] ?? ''));
$statusLabel = trim((string) ($input['status_label'] ?? $input['sts'] ?? 'Pending'));
$notes = trim((string) ($input['notes'] ?? ''));
$items = normalize_purchase_items($input['items'] ?? []);

if ($orderDate === '' || $vendorName === '' || empty($items)) {
    send_json(['ok' => false, 'message' => 'order_date, vendor_name and at least one item are required'], 422);
}

$allowedStatuses = ['Pending', 'Approved', 'Received', 'Partial', 'Cancelled'];
if (!in_array($statusLabel, $allowedStatuses, true)) {
    $statusLabel = 'Pending';
}

$totalAmount = array_reduce($items, static fn ($sum, $item) => $sum + (float) $item['line_total'], 0.0);

try {
    $pdo = db();
    ensure_purchase_schema($pdo);
    $pdo->beginTransaction();

    $employeeId = (int) ($user['id'] ?? 0);

    if ($orderId > 0) {
        enforce_purchase_scope_for_order($pdo, $user, $orderId);

        $update = $pdo->prepare(
            'UPDATE purchase_orders
             SET order_date = :order_date,
                 vendor_name = :vendor_name,
                 category_name = :category_name,
                 requested_by_name = :requested_by_name,
                 delivery_date = :delivery_date,
                 status_label = :status_label,
                 notes = :notes,
                 total_amount = :total_amount,
                 assigned_to_employee_id = :assigned_to_employee_id
             WHERE id = :id'
        );
        $update->execute([
            'order_date' => $orderDate,
            'vendor_name' => mb_substr($vendorName, 0, 150),
            'category_name' => $categoryName !== '' ? mb_substr($categoryName, 0, 100) : null,
            'requested_by_name' => $requestedByName !== '' ? mb_substr($requestedByName, 0, 120) : null,
            'delivery_date' => $deliveryDate !== '' ? $deliveryDate : null,
            'status_label' => $statusLabel,
            'notes' => $notes !== '' ? $notes : null,
            'total_amount' => $totalAmount,
            'assigned_to_employee_id' => $employeeId > 0 ? $employeeId : null,
            'id' => $orderId,
        ]);

        $delItems = $pdo->prepare('DELETE FROM purchase_order_items WHERE purchase_order_id = :purchase_order_id');
        $delItems->execute(['purchase_order_id' => $orderId]);
    } else {
        $poNumber = next_po_number($pdo);
        $insert = $pdo->prepare(
            'INSERT INTO purchase_orders (
                po_number, order_date, vendor_name, category_name, requested_by_name, delivery_date,
                status_label, notes, total_amount, created_by_employee_id, assigned_to_employee_id
             ) VALUES (
                :po_number, :order_date, :vendor_name, :category_name, :requested_by_name, :delivery_date,
                :status_label, :notes, :total_amount, :created_by_employee_id, :assigned_to_employee_id
             )'
        );
        $insert->execute([
            'po_number' => $poNumber,
            'order_date' => $orderDate,
            'vendor_name' => mb_substr($vendorName, 0, 150),
            'category_name' => $categoryName !== '' ? mb_substr($categoryName, 0, 100) : null,
            'requested_by_name' => $requestedByName !== '' ? mb_substr($requestedByName, 0, 120) : null,
            'delivery_date' => $deliveryDate !== '' ? $deliveryDate : null,
            'status_label' => $statusLabel,
            'notes' => $notes !== '' ? $notes : null,
            'total_amount' => $totalAmount,
            'created_by_employee_id' => $employeeId > 0 ? $employeeId : null,
            'assigned_to_employee_id' => $employeeId > 0 ? $employeeId : null,
        ]);

        $orderId = (int) $pdo->lastInsertId();
    }

    $insItem = $pdo->prepare(
        'INSERT INTO purchase_order_items (purchase_order_id, item_name, quantity, unit_price, line_total)
         VALUES (:purchase_order_id, :item_name, :quantity, :unit_price, :line_total)'
    );

    foreach ($items as $item) {
        $insItem->execute([
            'purchase_order_id' => $orderId,
            'item_name' => $item['item_name'],
            'quantity' => $item['quantity'],
            'unit_price' => $item['unit_price'],
            'line_total' => $item['line_total'],
        ]);
    }

    $pdo->commit();

    send_json([
        'ok' => true,
        'message' => 'Purchase order saved successfully',
        'id' => $orderId,
    ]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    send_json([
        'ok' => false,
        'message' => 'Failed to save purchase order',
        'error' => $e->getMessage(),
    ], 500);
}
