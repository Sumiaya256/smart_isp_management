<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/database.php';
require_once dirname(__DIR__, 2) . '/config/auth.php';

function ensure_purchase_schema(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS purchase_orders (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            po_number VARCHAR(40) NOT NULL UNIQUE,
            order_date DATE NOT NULL,
            vendor_name VARCHAR(150) NOT NULL,
            category_name VARCHAR(100) NULL,
            requested_by_name VARCHAR(120) NULL,
            delivery_date DATE NULL,
            status_label ENUM("Pending", "Approved", "Received", "Partial", "Cancelled") NOT NULL DEFAULT "Pending",
            notes TEXT NULL,
            total_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
            created_by_employee_id BIGINT UNSIGNED NULL,
            assigned_to_employee_id BIGINT UNSIGNED NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_purchase_status (status_label),
            KEY idx_purchase_vendor (vendor_name),
            KEY idx_purchase_created_by (created_by_employee_id),
            KEY idx_purchase_assigned_to (assigned_to_employee_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS purchase_order_items (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            purchase_order_id BIGINT UNSIGNED NOT NULL,
            item_name VARCHAR(180) NOT NULL,
            quantity DECIMAL(12,2) NOT NULL DEFAULT 0,
            unit_price DECIMAL(14,2) NOT NULL DEFAULT 0,
            line_total DECIMAL(14,2) NOT NULL DEFAULT 0,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_purchase_order_items_order FOREIGN KEY (purchase_order_id) REFERENCES purchase_orders(id) ON DELETE CASCADE,
            KEY idx_purchase_order_items_order (purchase_order_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
}

function purchase_scope_sql(array $user, string $tableAlias = 'po', string $paramName = 'scope_employee_id'): array
{
    if (!is_limited_module_permission($user, 'Purchase')) {
        return ['sql' => '', 'params' => []];
    }

    $employeeId = (int) ($user['id'] ?? 0);
    if ($employeeId <= 0) {
        send_json(['ok' => false, 'message' => 'Forbidden: invalid limited scope'], 403);
    }

    return [
        'sql' => " AND ({$tableAlias}.created_by_employee_id = :{$paramName} OR {$tableAlias}.assigned_to_employee_id = :{$paramName})",
        'params' => [$paramName => $employeeId],
    ];
}

function enforce_purchase_scope_for_order(PDO $pdo, array $user, int $orderId): void
{
    if (!is_limited_module_permission($user, 'Purchase')) {
        return;
    }

    $employeeId = (int) ($user['id'] ?? 0);
    if ($employeeId <= 0) {
        send_json(['ok' => false, 'message' => 'Forbidden: invalid limited scope'], 403);
    }

    $stmt = $pdo->prepare(
        'SELECT id FROM purchase_orders
         WHERE id = :id
           AND (created_by_employee_id = :employee_id OR assigned_to_employee_id = :employee_id)
         LIMIT 1'
    );
    $stmt->execute([
        'id' => $orderId,
        'employee_id' => $employeeId,
    ]);

    if (!$stmt->fetch()) {
        send_json(['ok' => false, 'message' => 'Forbidden: limited access allows only own/assigned purchase orders'], 403);
    }
}

function normalize_purchase_items($rawItems): array
{
    if (!is_array($rawItems)) {
        return [];
    }

    $items = [];
    foreach ($rawItems as $entry) {
        if (!is_array($entry)) {
            continue;
        }

        $itemName = trim((string) ($entry['name'] ?? $entry['item_name'] ?? ''));
        $qty = (float) ($entry['qty'] ?? $entry['quantity'] ?? 0);
        $price = (float) ($entry['price'] ?? $entry['unit_price'] ?? 0);

        if ($itemName === '' || $qty <= 0 || $price < 0) {
            continue;
        }

        $lineTotal = $qty * $price;
        $items[] = [
            'item_name' => mb_substr($itemName, 0, 180),
            'quantity' => $qty,
            'unit_price' => $price,
            'line_total' => $lineTotal,
        ];
    }

    return $items;
}

function next_po_number(PDO $pdo): string
{
    $prefix = 'PO-' . date('Ymd') . '-';
    for ($i = 0; $i < 20; $i += 1) {
        $candidate = $prefix . random_int(1000, 9999);
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM purchase_orders WHERE po_number = :po_number');
        $stmt->execute(['po_number' => $candidate]);
        if ((int) $stmt->fetchColumn() === 0) {
            return $candidate;
        }
    }

    return $prefix . time();
}
