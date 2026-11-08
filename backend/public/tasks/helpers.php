<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/database.php';
require_once dirname(__DIR__, 2) . '/config/auth.php';

function tasks_str_cut(string $value, int $max): string
{
    if ($max <= 0) {
        return '';
    }

    if (function_exists('mb_substr')) {
        return (string) mb_substr($value, 0, $max);
    }

    return substr($value, 0, $max);
}

function ensure_tasks_schema(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS task_items (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            task_code VARCHAR(30) NOT NULL UNIQUE,
            title VARCHAR(220) NOT NULL,
            category_name VARCHAR(80) NOT NULL,
            assignee_name VARCHAR(120) NOT NULL,
            priority_label ENUM('Critical','High','Medium','Low') NOT NULL DEFAULT 'Medium',
            status_label ENUM('Pending','In Progress','On Hold','Completed','Overdue') NOT NULL DEFAULT 'Pending',
            progress_percent TINYINT UNSIGNED NOT NULL DEFAULT 0,
            due_date DATE NOT NULL,
            reference_code VARCHAR(80) NULL,
            description_text TEXT NULL,
            created_by_name VARCHAR(120) NULL,
            created_by_employee_id BIGINT UNSIGNED NULL,
            assigned_to_employee_id BIGINT UNSIGNED NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_task_status (status_label),
            INDEX idx_task_priority (priority_label),
            INDEX idx_task_due (due_date),
            INDEX idx_task_assignee (assignee_name),
            INDEX idx_task_creator (created_by_employee_id),
            INDEX idx_task_assigned_employee (assigned_to_employee_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
}

function tasks_scope_sql(array $user, string $alias = 't', string $param = 'scope_employee_id'): array
{
    if (!is_limited_module_permission($user, 'Task Management')) {
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

function enforce_task_scope(PDO $pdo, array $user, int $taskId): void
{
    if (!is_limited_module_permission($user, 'Task Management')) {
        return;
    }

    $employeeId = (int) ($user['id'] ?? 0);
    if ($employeeId <= 0) {
        send_json(['ok' => false, 'message' => 'Forbidden: invalid limited scope'], 403);
    }

    $stmt = $pdo->prepare(
        'SELECT id FROM task_items
         WHERE id = :id
           AND (created_by_employee_id = :employee_id OR assigned_to_employee_id = :employee_id)
         LIMIT 1'
    );
    $stmt->execute(['id' => $taskId, 'employee_id' => $employeeId]);

    if (!$stmt->fetchColumn()) {
        send_json(['ok' => false, 'message' => 'Task access denied'], 403);
    }
}

function next_task_code(PDO $pdo): string
{
    $stmt = $pdo->query('SELECT task_code FROM task_items ORDER BY id DESC LIMIT 1');
    $last = (string) ($stmt->fetchColumn() ?: '');
    if (!preg_match('/TSK-(\d+)/', $last, $m)) {
        return 'TSK-001';
    }

    $next = (int) $m[1] + 1;
    return 'TSK-' . str_pad((string) $next, 3, '0', STR_PAD_LEFT);
}
