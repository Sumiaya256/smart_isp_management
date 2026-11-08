<?php

declare(strict_types=1);

require __DIR__ . '/../backend/config/database.php';
require __DIR__ . '/../backend/public/employees/access_matrix.php';

$employeeId = isset($argv[1]) ? (int) $argv[1] : 0;
if ($employeeId <= 0) {
    fwrite(STDERR, "Usage: php tools/inspect_employee.php <employee_id>\n");
    exit(1);
}

$pdo = db();

$stmt = $pdo->prepare(
    'SELECT
        e.id,
        e.employee_code,
        e.full_name,
        e.email,
        e.position_id,
        p.position_name,
        d.department_name,
        ep.role_name,
        ep.designation_title,
        ep.status_label,
        ep.access_modules
     FROM employees e
     LEFT JOIN positions p ON p.id = e.position_id
     LEFT JOIN departments d ON d.id = e.department_id
     LEFT JOIN employee_profiles ep ON ep.employee_id = e.id
     WHERE e.id = :id
     LIMIT 1'
);
$stmt->execute(['id' => $employeeId]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$row) {
    echo 'employee_not_found=' . $employeeId . PHP_EOL;
    exit(0);
}

$fields = [
    'id',
    'employee_code',
    'full_name',
    'email',
    'position_id',
    'position_name',
    'department_name',
    'role_name',
    'designation_title',
    'status_label',
];

foreach ($fields as $field) {
    echo $field . '=' . (string) ($row[$field] ?? '') . PHP_EOL;
}

$raw = (string) ($row['access_modules'] ?? '');
echo 'access_modules_raw=' . $raw . PHP_EOL;

$map = json_decode($raw, true);
echo 'access_modules_json_valid=' . (is_array($map) ? 'yes' : 'no') . PHP_EOL;
echo 'access_modules_keys=' . (is_array($map) ? count($map) : 0) . PHP_EOL;
echo 'available_access_modules=' . count(available_access_modules()) . PHP_EOL;

if (is_array($map)) {
    $sampleKeys = [
        'Dashboard',
        'Client',
        'Add New Client',
        'Mikrotik Server',
        'Support & Ticketing',
        'Income',
    ];
    foreach ($sampleKeys as $key) {
        echo 'perm_' . $key . '=' . (string) ($map[$key] ?? 'missing') . PHP_EOL;
    }
}
