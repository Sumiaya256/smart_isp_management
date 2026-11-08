<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/database.php';
require_once dirname(__DIR__, 2) . '/config/auth.php';

function ensure_client_portal_settings_table(PDO $pdo): void
{
    try {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS client_portal_settings (
                setting_key VARCHAR(80) NOT NULL PRIMARY KEY,
                setting_value TEXT NULL,
                updated_by_employee_id BIGINT UNSIGNED NULL,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    } catch (Throwable $e) {
        // Best-effort bootstrap; the page should still load if the table cannot be created immediately.
    }
}

function ensure_client_portal_tracking_columns(PDO $pdo): void
{
    try {
        ensure_client_portal_auth_columns($pdo);

        if (!column_exists($pdo, 'clients', 'portal_last_login_at')) {
            $pdo->exec('ALTER TABLE clients ADD COLUMN portal_last_login_at DATETIME NULL AFTER connection_password_hash');
        }

        if (!column_exists($pdo, 'clients', 'portal_login_count')) {
            $pdo->exec('ALTER TABLE clients ADD COLUMN portal_login_count INT NOT NULL DEFAULT 0 AFTER portal_last_login_at');
        }
    } catch (Throwable $e) {
        // Ignore bootstrap failures so the dashboard can still render.
    }
}

function ensure_client_portal_auth_columns(PDO $pdo): void
{
    try {
        if (!column_exists($pdo, 'clients', 'connection_email')) {
            $pdo->exec('ALTER TABLE clients ADD COLUMN connection_email VARCHAR(120) NULL AFTER email');
        }

        if (!column_exists($pdo, 'clients', 'connection_password_hash')) {
            $pdo->exec('ALTER TABLE clients ADD COLUMN connection_password_hash VARCHAR(255) NULL AFTER connection_email');
        }
    } catch (Throwable $e) {
        // Ignore bootstrap failures so action endpoints can still return actionable errors.
    }
}

function read_client_portal_settings(PDO $pdo): array
{
    ensure_client_portal_settings_table($pdo);

    try {
        $rows = $pdo->query('SELECT setting_key, setting_value FROM client_portal_settings ORDER BY setting_key ASC')->fetchAll();
    } catch (Throwable $e) {
        return [];
    }

    $settings = [];
    foreach ($rows as $row) {
        $settings[(string) $row['setting_key']] = (string) ($row['setting_value'] ?? '');
    }
    return $settings;
}

function upsert_client_portal_settings(PDO $pdo, array $settings, int $employeeId): void
{
    ensure_client_portal_settings_table($pdo);
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO client_portal_settings (setting_key, setting_value, updated_by_employee_id)
             VALUES (:setting_key, :setting_value, :updated_by_employee_id)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_by_employee_id = VALUES(updated_by_employee_id)'
        );
        foreach ($settings as $key => $value) {
            $stmt->execute([
                'setting_key' => (string) $key,
                'setting_value' => (string) $value,
                'updated_by_employee_id' => $employeeId > 0 ? $employeeId : null,
            ]);
        }
    } catch (Throwable $e) {
        throw $e;
    }
}

function normalize_bool_setting($value): string
{
    return filter_var($value, FILTER_VALIDATE_BOOLEAN) ? '1' : '0';
}
