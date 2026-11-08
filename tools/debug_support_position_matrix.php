<?php

declare(strict_types=1);

require __DIR__ . '/../backend/config/database.php';

$pdo = db();

echo "== Support Positions ==" . PHP_EOL;
$positions = $pdo->query(
    "SELECT d.id AS dept_id, d.department_name, p.id AS position_id, p.position_name
     FROM positions p
     INNER JOIN departments d ON d.id = p.department_id
     WHERE d.department_name LIKE '%Support%'
     ORDER BY p.id ASC"
)->fetchAll(PDO::FETCH_ASSOC);

foreach ($positions as $row) {
    echo sprintf(
        'dept_id=%d dept=%s position_id=%d position=%s',
        (int) ($row['dept_id'] ?? 0),
        (string) ($row['department_name'] ?? ''),
        (int) ($row['position_id'] ?? 0),
        (string) ($row['position_name'] ?? '')
    ) . PHP_EOL;
}

echo PHP_EOL . "== Support Position Permission Summary ==" . PHP_EOL;
$summary = $pdo->query(
    "SELECT p.id AS position_id, p.position_name,
            SUM(CASE WHEN pam.permission_level='full' THEN 1 ELSE 0 END) AS full_count,
            SUM(CASE WHEN pam.permission_level='view' THEN 1 ELSE 0 END) AS view_count,
            SUM(CASE WHEN pam.permission_level='limited' THEN 1 ELSE 0 END) AS limited_count,
            SUM(CASE WHEN pam.permission_level='none' THEN 1 ELSE 0 END) AS none_count,
            COUNT(*) AS total
     FROM position_access_modules pam
     INNER JOIN positions p ON p.id = pam.position_id
     INNER JOIN departments d ON d.id = p.department_id
     WHERE d.department_name LIKE '%Support%'
     GROUP BY p.id, p.position_name
     ORDER BY p.id ASC"
)->fetchAll(PDO::FETCH_ASSOC);

foreach ($summary as $row) {
    echo sprintf(
        'position_id=%d position=%s full=%d view=%d limited=%d none=%d total=%d',
        (int) ($row['position_id'] ?? 0),
        (string) ($row['position_name'] ?? ''),
        (int) ($row['full_count'] ?? 0),
        (int) ($row['view_count'] ?? 0),
        (int) ($row['limited_count'] ?? 0),
        (int) ($row['none_count'] ?? 0),
        (int) ($row['total'] ?? 0)
    ) . PHP_EOL;
}

echo PHP_EOL . "== Recent Employees ==" . PHP_EOL;
$employees = $pdo->query(
    "SELECT e.id, e.employee_code, e.full_name, d.department_name, p.position_name
     FROM employees e
     LEFT JOIN departments d ON d.id = e.department_id
     LEFT JOIN positions p ON p.id = e.position_id
     ORDER BY e.id DESC
     LIMIT 12"
)->fetchAll(PDO::FETCH_ASSOC);

foreach ($employees as $row) {
    echo sprintf(
        'id=%d code=%s name=%s dept=%s position=%s',
        (int) ($row['id'] ?? 0),
        (string) ($row['employee_code'] ?? ''),
        (string) ($row['full_name'] ?? ''),
        (string) ($row['department_name'] ?? ''),
        (string) ($row['position_name'] ?? '')
    ) . PHP_EOL;
}
