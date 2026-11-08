<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/backend/config/database.php';

$pdo = db();
$stmt = $pdo->prepare(
    'SELECT e.employee_code, e.full_name, e.email, d.department_name, p.position_name, ep.role_name
     FROM employees e
     LEFT JOIN departments d ON d.id = e.department_id
     LEFT JOIN positions p ON p.id = e.position_id
     LEFT JOIN employee_profiles ep ON ep.employee_id = e.id
     WHERE e.employee_code = :code
     LIMIT 1'
);
$stmt->execute(['code' => 'EMP-019']);
$row = $stmt->fetch();

if (!$row) {
    echo "NOT_FOUND\n";
    exit(1);
}

echo json_encode($row, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
