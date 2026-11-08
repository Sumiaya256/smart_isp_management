<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/database.php';
require_once dirname(__DIR__, 2) . '/config/auth.php';

function attendance_table_exists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*)
         FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name'
    );
    $stmt->execute(['table_name' => $table]);
    return (int) $stmt->fetchColumn() > 0;
}

function attendance_column_exists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*)
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name AND COLUMN_NAME = :column_name'
    );
    $stmt->execute([
        'table_name' => $table,
        'column_name' => $column,
    ]);
    return (int) $stmt->fetchColumn() > 0;
}

function ensure_attendance_schema(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS attendance (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            employee_id BIGINT UNSIGNED NOT NULL,
            attendance_date DATE NOT NULL,
            status ENUM('present','absent','leave') NOT NULL DEFAULT 'present',
            remarks VARCHAR(255) NULL,
            check_in_at DATETIME NULL,
            check_out_at DATETIME NULL,
            source_label VARCHAR(30) NOT NULL DEFAULT 'manual',
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_attendance_employee_date (employee_id, attendance_date),
            INDEX idx_attendance_date (attendance_date),
            INDEX idx_attendance_employee (employee_id),
            CONSTRAINT fk_attendance_employee FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    if (!attendance_column_exists($pdo, 'attendance', 'check_in_at')) {
        $pdo->exec('ALTER TABLE attendance ADD COLUMN check_in_at DATETIME NULL AFTER remarks');
    }
    if (!attendance_column_exists($pdo, 'attendance', 'check_out_at')) {
        $pdo->exec('ALTER TABLE attendance ADD COLUMN check_out_at DATETIME NULL AFTER check_in_at');
    }
    if (!attendance_column_exists($pdo, 'attendance', 'source_label')) {
        $pdo->exec("ALTER TABLE attendance ADD COLUMN source_label VARCHAR(30) NOT NULL DEFAULT 'manual' AFTER check_out_at");
    }
    if (!attendance_column_exists($pdo, 'attendance', 'updated_at')) {
        $pdo->exec('ALTER TABLE attendance ADD COLUMN updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP');
    }

    // Backfill old rows where check-in/check-out were not captured historically.
    $pdo->exec('UPDATE attendance SET check_in_at = COALESCE(check_in_at, CONCAT(attendance_date, " 09:00:00")) WHERE status = "present" AND check_in_at IS NULL');
}

function attendance_resolve_employee_id(PDO $pdo, array $user): ?int
{
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

    $fullName = trim((string) ($user['full_name'] ?? ''));
    if ($fullName !== '') {
        $stmt = $pdo->prepare('SELECT id FROM employees WHERE full_name = :full_name ORDER BY id DESC LIMIT 1');
        $stmt->execute(['full_name' => $fullName]);
        $found = (int) ($stmt->fetchColumn() ?: 0);
        if ($found > 0) {
            return $found;
        }
    }

    return null;
}

function attendance_format_time(?string $value): ?string
{
    if (!$value) {
        return null;
    }
    return date('H:i:s', strtotime($value));
}

function attendance_minutes_between(?string $checkInAt, ?string $checkOutAt): int
{
    if (!$checkInAt || !$checkOutAt) {
        return 0;
    }

    $in = strtotime($checkInAt);
    $out = strtotime($checkOutAt);
    if ($in === false || $out === false || $out <= $in) {
        return 0;
    }

    return (int) floor(($out - $in) / 60);
}
