<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/database.php';
require_once dirname(__DIR__, 2) . '/config/auth.php';

function ensure_assets_schema(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS assets_items (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            asset_tag VARCHAR(60) NOT NULL UNIQUE,
            asset_name VARCHAR(180) NOT NULL,
            type_name VARCHAR(80) DEFAULT NULL,
            purchase_date DATE DEFAULT NULL,
            purchase_value DECIMAL(14,2) NOT NULL DEFAULT 0,
            assigned_to_name VARCHAR(140) DEFAULT NULL,
            status_label ENUM('active','assigned','repair','spare','retired') NOT NULL DEFAULT 'active',
            notes TEXT DEFAULT NULL,
            created_by_employee_id INT DEFAULT NULL,
            assigned_to_employee_id INT DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_assets_type (type_name),
            INDEX idx_assets_status (status_label),
            INDEX idx_assets_created_by (created_by_employee_id),
            INDEX idx_assets_assigned_to (assigned_to_employee_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
}

function assets_scope_sql(array $user, string $alias = 'a', string $param = 'scope_employee_id'): array
{
    if (!is_limited_module_permission($user, 'Assets')) {
        return ['sql' => '', 'params' => []];
    }

    $employeeId = (int) ($user['id'] ?? 0);
    if ($employeeId <= 0) {
        return ['sql' => ' AND 1=0', 'params' => []];
    }

    return [
        'sql' => " AND ({$alias}.created_by_employee_id = :{$param} OR {$alias}.assigned_to_employee_id = :{$param})",
        'params' => [$param => $employeeId],
    ];
}

function enforce_assets_scope(PDO $pdo, array $user, int $assetId): void
{
    if (!is_limited_module_permission($user, 'Assets')) {
        return;
    }

    $employeeId = (int) ($user['id'] ?? 0);
    if ($employeeId <= 0) {
        send_json(['ok' => false, 'message' => 'Forbidden: invalid limited scope'], 403);
    }

    $stmt = $pdo->prepare(
        'SELECT id FROM assets_items
         WHERE id = :id
           AND (created_by_employee_id = :employee_id OR assigned_to_employee_id = :employee_id)
         LIMIT 1'
    );
    $stmt->execute(['id' => $assetId, 'employee_id' => $employeeId]);

    if (!$stmt->fetchColumn()) {
        send_json(['ok' => false, 'message' => 'Asset access denied'], 403);
    }
}
