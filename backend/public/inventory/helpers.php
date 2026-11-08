<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/database.php';
require_once dirname(__DIR__, 2) . '/config/auth.php';

function ensure_column(PDO $pdo, string $table, string $column, string $definition): void
{
    $check = $pdo->prepare(
        'SELECT COUNT(*)
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = :table_name
           AND COLUMN_NAME = :column_name'
    );
    $check->execute([
        'table_name' => $table,
        'column_name' => $column,
    ]);

    if ((int) $check->fetchColumn() <= 0) {
        $pdo->exec(sprintf('ALTER TABLE `%s` ADD COLUMN `%s` %s', $table, $column, $definition));
    }
}

function table_exists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*)
         FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = :table_name'
    );
    $stmt->execute(['table_name' => $table]);
    return (int) $stmt->fetchColumn() > 0;
}

function ensure_inventory_schema(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS inventory_items (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            item_code VARCHAR(40) NOT NULL UNIQUE,
            item_name VARCHAR(180) NOT NULL,
            category_name VARCHAR(120) DEFAULT NULL,
            unit_label VARCHAR(40) DEFAULT NULL,
            min_stock DECIMAL(12,2) NOT NULL DEFAULT 0,
            current_stock DECIMAL(12,2) NOT NULL DEFAULT 0,
            unit_cost DECIMAL(12,2) NOT NULL DEFAULT 0,
            active_status TINYINT(1) NOT NULL DEFAULT 1,
            created_by_employee_id INT DEFAULT NULL,
            assigned_to_employee_id INT DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_inventory_items_scope_created (created_by_employee_id),
            INDEX idx_inventory_items_scope_assigned (assigned_to_employee_id),
            INDEX idx_inventory_items_item_name (item_name),
            INDEX idx_inventory_items_category (category_name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    if (!table_exists($pdo, 'inventory_movements')) {
        try {
            $pdo->exec(
                "CREATE TABLE IF NOT EXISTS inventory_movements (
                    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                    inventory_item_id INT UNSIGNED NOT NULL,
                    movement_type ENUM('IN','OUT','ADJUST') NOT NULL,
                    quantity DECIMAL(12,2) NOT NULL,
                    unit_cost DECIMAL(12,2) DEFAULT NULL,
                    reference_label VARCHAR(80) DEFAULT NULL,
                    notes TEXT DEFAULT NULL,
                    created_by_employee_id INT DEFAULT NULL,
                    assigned_to_employee_id INT DEFAULT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    CONSTRAINT fk_inventory_movements_item
                        FOREIGN KEY (inventory_item_id) REFERENCES inventory_items(id)
                        ON DELETE CASCADE,
                    INDEX idx_inventory_movements_item (inventory_item_id),
                    INDEX idx_inventory_movements_scope_created (created_by_employee_id),
                    INDEX idx_inventory_movements_scope_assigned (assigned_to_employee_id),
                    INDEX idx_inventory_movements_created_at (created_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
            );
        } catch (Throwable $e) {
            // Fallback for legacy databases where foreign key creation may fail.
            $pdo->exec(
                "CREATE TABLE IF NOT EXISTS inventory_movements (
                    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                    inventory_item_id INT UNSIGNED NOT NULL,
                    movement_type ENUM('IN','OUT','ADJUST') NOT NULL,
                    quantity DECIMAL(12,2) NOT NULL,
                    unit_cost DECIMAL(12,2) DEFAULT NULL,
                    reference_label VARCHAR(80) DEFAULT NULL,
                    notes TEXT DEFAULT NULL,
                    created_by_employee_id INT DEFAULT NULL,
                    assigned_to_employee_id INT DEFAULT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_inventory_movements_item (inventory_item_id),
                    INDEX idx_inventory_movements_scope_created (created_by_employee_id),
                    INDEX idx_inventory_movements_scope_assigned (assigned_to_employee_id),
                    INDEX idx_inventory_movements_created_at (created_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
            );
        }
    }

    ensure_column($pdo, 'inventory_items', 'item_code', "VARCHAR(40) NOT NULL DEFAULT ''");
    ensure_column($pdo, 'inventory_items', 'item_name', "VARCHAR(180) NOT NULL DEFAULT ''");
    ensure_column($pdo, 'inventory_items', 'category_name', 'VARCHAR(120) DEFAULT NULL');
    ensure_column($pdo, 'inventory_items', 'unit_label', 'VARCHAR(40) DEFAULT NULL');
    ensure_column($pdo, 'inventory_items', 'min_stock', 'DECIMAL(12,2) NOT NULL DEFAULT 0');
    ensure_column($pdo, 'inventory_items', 'current_stock', 'DECIMAL(12,2) NOT NULL DEFAULT 0');
    ensure_column($pdo, 'inventory_items', 'unit_cost', 'DECIMAL(12,2) NOT NULL DEFAULT 0');
    ensure_column($pdo, 'inventory_items', 'active_status', 'TINYINT(1) NOT NULL DEFAULT 1');
    ensure_column($pdo, 'inventory_items', 'created_at', 'TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP');
    ensure_column($pdo, 'inventory_items', 'updated_at', 'TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP');

    ensure_column($pdo, 'inventory_items', 'created_by_employee_id', 'INT DEFAULT NULL');
    ensure_column($pdo, 'inventory_items', 'assigned_to_employee_id', 'INT DEFAULT NULL');

    ensure_column($pdo, 'inventory_movements', 'inventory_item_id', 'INT UNSIGNED NOT NULL');
    ensure_column($pdo, 'inventory_movements', 'movement_type', "ENUM('IN','OUT','ADJUST') NOT NULL");
    ensure_column($pdo, 'inventory_movements', 'quantity', 'DECIMAL(12,2) NOT NULL DEFAULT 0');
    ensure_column($pdo, 'inventory_movements', 'unit_cost', 'DECIMAL(12,2) DEFAULT NULL');
    ensure_column($pdo, 'inventory_movements', 'reference_label', 'VARCHAR(80) DEFAULT NULL');
    ensure_column($pdo, 'inventory_movements', 'notes', 'TEXT DEFAULT NULL');
    ensure_column($pdo, 'inventory_movements', 'created_at', 'TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP');
    ensure_column($pdo, 'inventory_movements', 'created_by_employee_id', 'INT DEFAULT NULL');
    ensure_column($pdo, 'inventory_movements', 'assigned_to_employee_id', 'INT DEFAULT NULL');
}

function inventory_scope_sql(array $user, string $alias, string $paramName = 'scope_employee_id'): array
{
    if (!is_limited_module_permission($user, 'Inventory')) {
        return ['sql' => '', 'params' => []];
    }

    $employeeId = (int) ($user['id'] ?? 0);
    if ($employeeId <= 0) {
        return ['sql' => ' AND 1=0', 'params' => []];
    }

    $createdParam = $paramName . '_created';
    $assignedParam = $paramName . '_assigned';

    return [
        'sql' => " AND ({$alias}.created_by_employee_id = :{$createdParam} OR {$alias}.assigned_to_employee_id = :{$assignedParam})",
        'params' => [
            $createdParam => $employeeId,
            $assignedParam => $employeeId,
        ],
    ];
}

function enforce_inventory_scope_for_item(PDO $pdo, array $user, int $itemId): void
{
    if (!is_limited_module_permission($user, 'Inventory')) {
        return;
    }

    $employeeId = (int) ($user['id'] ?? 0);
    if ($employeeId <= 0) {
        send_json(['ok' => false, 'message' => 'Unauthorized inventory scope'], 403);
    }

    $stmt = $pdo->prepare(
        'SELECT id
         FROM inventory_items
         WHERE id = :id
                     AND (created_by_employee_id = :employee_id_created OR assigned_to_employee_id = :employee_id_assigned)
         LIMIT 1'
    );
    $stmt->execute([
        'id' => $itemId,
                'employee_id_created' => $employeeId,
                'employee_id_assigned' => $employeeId,
    ]);

    if (!$stmt->fetchColumn()) {
        send_json(['ok' => false, 'message' => 'Inventory item access denied'], 403);
    }
}

function next_item_code(PDO $pdo): string
{
    $datePart = date('Ymd');
    $stmt = $pdo->prepare(
        "SELECT item_code
         FROM inventory_items
         WHERE item_code LIKE :prefix
         ORDER BY id DESC
         LIMIT 1"
    );
    $stmt->execute(['prefix' => 'ITM-' . $datePart . '-%']);
    $last = (string) ($stmt->fetchColumn() ?: '');

    $next = 1;
    if (preg_match('/-(\d+)$/', $last, $m)) {
        $next = ((int) $m[1]) + 1;
    }

    return sprintf('ITM-%s-%04d', $datePart, $next);
}

function recalculate_item_stock(PDO $pdo, int $itemId): void
{
    $stmt = $pdo->prepare(
        "SELECT
            COALESCE(SUM(CASE WHEN movement_type = 'IN' THEN quantity WHEN movement_type = 'OUT' THEN -quantity ELSE quantity END), 0) AS net_qty,
            COALESCE(AVG(NULLIF(unit_cost, 0)), 0) AS avg_cost
         FROM inventory_movements
         WHERE inventory_item_id = :inventory_item_id"
    );
    $stmt->execute(['inventory_item_id' => $itemId]);
    $row = $stmt->fetch() ?: ['net_qty' => 0, 'avg_cost' => 0];

    $upd = $pdo->prepare(
        'UPDATE inventory_items
         SET current_stock = :current_stock,
             unit_cost = CASE WHEN :avg_cost_check > 0 THEN :avg_cost_value ELSE unit_cost END
         WHERE id = :id'
    );
    $upd->execute([
        'current_stock' => (float) $row['net_qty'],
        'avg_cost_check' => (float) $row['avg_cost'],
        'avg_cost_value' => (float) $row['avg_cost'],
        'id' => $itemId,
    ]);
}
