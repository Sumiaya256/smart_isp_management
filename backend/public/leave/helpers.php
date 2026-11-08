<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/database.php';
require_once dirname(__DIR__, 2) . '/config/auth.php';

function leave_column_exists(PDO $pdo, string $column): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*)
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name AND COLUMN_NAME = :column_name'
    );
    $stmt->execute([
        'table_name' => 'leave_requests',
        'column_name' => $column,
    ]);
    return (int) $stmt->fetchColumn() > 0;
}

function leave_table_exists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*)
         FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name'
    );
    $stmt->execute(['table_name' => $table]);
    return (int) $stmt->fetchColumn() > 0;
}

function leave_str_cut(string $value, int $max): string
{
    if ($max <= 0) {
        return '';
    }

    if (function_exists('mb_substr')) {
        return (string) mb_substr($value, 0, $max);
    }

    return substr($value, 0, $max);
}

function leave_resolve_employee_id(PDO $pdo, array $user, string $employeeCode = '', string $employeeName = ''): ?int
{
    if (!leave_table_exists($pdo, 'employees')) {
        return null;
    }

    $sessionEmployeeId = (int) ($user['id'] ?? 0);
    if ($sessionEmployeeId > 0) {
        $checkStmt = $pdo->prepare('SELECT id FROM employees WHERE id = :id LIMIT 1');
        $checkStmt->execute(['id' => $sessionEmployeeId]);
        $found = (int) ($checkStmt->fetchColumn() ?: 0);
        if ($found > 0) {
            return $found;
        }
    }

    $email = trim((string) ($user['email'] ?? ''));
    if ($email !== '') {
        $stmt = $pdo->prepare('SELECT id FROM employees WHERE email = :email LIMIT 1');
        $stmt->execute(['email' => $email]);
        $found = (int) ($stmt->fetchColumn() ?: 0);
        if ($found > 0) {
            return $found;
        }
    }

    $employeeCode = trim($employeeCode);
    if ($employeeCode !== '') {
        $stmt = $pdo->prepare('SELECT id FROM employees WHERE employee_code = :employee_code LIMIT 1');
        $stmt->execute(['employee_code' => $employeeCode]);
        $found = (int) ($stmt->fetchColumn() ?: 0);
        if ($found > 0) {
            return $found;
        }
    }

    $employeeName = trim($employeeName);
    if ($employeeName !== '') {
        $stmt = $pdo->prepare('SELECT id FROM employees WHERE full_name = :full_name ORDER BY id DESC LIMIT 1');
        $stmt->execute(['full_name' => $employeeName]);
        $found = (int) ($stmt->fetchColumn() ?: 0);
        if ($found > 0) {
            return $found;
        }
    }

    return null;
}

