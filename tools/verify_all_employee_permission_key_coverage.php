<?php

declare(strict_types=1);

require __DIR__ . '/../backend/config/database.php';
require __DIR__ . '/../backend/public/employees/access_matrix.php';

$pdo = db();
$expected = count(available_access_modules());

$stmt = $pdo->query(
    'SELECT e.id, e.employee_code, ep.access_modules
     FROM employees e
     LEFT JOIN employee_profiles ep ON ep.employee_id = e.id
     ORDER BY e.id ASC'
);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$ok = 0;
$bad = 0;

foreach ($rows as $row) {
    $map = json_decode((string) ($row['access_modules'] ?? ''), true);
    if (is_array($map) && count($map) === $expected) {
        $ok++;
        continue;
    }

    $bad++;
    $code = (string) ($row['employee_code'] ?? '');
    $actual = is_array($map) ? count($map) : 0;
    echo 'mismatch=' . $code . ' keys=' . $actual . PHP_EOL;
}

echo 'expected_keys=' . $expected . PHP_EOL;
echo 'employees_total=' . count($rows) . PHP_EOL;
echo 'ok=' . $ok . PHP_EOL;
echo 'bad=' . $bad . PHP_EOL;
