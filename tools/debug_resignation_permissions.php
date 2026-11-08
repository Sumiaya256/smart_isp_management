<?php

declare(strict_types=1);

require __DIR__ . '/../backend/config/database.php';

$email = $argv[1] ?? 'naim.sarker.r893@promee.internet';
$action = $argv[2] ?? '';

$pdo = db();

$employeeStmt = $pdo->prepare(
    'SELECT e.id, e.employee_code, e.full_name, e.email, e.position_id, p.position_name, d.department_name
     FROM employees e
     LEFT JOIN positions p ON p.id = e.position_id
     LEFT JOIN departments d ON d.id = e.department_id
     WHERE e.email = :email
     LIMIT 1'
);
$employeeStmt->execute(['email' => $email]);
$employee = $employeeStmt->fetch(PDO::FETCH_ASSOC);

if (!$employee) {
    echo "employee_not_found\n";
    exit(1);
}

echo "employee\n";
echo json_encode($employee, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";

$permStmt = $pdo->prepare(
    'SELECT module_name, permission_level
     FROM position_access_modules
     WHERE position_id = :position_id
       AND module_name IN ("Resign Rule", "Resignation", "HR & Payroll")
     ORDER BY module_name'
);
$permStmt->execute(['position_id' => (int) $employee['position_id']]);
$permissions = $permStmt->fetchAll(PDO::FETCH_ASSOC);

echo "position_permissions\n";
echo json_encode($permissions, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";

$allPermissionTables = [];
$tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
foreach ($tables as $tableName) {
    $name = strtolower((string) $tableName);
    if (strpos($name, 'permission') !== false || strpos($name, 'access') !== false || strpos($name, 'role') !== false) {
        $allPermissionTables[] = $tableName;
    }
}

echo "permission_related_tables\n";
echo json_encode($allPermissionTables, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";

if ($action === 'cleanup') {
    $deleteStmt = $pdo->prepare('DELETE FROM hr_resignation_rules WHERE rule_name IN ("TEMP_TEST_RULE", "TEMP_TEST_RULE_2")');
    $deleteStmt->execute();
    echo "\ncleanup_deleted\n";
    echo (string) $deleteStmt->rowCount() . "\n";
}
