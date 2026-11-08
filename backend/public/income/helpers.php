<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/database.php';
require_once dirname(__DIR__, 2) . '/config/auth.php';

function ensure_income_schema(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS income_entries (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            invoice_no VARCHAR(60) NOT NULL,
            client_name VARCHAR(160) NOT NULL,
            package_name VARCHAR(120) DEFAULT NULL,
            income_type VARCHAR(80) NOT NULL,
            amount DECIMAL(14,2) NOT NULL DEFAULT 0,
            paid_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
            due_date DATE DEFAULT NULL,
            status_label ENUM('paid','pending','partial') NOT NULL DEFAULT 'pending',
            payment_method VARCHAR(60) DEFAULT NULL,
            notes TEXT DEFAULT NULL,
            source_invoice_id BIGINT UNSIGNED DEFAULT NULL,
            source_payment_id BIGINT UNSIGNED DEFAULT NULL,
            created_by_employee_id INT DEFAULT NULL,
            assigned_to_employee_id INT DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_income_type (income_type),
            INDEX idx_income_status (status_label),
            INDEX idx_income_due_date (due_date),
            INDEX idx_income_source_invoice (source_invoice_id),
            INDEX idx_income_source_payment (source_payment_id),
            INDEX idx_income_created_by (created_by_employee_id),
            INDEX idx_income_assigned_to (assigned_to_employee_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    ensure_income_column($pdo, 'source_invoice_id', 'BIGINT UNSIGNED NULL AFTER notes');
    ensure_income_column($pdo, 'source_payment_id', 'BIGINT UNSIGNED NULL AFTER source_invoice_id');
}

function ensure_income_column(PDO $pdo, string $columnName, string $definition): void
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*)
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name AND COLUMN_NAME = :column_name'
    );
    $stmt->execute([
        'table_name' => 'income_entries',
        'column_name' => $columnName,
    ]);

    if ((int) $stmt->fetchColumn() === 0) {
        $pdo->exec('ALTER TABLE income_entries ADD COLUMN ' . $columnName . ' ' . $definition);
    }
}

function income_scope_sql(array $user, string $alias = 'i', string $param = 'scope_employee_id'): array
{
    if (!is_limited_module_permission($user, 'Income')) {
        return ['sql' => '', 'params' => []];
    }

    $employeeId = (int) ($user['id'] ?? 0);
    if ($employeeId <= 0) {
        return ['sql' => ' AND 1=0', 'params' => []];
    }

    $createdParam = $param . '_created';
    $assignedParam = $param . '_assigned';

    return [
        'sql' => " AND ({$alias}.created_by_employee_id = :{$createdParam} OR {$alias}.assigned_to_employee_id = :{$assignedParam})",
        'params' => [
            $createdParam => $employeeId,
            $assignedParam => $employeeId,
        ],
    ];
}

function enforce_income_scope(PDO $pdo, array $user, int $entryId): void
{
    if (!is_limited_module_permission($user, 'Income')) {
        return;
    }

    $employeeId = (int) ($user['id'] ?? 0);
    if ($employeeId <= 0) {
        send_json(['ok' => false, 'message' => 'Forbidden: invalid limited scope'], 403);
    }

    $stmt = $pdo->prepare(
        'SELECT id FROM income_entries
         WHERE id = :id
                     AND (created_by_employee_id = :employee_id_created OR assigned_to_employee_id = :employee_id_assigned)
         LIMIT 1'
    );
        $stmt->execute([
                'id' => $entryId,
                'employee_id_created' => $employeeId,
                'employee_id_assigned' => $employeeId,
        ]);

    if (!$stmt->fetchColumn()) {
        send_json(['ok' => false, 'message' => 'Income entry access denied'], 403);
    }
}
