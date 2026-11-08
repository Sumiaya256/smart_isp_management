<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/database.php';
require_once dirname(__DIR__, 2) . '/config/auth.php';

function events_str_cut(string $value, int $max): string
{
    if ($max <= 0) {
        return '';
    }

    if (function_exists('mb_substr')) {
        return (string) mb_substr($value, 0, $max);
    }

    return substr($value, 0, $max);
}

function ensure_events_schema(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS events_holidays (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(180) NOT NULL,
            event_type ENUM('event','holiday') NOT NULL DEFAULT 'event',
            event_date DATE NOT NULL,
            description_text TEXT DEFAULT NULL,
            created_by_employee_id INT DEFAULT NULL,
            assigned_to_employee_id INT DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_events_type (event_type),
            INDEX idx_events_date (event_date),
            INDEX idx_events_created_by (created_by_employee_id),
            INDEX idx_events_assigned_to (assigned_to_employee_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
}

function events_scope_sql(array $user, string $alias = 'e', string $param = 'scope_employee_id'): array
{
    if (!is_limited_module_permission($user, 'Events & Holidays')) {
        return ['sql' => '', 'params' => []];
    }

    $employeeId = (int) ($user['id'] ?? 0);
    if ($employeeId <= 0) {
        return ['sql' => ' AND 1=0', 'params' => []];
    }

    return [
        'sql' => " AND (({$alias}.created_by_employee_id = :{$param} OR {$alias}.assigned_to_employee_id = :{$param}) OR ({$alias}.created_by_employee_id IS NULL AND {$alias}.assigned_to_employee_id IS NULL))",
        'params' => [$param => $employeeId],
    ];
}

function enforce_events_scope(PDO $pdo, array $user, int $eventId): void
{
    if (!is_limited_module_permission($user, 'Events & Holidays')) {
        return;
    }

    $employeeId = (int) ($user['id'] ?? 0);
    if ($employeeId <= 0) {
        send_json(['ok' => false, 'message' => 'Forbidden: invalid limited scope'], 403);
    }

    $stmt = $pdo->prepare(
        'SELECT id FROM events_holidays
         WHERE id = :id
           AND (created_by_employee_id = :employee_id OR assigned_to_employee_id = :employee_id)
         LIMIT 1'
    );
    $stmt->execute(['id' => $eventId, 'employee_id' => $employeeId]);

    if (!$stmt->fetchColumn()) {
        send_json(['ok' => false, 'message' => 'Event access denied'], 403);
    }
}