function ensure_leave_schema(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS leave_requests (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            employee_code VARCHAR(40) NOT NULL,
            employee_name VARCHAR(140) NOT NULL,
            department_name VARCHAR(100) DEFAULT NULL,
            leave_type VARCHAR(60) NOT NULL,
            start_date DATE NOT NULL,
            end_date DATE NOT NULL,
            total_days INT NOT NULL DEFAULT 1,
            reason_text TEXT DEFAULT NULL,
            applied_on DATE NOT NULL,
            status_label ENUM('pending','approved','rejected','cancelled') NOT NULL DEFAULT 'pending',
            reject_reason TEXT DEFAULT NULL,
            created_by_employee_id INT DEFAULT NULL,
            assigned_to_employee_id INT DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_leave_status (status_label),
            INDEX idx_leave_type (leave_type),
            INDEX idx_leave_start_date (start_date),
            INDEX idx_leave_created_by (created_by_employee_id),
            INDEX idx_leave_assigned_to (assigned_to_employee_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    // Backward-compatible migration for older schemas.
    if (!leave_column_exists($pdo, 'employee_code')) {
        $pdo->exec('ALTER TABLE leave_requests ADD COLUMN employee_code VARCHAR(40) NULL AFTER id');
    }
    if (!leave_column_exists($pdo, 'employee_name')) {
        $pdo->exec('ALTER TABLE leave_requests ADD COLUMN employee_name VARCHAR(140) NULL AFTER employee_code');
    }
    if (!leave_column_exists($pdo, 'department_name')) {
        $pdo->exec('ALTER TABLE leave_requests ADD COLUMN department_name VARCHAR(100) NULL AFTER employee_name');
    }
    if (!leave_column_exists($pdo, 'total_days')) {
        $pdo->exec('ALTER TABLE leave_requests ADD COLUMN total_days INT NOT NULL DEFAULT 1 AFTER end_date');
    }
    if (!leave_column_exists($pdo, 'reason_text')) {
        if (leave_column_exists($pdo, 'reason')) {
            $pdo->exec('ALTER TABLE leave_requests ADD COLUMN reason_text TEXT NULL AFTER total_days');
            $pdo->exec('UPDATE leave_requests SET reason_text = reason WHERE reason_text IS NULL AND reason IS NOT NULL');
        } else {
            $pdo->exec('ALTER TABLE leave_requests ADD COLUMN reason_text TEXT NULL AFTER total_days');
        }
    }
    if (!leave_column_exists($pdo, 'applied_on')) {
        $pdo->exec('ALTER TABLE leave_requests ADD COLUMN applied_on DATE NULL AFTER reason_text');
        if (leave_column_exists($pdo, 'created_at')) {
            $pdo->exec('UPDATE leave_requests SET applied_on = DATE(created_at) WHERE applied_on IS NULL');
        } else {
            $pdo->exec('UPDATE leave_requests SET applied_on = CURDATE() WHERE applied_on IS NULL');
        }
    }
    if (!leave_column_exists($pdo, 'status_label')) {
        $pdo->exec('ALTER TABLE leave_requests ADD COLUMN status_label ENUM("pending","approved","rejected","cancelled") NOT NULL DEFAULT "pending" AFTER applied_on');
        if (leave_column_exists($pdo, 'approval_status')) {
            $pdo->exec('UPDATE leave_requests SET status_label = CASE LOWER(TRIM(COALESCE(approval_status, "pending"))) WHEN "approved" THEN "approved" WHEN "rejected" THEN "rejected" ELSE "pending" END');
        }
    }
    if (!leave_column_exists($pdo, 'reject_reason')) {
        $pdo->exec('ALTER TABLE leave_requests ADD COLUMN reject_reason TEXT NULL AFTER status_label');
    }
    if (!leave_column_exists($pdo, 'created_by_employee_id')) {
        $pdo->exec('ALTER TABLE leave_requests ADD COLUMN created_by_employee_id INT NULL AFTER reject_reason');
        if (leave_column_exists($pdo, 'employee_id')) {
            $pdo->exec('UPDATE leave_requests SET created_by_employee_id = employee_id WHERE created_by_employee_id IS NULL');
        }
    }
    if (!leave_column_exists($pdo, 'assigned_to_employee_id')) {
        $pdo->exec('ALTER TABLE leave_requests ADD COLUMN assigned_to_employee_id INT NULL AFTER created_by_employee_id');
        if (leave_column_exists($pdo, 'employee_id')) {
            $pdo->exec('UPDATE leave_requests SET assigned_to_employee_id = employee_id WHERE assigned_to_employee_id IS NULL');
        }
    }

    if (leave_column_exists($pdo, 'employee_id') && leave_table_exists($pdo, 'employees')) {
        $pdo->exec(
            'UPDATE leave_requests lr
             INNER JOIN employees e ON e.id = lr.employee_id
             LEFT JOIN departments d ON d.id = e.department_id
             SET lr.employee_code = COALESCE(NULLIF(lr.employee_code, ""), e.employee_code),
                 lr.employee_name = COALESCE(NULLIF(lr.employee_name, ""), e.full_name),
                 lr.department_name = COALESCE(NULLIF(lr.department_name, ""), d.department_name)
             WHERE (lr.employee_code IS NULL OR lr.employee_code = "" OR lr.employee_name IS NULL OR lr.employee_name = "")'
        );
    }

    $pdo->exec('UPDATE leave_requests SET employee_code = COALESCE(NULLIF(employee_code, ""), CONCAT("EMP", LPAD(id, 3, "0")))');
    $pdo->exec('UPDATE leave_requests SET employee_name = COALESCE(NULLIF(employee_name, ""), "Employee")');
    $pdo->exec('UPDATE leave_requests SET total_days = CASE WHEN total_days IS NULL OR total_days < 1 THEN 1 ELSE total_days END');
    $pdo->exec('UPDATE leave_requests SET applied_on = COALESCE(applied_on, CURDATE())');

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS leave_attachments (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            leave_request_id BIGINT UNSIGNED NOT NULL,
            original_name VARCHAR(255) NOT NULL,
            stored_name VARCHAR(255) NOT NULL,
            file_path VARCHAR(500) NOT NULL,
            mime_type VARCHAR(120) NOT NULL,
            file_size BIGINT UNSIGNED NOT NULL DEFAULT 0,
            uploaded_by_employee_id BIGINT UNSIGNED NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_leave_attachments_request (leave_request_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
}

function leave_scope_sql(array $user, string $alias = 'l', string $param = 'scope_employee_id'): array
{
    if (!is_limited_module_permission($user, 'Leave Management')) {
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

function enforce_leave_scope(PDO $pdo, array $user, int $leaveId): void
{
    if (!is_limited_module_permission($user, 'Leave Management')) {
        return;
    }

    $employeeId = (int) ($user['id'] ?? 0);
    if ($employeeId <= 0) {
        send_json(['ok' => false, 'message' => 'Forbidden: invalid limited scope'], 403);
    }

    $stmt = $pdo->prepare(
        'SELECT id FROM leave_requests
         WHERE id = :id
           AND (created_by_employee_id = :employee_id OR assigned_to_employee_id = :employee_id)
         LIMIT 1'
    );
    $stmt->execute(['id' => $leaveId, 'employee_id' => $employeeId]);

    if (!$stmt->fetchColumn()) {
        send_json(['ok' => false, 'message' => 'Leave request access denied'], 403);
    }
}
