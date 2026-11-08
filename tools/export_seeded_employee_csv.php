<?php

declare(strict_types=1);

require __DIR__ . '/../backend/config/database.php';
require __DIR__ . '/../backend/public/employees/access_matrix.php';

$pdo = db();
$allModules = available_access_modules();

$csvPath = __DIR__ . '/../employee_role_access_seed_3_per_role.csv';
$handle = fopen($csvPath, 'w');
if ($handle === false) {
    fwrite(STDERR, "Failed to open CSV for writing\n");
    exit(1);
}

fputcsv($handle, [
    'Employee Code',
    'Full Name',
    'Role',
    'Department',
    'Position ID',
    'Email',
    'Password',
    'Phone',
    'NID',
    'Join Date',
    'Access Modules',
    'Features (Permission Matrix)',
]);

$empStmt = $pdo->query(
    "SELECT
        e.id,
        e.employee_code,
        e.full_name,
        e.email,
        e.phone,
        e.join_date,
        e.position_id,
        p.position_name,
        d.department_name,
        ep.nid
     FROM employees e
     INNER JOIN positions p ON p.id = e.position_id
     INNER JOIN departments d ON d.id = e.department_id
     LEFT JOIN employee_profiles ep ON ep.employee_id = e.id
     WHERE e.employee_code LIKE 'EMP-R%'
     ORDER BY e.position_id ASC, e.employee_code ASC"
);
$employees = $empStmt->fetchAll(PDO::FETCH_ASSOC);

$permStmt = $pdo->prepare(
    'SELECT module_name, permission_level
     FROM position_access_modules
    WHERE position_id = :position_id
     ORDER BY module_name ASC'
);

$total = 0;
foreach ($employees as $employee) {
    $positionId = (int) ($employee['position_id'] ?? 0);

    $permStmt->execute(['position_id' => $positionId]);
    $permissions = $permStmt->fetchAll(PDO::FETCH_ASSOC);

    $permissionMap = [];
    foreach ($allModules as $moduleName) {
        $permissionMap[$moduleName] = 'none';
    }

    foreach ($permissions as $permission) {
        $moduleName = trim((string) ($permission['module_name'] ?? ''));
        if ($moduleName === '' || !array_key_exists($moduleName, $permissionMap)) {
            continue;
        }
        $level = normalize_permission_level((string) ($permission['permission_level'] ?? 'none'));
        if ($level === 'limited') {
            $level = 'view';
        }
        $permissionMap[$moduleName] = $level;
    }

    $accessModules = [];
    $featureMatrix = [];
    foreach ($allModules as $moduleName) {
        $level = $permissionMap[$moduleName] ?? 'none';
        if ($level === 'full' || $level === 'view') {
            $accessModules[] = $moduleName;
        }

        $displayLevel = $level === 'none' ? 'No Access' : $level;
        $featureMatrix[] = $moduleName . ' (' . $displayLevel . ')';
    }

    fputcsv($handle, [
        (string) ($employee['employee_code'] ?? ''),
        (string) ($employee['full_name'] ?? ''),
        (string) ($employee['position_name'] ?? ''),
        (string) ($employee['department_name'] ?? ''),
        $positionId,
        (string) ($employee['email'] ?? ''),
        '123456',
        (string) ($employee['phone'] ?? ''),
        (string) ($employee['nid'] ?? ''),
        (string) ($employee['join_date'] ?? ''),
        implode(', ', $accessModules),
        implode('; ', $featureMatrix),
    ]);

    $total++;
}

fclose($handle);

echo 'Exported rows: ' . $total . PHP_EOL;
echo 'CSV: employee_role_access_seed_3_per_role.csv' . PHP_EOL;
