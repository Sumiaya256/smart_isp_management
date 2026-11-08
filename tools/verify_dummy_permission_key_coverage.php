<?php

declare(strict_types=1);

require __DIR__ . '/../backend/config/database.php';
require __DIR__ . '/../backend/public/employees/access_matrix.php';

$pdo = db();
$expected = count(available_access_modules());

$stmt = $pdo->query(
    "SELECT e.employee_code, ep.access_modules
     FROM employee_profiles ep
     INNER JOIN employees e ON e.id = ep.employee_id
     WHERE e.employee_code LIKE 'EMP-R%'
     ORDER BY e.employee_code ASC"
);

$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
$ok = 0;
$bad = 0;

foreach ($rows as $row) {
    $code = (string) ($row['employee_code'] ?? '');
    $map = json_decode((string) ($row['access_modules'] ?? ''), true);
    if (is_array($map) && count($map) === $expected) {
        $ok++;
        continue;
    }

    $bad++;
    $actual = is_array($map) ? count($map) : 0;
    echo 'mismatch=' . $code . ' actual_keys=' . $actual . PHP_EOL;
}

echo 'expected_keys=' . $expected . PHP_EOL;
echo 'dummy_profiles=' . count($rows) . PHP_EOL;
echo 'ok=' . $ok . PHP_EOL;
echo 'bad=' . $bad . PHP_EOL;
