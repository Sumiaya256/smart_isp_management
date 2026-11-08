<?php

declare(strict_types=1);

function sql_in_placeholders(int $count): string
{
    return implode(',', array_fill(0, max(0, $count), '?'));
}

function delete_employees_by_ids(PDO $pdo, array $employeeIds): int
{
    $employeeIds = array_values(array_unique(array_filter(array_map('intval', $employeeIds), static fn ($id) => $id > 0)));
    if (empty($employeeIds)) {
        return 0;
    }

    $in = sql_in_placeholders(count($employeeIds));

    // Unassign assets from removed employees; keep asset records.
    $stmt = $pdo->prepare("UPDATE assets SET assigned_to_employee_id = NULL WHERE assigned_to_employee_id IN ($in)");
    $stmt->execute($employeeIds);

    $stmt = $pdo->prepare("DELETE FROM attendance WHERE employee_id IN ($in)");
    $stmt->execute($employeeIds);

    $stmt = $pdo->prepare("DELETE FROM leave_requests WHERE employee_id IN ($in)");
    $stmt->execute($employeeIds);

    $stmt = $pdo->prepare("DELETE FROM payroll_items WHERE employee_id IN ($in)");
    $stmt->execute($employeeIds);

    $stmt = $pdo->prepare("DELETE FROM employee_profiles WHERE employee_id IN ($in)");
    $stmt->execute($employeeIds);

    $stmt = $pdo->prepare("DELETE FROM employees WHERE id IN ($in)");
    $stmt->execute($employeeIds);

    return $stmt->rowCount();
}

function ensure_department_access_table(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS department_access_modules (
            department_id BIGINT UNSIGNED NOT NULL,
            module_name VARCHAR(120) NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (department_id, module_name),
            CONSTRAINT fk_dept_access_department FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE CASCADE
        ) ENGINE=InnoDB'
    );
}
