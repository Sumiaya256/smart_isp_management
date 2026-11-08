<?php

declare(strict_types=1);

require __DIR__ . '/../backend/config/database.php';
require __DIR__ . '/../backend/public/employees/access_matrix.php';

$pdo = db();
$allModules = available_access_modules();

$stmt = $pdo->query(
    'SELECT e.id AS employee_id, e.employee_code, e.position_id, ep.access_modules
     FROM employees e
     LEFT JOIN employee_profiles ep ON ep.employee_id = e.id
     ORDER BY e.id ASC'
);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$ok = 0;
$bad = 0;

foreach ($rows as $row) {
    $employeeId = (int) ($row['employee_id'] ?? 0);
    $employeeCode = (string) ($row['employee_code'] ?? '');
    $positionId = (int) ($row['position_id'] ?? 0);

    if ($employeeId <= 0 || $positionId <= 0) {
        continue;
    }

    $expected = [];
    $positionMap = load_position_module_permissions($pdo, $positionId);
    foreach ($allModules as $moduleName) {
        $expected[$moduleName] = (string) ($positionMap[$moduleName] ?? 'none');
    }

    $actual = json_decode((string) ($row['access_modules'] ?? ''), true);
    if (!is_array($actual)) {
        $actual = [];
    }

    $mismatch = false;
    foreach ($allModules as $moduleName) {
        $actualLevel = strtolower(trim((string) ($actual[$moduleName] ?? 'none')));
        $expectedLevel = strtolower(trim((string) ($expected[$moduleName] ?? 'none')));
        if ($actualLevel !== $expectedLevel) {
            $mismatch = true;
            break;
        }
    }

    if ($mismatch) {
        $bad++;
        echo 'mismatch=' . $employeeCode . ' position_id=' . $positionId . PHP_EOL;
    } else {
        $ok++;
    }
}

echo 'employees_checked=' . count($rows) . PHP_EOL;
echo 'matches=' . $ok . PHP_EOL;
echo 'mismatches=' . $bad . PHP_EOL;
