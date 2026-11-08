<?php

declare(strict_types=1);

require __DIR__ . '/../backend/config/database.php';
require __DIR__ . '/../backend/public/employees/access_matrix.php';

$pdo = db();

$stmt = $pdo->prepare(
    'SELECT
        e.id,
        e.employee_code,
        e.full_name,
        e.position_id,
        p.position_name,
        d.department_name,
        ep.access_modules
     FROM employees e
     LEFT JOIN positions p ON p.id = e.position_id
     LEFT JOIN departments d ON d.id = e.department_id
     LEFT JOIN employee_profiles ep ON ep.employee_id = e.id
     WHERE e.id = :id
     LIMIT 1'
);
$stmt->execute(['id' => 59]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$row) {
    echo "employee_59_not_found\n";
    exit(0);
}

echo 'id=' . (string) ($row['id'] ?? '') . PHP_EOL;
echo 'employee_code=' . (string) ($row['employee_code'] ?? '') . PHP_EOL;
echo 'name=' . (string) ($row['full_name'] ?? '') . PHP_EOL;
echo 'position_id=' . (string) ($row['position_id'] ?? '') . PHP_EOL;
echo 'position_name=' . (string) ($row['position_name'] ?? '') . PHP_EOL;
echo 'department_name=' . (string) ($row['department_name'] ?? '') . PHP_EOL;

$raw = (string) ($row['access_modules'] ?? '');
echo 'access_modules_raw=' . $raw . PHP_EOL;

$map = json_decode($raw, true);
echo 'access_modules_json_valid=' . (is_array($map) ? 'yes' : 'no') . PHP_EOL;
echo 'access_modules_keys=' . (is_array($map) ? count($map) : 0) . PHP_EOL;

echo 'available_access_modules=' . count(available_access_modules()) . PHP_EOL;
