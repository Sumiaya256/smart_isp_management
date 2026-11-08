<?php

declare(strict_types=1);

require __DIR__ . '/../backend/config/database.php';
require __DIR__ . '/../backend/public/employees/access_matrix.php';

$pdo = db();
$allModules = available_access_modules();

$employeesStmt = $pdo->query(
    'SELECT e.id AS employee_id, e.position_id
     FROM employees e
     ORDER BY e.id ASC'
);
$employees = $employeesStmt->fetchAll(PDO::FETCH_ASSOC);

$permStmt = $pdo->prepare(
    'SELECT module_name, permission_level
     FROM position_access_modules
     WHERE position_id = :position_id
     ORDER BY module_name ASC'
);

$upsertStmt = $pdo->prepare(
    'INSERT INTO employee_profiles (employee_id, access_modules)
     VALUES (:employee_id, :access_modules)
     ON DUPLICATE KEY UPDATE access_modules = VALUES(access_modules)'
);

$processed = 0;
$updated = 0;

$pdo->beginTransaction();
try {
    foreach ($employees as $employee) {
        $employeeId = (int) ($employee['employee_id'] ?? 0);
        $positionId = (int) ($employee['position_id'] ?? 0);
        if ($employeeId <= 0 || $positionId <= 0) {
            continue;
        }

        $map = [];
        foreach ($allModules as $moduleName) {
            $map[$moduleName] = 'none';
        }

        $permStmt->execute(['position_id' => $positionId]);
        $rows = $permStmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as $row) {
            $moduleName = trim((string) ($row['module_name'] ?? ''));
            if ($moduleName === '' || !array_key_exists($moduleName, $map)) {
                continue;
            }

            $level = normalize_permission_level((string) ($row['permission_level'] ?? 'none'));
            if ($level === 'limited') {
                $level = 'view';
            }
            if ($level !== 'full' && $level !== 'view' && $level !== 'none') {
                $level = 'none';
            }

            $map[$moduleName] = $level;
        }

        $upsertStmt->execute([
            'employee_id' => $employeeId,
            'access_modules' => json_encode($map, JSON_UNESCAPED_UNICODE),
        ]);

        $processed++;
        $updated += $upsertStmt->rowCount() > 0 ? 1 : 0;
    }

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    throw $e;
}

echo 'employees_processed=' . $processed . PHP_EOL;
echo 'profiles_written=' . $updated . PHP_EOL;
echo 'permission_keys=' . count($allModules) . PHP_EOL;
