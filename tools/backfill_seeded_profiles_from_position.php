<?php

declare(strict_types=1);

require __DIR__ . '/../backend/config/database.php';

$pdo = db();

$empStmt = $pdo->query(
    "SELECT
        e.id AS employee_id,
        e.position_id,
        d.department_name,
        p.position_name
     FROM employees e
     LEFT JOIN departments d ON d.id = e.department_id
     LEFT JOIN positions p ON p.id = e.position_id
     WHERE e.employee_code LIKE 'EMP-R%'
     ORDER BY e.id ASC"
);
$employees = $empStmt->fetchAll(PDO::FETCH_ASSOC);

$permStmt = $pdo->prepare(
    'SELECT module_name, permission_level
     FROM position_access_modules
     WHERE position_id = :position_id
       AND permission_level <> "none"
     ORDER BY module_name ASC'
);

$updateStmt = $pdo->prepare(
    'UPDATE employee_profiles
     SET role_name = :role_name,
         designation_title = :designation_title,
         status_label = COALESCE(NULLIF(status_label, ""), "Active"),
         access_modules = :access_modules
     WHERE employee_id = :employee_id'
);

$updated = 0;

$pdo->beginTransaction();
try {
    foreach ($employees as $employee) {
        $employeeId = (int) ($employee['employee_id'] ?? 0);
        $positionId = (int) ($employee['position_id'] ?? 0);
        if ($employeeId <= 0 || $positionId <= 0) {
            continue;
        }

        $roleName = trim((string) ($employee['department_name'] ?? ''));
        if ($roleName === '') {
            $roleName = 'Employee';
        }

        $designationTitle = trim((string) ($employee['position_name'] ?? ''));

        $permStmt->execute(['position_id' => $positionId]);
        $rows = $permStmt->fetchAll(PDO::FETCH_ASSOC);

        $permissionMap = [];
        foreach ($rows as $row) {
            $module = trim((string) ($row['module_name'] ?? ''));
            $level = strtolower(trim((string) ($row['permission_level'] ?? 'none')));
            if ($module === '' || $level === 'none') {
                continue;
            }
            if ($level === 'limited') {
                $level = 'view';
            }
            if ($level !== 'full' && $level !== 'view') {
                $level = 'view';
            }
            $permissionMap[$module] = $level;
        }

        if (empty($permissionMap)) {
            $permissionMap['Dashboard'] = 'view';
        }

        $updateStmt->execute([
            'role_name' => $roleName,
            'designation_title' => $designationTitle,
            'access_modules' => json_encode($permissionMap, JSON_UNESCAPED_UNICODE),
            'employee_id' => $employeeId,
        ]);

        $updated += $updateStmt->rowCount() > 0 ? 1 : 0;
    }

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    throw $e;
}

echo 'profiles_processed=' . count($employees) . PHP_EOL;
echo 'profiles_updated=' . $updated . PHP_EOL;
