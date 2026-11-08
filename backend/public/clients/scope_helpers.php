<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/database.php';
require_once dirname(__DIR__, 2) . '/config/auth.php';

function bypass_client_scope(array $user): bool
{
    $roleKey = preg_replace('/[^a-z]/', '', strtolower(trim((string) ($user['role_name'] ?? ''))));
    $positionKey = preg_replace('/[^a-z]/', '', strtolower(trim((string) ($user['position_name'] ?? ''))));

    return in_array($roleKey, ['admindirector', 'admin', 'administration', 'superadmin'], true)
        || in_array($positionKey, ['admindirector', 'admin'], true);
}

function ensure_client_scope_columns(PDO $pdo): void
{
    if (!client_column_exists($pdo, 'clients', 'created_by_employee_id')) {
        $pdo->exec('ALTER TABLE clients ADD COLUMN created_by_employee_id BIGINT UNSIGNED NULL AFTER package_id');
    }

    if (!client_column_exists($pdo, 'clients', 'assigned_to_employee_id')) {
        $pdo->exec('ALTER TABLE clients ADD COLUMN assigned_to_employee_id BIGINT UNSIGNED NULL AFTER created_by_employee_id');
    }
}

function apply_client_scope_where(array $user, string $alias = 'c', string $paramName = 'scope_employee_id'): array
{
    if (bypass_client_scope($user)) {
        return ['sql' => '', 'params' => []];
    }

    if (!is_limited_module_permission($user, 'Client')) {
        return ['sql' => '', 'params' => []];
    }

    $employeeId = (int) ($user['id'] ?? 0);
    if ($employeeId <= 0) {
        send_json(['ok' => false, 'message' => 'Forbidden: invalid employee scope'], 403);
    }

    return [
        'sql' => " AND ({$alias}.created_by_employee_id = :{$paramName} OR {$alias}.assigned_to_employee_id = :{$paramName})",
        'params' => [$paramName => $employeeId],
    ];
}

function enforce_client_scope_for_client_id(PDO $pdo, array $user, int $clientId): void
{
    if ($clientId <= 0) {
        send_json(['ok' => false, 'message' => 'Invalid client ID'], 400);
    }

    if (bypass_client_scope($user)) {
        return;
    }

    if (!is_limited_module_permission($user, 'Client')) {
        return;
    }

    $employeeId = (int) ($user['id'] ?? 0);
    if ($employeeId <= 0) {
        send_json(['ok' => false, 'message' => 'Forbidden: invalid employee scope'], 403);
    }

    ensure_client_scope_columns($pdo);

    $stmt = $pdo->prepare(
        'SELECT id
         FROM clients
         WHERE id = :client_id
           AND (created_by_employee_id = :employee_id OR assigned_to_employee_id = :employee_id)
         LIMIT 1'
    );
    $stmt->execute([
        'client_id' => $clientId,
        'employee_id' => $employeeId,
    ]);

    if (!$stmt->fetch()) {
        send_json(['ok' => false, 'message' => 'Forbidden: limited access allows only own/assigned clients'], 403);
    }
}

function client_column_exists(PDO $pdo, string $tableName, string $columnName): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*)
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name AND COLUMN_NAME = :column_name'
    );
    $stmt->execute([
        'table_name' => $tableName,
        'column_name' => $columnName,
    ]);

    return (int) $stmt->fetchColumn() > 0;
}
