<?php

declare(strict_types=1);

require __DIR__ . '/../backend/config/database.php';
require __DIR__ . '/../backend/public/employees/access_matrix.php';

$pdo = db();
$allModules = available_access_modules();

$employeesStmt = $pdo->query(
    "SELECT e.id, e.employee_code, e.position_id, e.email
     FROM employees e
     WHERE e.employee_code LIKE 'EMP-R%'
     ORDER BY e.id ASC"
);
$employees = $employeesStmt->fetchAll(PDO::FETCH_ASSOC);

$permStmt = $pdo->prepare(
    'SELECT module_name, permission_level
     FROM position_access_modules
     WHERE position_id = :position_id'
);

$upsertProfile = $pdo->prepare(
    'INSERT INTO employee_profiles (employee_id, access_modules)
     VALUES (:employee_id, :access_modules)
     ON DUPLICATE KEY UPDATE access_modules = VALUES(access_modules)'
);

$updated = 0;
$total = 0;

$pdo->beginTransaction();
try {
    foreach ($employees as $employee) {
        $employeeId = (int) ($employee['id'] ?? 0);
        $positionId = (int) ($employee['position_id'] ?? 0);
        if ($employeeId <= 0 || $positionId <= 0) {
            continue;
        }

        $permStmt->execute(['position_id' => $positionId]);
        $rows = $permStmt->fetchAll(PDO::FETCH_ASSOC);

        $matrix = [];
        foreach ($allModules as $moduleName) {
            $matrix[$moduleName] = 'none';
        }

        foreach ($rows as $row) {
            $moduleName = trim((string) ($row['module_name'] ?? ''));
            if ($moduleName === '' || !array_key_exists($moduleName, $matrix)) {
                continue;
            }

            $level = normalize_permission_level((string) ($row['permission_level'] ?? 'none'));
            if ($level === 'limited') {
                $level = 'view';
            }
            $matrix[$moduleName] = $level;
        }

        $upsertProfile->execute([
            'employee_id' => $employeeId,
            'access_modules' => json_encode($matrix, JSON_UNESCAPED_UNICODE),
        ]);

        $updated += $upsertProfile->rowCount() > 0 ? 1 : 0;
        $total++;
    }

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    throw $e;
}

echo 'dummy_employees_total=' . $total . PHP_EOL;
echo 'profiles_written=' . $updated . PHP_EOL;

$karimEmail = 'karim.islam.r842@promee.internet';
$karimStmt = $pdo->prepare(
    'SELECT e.employee_code, ep.access_modules
     FROM employees e
     INNER JOIN employee_profiles ep ON ep.employee_id = e.id
     WHERE e.email = :email
     LIMIT 1'
);
$karimStmt->execute(['email' => $karimEmail]);
$karim = $karimStmt->fetch(PDO::FETCH_ASSOC);

if ($karim) {
    $map = json_decode((string) ($karim['access_modules'] ?? ''), true);
    if (!is_array($map)) {
        $map = [];
    }

    $missing = [];
    foreach ($allModules as $moduleName) {
        if (!array_key_exists($moduleName, $map)) {
            $missing[] = $moduleName;
        }
    }

    echo 'karim_employee_code=' . (string) ($karim['employee_code'] ?? '') . PHP_EOL;
    echo 'karim_permission_keys=' . count($map) . PHP_EOL;
    echo 'karim_missing_keys=' . count($missing) . PHP_EOL;
}
