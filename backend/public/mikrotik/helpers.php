<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/database.php';
require_once dirname(__DIR__, 2) . '/config/auth.php';

function mikrotik_column_exists(PDO $pdo, string $tableName, string $columnName): bool
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

function ensure_mikrotik_bulk_table(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS mikrotik_bulk_import_rows (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            batch_id VARCHAR(60) NOT NULL,
            uploaded_by_employee_id BIGINT UNSIGNED NULL,
            full_name VARCHAR(120) NOT NULL,
            mobile VARCHAR(25) NOT NULL,
            email VARCHAR(180) NULL,
            national_id VARCHAR(40) NULL,
            address_line VARCHAR(255) NULL,
            zone_name VARCHAR(80) NULL,
            connection_type VARCHAR(40) NULL,
            server_name VARCHAR(80) NULL,
            protocol_type VARCHAR(40) NULL,
            profile_name VARCHAR(80) NULL,
            username VARCHAR(120) NULL,
            password_plain VARCHAR(120) NULL,
            customer_type VARCHAR(40) NULL,
            package_name VARCHAR(100) NULL,
            billing_status VARCHAR(40) NULL,
            monthly_bill DECIMAL(10,2) NULL,
            bill_month VARCHAR(20) NULL,
            join_date DATE NULL,
            expire_date DATE NULL,
            row_status ENUM("pending", "imported", "error") NOT NULL DEFAULT "pending",
            status_note VARCHAR(255) NULL,
            linked_client_id BIGINT UNSIGNED NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            expires_at DATETIME NOT NULL,
            INDEX idx_mikrotik_bulk_batch (batch_id),
            INDEX idx_mikrotik_bulk_status (row_status),
            INDEX idx_mikrotik_bulk_expires (expires_at),
            INDEX idx_mikrotik_bulk_user (uploaded_by_employee_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
}

function cleanup_expired_mikrotik_rows(PDO $pdo): void
{
    $pdo->exec('DELETE FROM mikrotik_bulk_import_rows WHERE expires_at < NOW()');
}

function is_mikrotik_limited(array $user): bool
{
    $permissions = effective_module_permissions($user);
    $level = strtolower(trim((string) ($permissions['Mikrotik Server'] ?? 'none')));
    return $level === 'limited';
}

function mikrotik_scope_sql(array $user, string $alias = 'r', string $param = 'scope_employee_id'): array
{
    if (!is_mikrotik_limited($user)) {
        return ['sql' => '', 'params' => []];
    }

    $employeeId = (int) ($user['id'] ?? 0);
    if ($employeeId <= 0) {
        send_json(['ok' => false, 'message' => 'Forbidden: invalid employee scope'], 403);
    }

    return [
        'sql' => " AND {$alias}.uploaded_by_employee_id = :{$param}",
        'params' => [$param => $employeeId],
    ];
}

function ensure_client_bulk_columns(PDO $pdo): void
{
    if (!mikrotik_column_exists($pdo, 'clients', 'connection_username')) {
        $pdo->exec('ALTER TABLE clients ADD COLUMN connection_username VARCHAR(120) NULL AFTER email');
    }

    if (!mikrotik_column_exists($pdo, 'clients', 'connection_email')) {
        $pdo->exec('ALTER TABLE clients ADD COLUMN connection_email VARCHAR(180) NULL AFTER connection_username');
    }

    if (!mikrotik_column_exists($pdo, 'clients', 'connection_password_hash')) {
        $pdo->exec('ALTER TABLE clients ADD COLUMN connection_password_hash VARCHAR(255) NULL AFTER connection_email');
    }

    if (!mikrotik_column_exists($pdo, 'clients', 'created_by_employee_id')) {
        $pdo->exec('ALTER TABLE clients ADD COLUMN created_by_employee_id BIGINT UNSIGNED NULL AFTER package_id');
    }

    if (!mikrotik_column_exists($pdo, 'clients', 'assigned_to_employee_id')) {
        $pdo->exec('ALTER TABLE clients ADD COLUMN assigned_to_employee_id BIGINT UNSIGNED NULL AFTER created_by_employee_id');
    }
}

function generate_bulk_client_code(PDO $pdo): string
{
    $prefix = 'mic';

    $stmt = $pdo->query(
        "SELECT client_code
         FROM clients
         WHERE client_code REGEXP '^mic[0-9]{5}$'
         ORDER BY CAST(RIGHT(client_code, 5) AS UNSIGNED) DESC
         LIMIT 1"
    );

    $lastCode = (string) ($stmt->fetchColumn() ?: '');
    $next = 1;

    if ($lastCode !== '') {
        $next = (int) substr($lastCode, -5) + 1;
    }

    do {
        $candidate = $prefix . str_pad((string) $next, 5, '0', STR_PAD_LEFT);
        $check = $pdo->prepare('SELECT COUNT(*) FROM clients WHERE client_code = :code');
        $check->execute(['code' => $candidate]);
        $exists = (int) $check->fetchColumn() > 0;
        $next++;
    } while ($exists);

    return $candidate;
}

function resolve_or_create_package(PDO $pdo, string $packageName, float $monthlyBill): ?int
{
    $name = trim($packageName);
    if ($name === '') {
        return null;
    }

    $find = $pdo->prepare('SELECT id FROM internet_packages WHERE package_name = :name LIMIT 1');
    $find->execute(['name' => $name]);
    $id = $find->fetchColumn();
    if ($id !== false) {
        return (int) $id;
    }

    $speed = 10;
    $price = $monthlyBill > 0 ? $monthlyBill : 0.00;

    $insert = $pdo->prepare(
        'INSERT INTO internet_packages (package_name, speed_mbps, monthly_price, is_active)
         VALUES (:name, :speed, :price, 1)'
    );
    $insert->execute([
        'name' => $name,
        'speed' => $speed,
        'price' => $price,
    ]);

    return (int) $pdo->lastInsertId();
}
