<?php

declare(strict_types=1);

require __DIR__ . '/../backend/config/database.php';

$email = $argv[1] ?? 'mim.rahman.r843@promee.internet';

$hrPages = [
    'Employee List',
    'Add Employee',
    'Salary Sheet',
    'Department',
    'Position',
    'Payhead',
    'Payroll',
    'Resign Rule',
    'Resignation',
    'Internet Packages',
    'HR & Payroll',
];

$pdo = db();
$stmt = $pdo->prepare(
    'SELECT e.id, e.employee_code, e.position_id, p.position_name
     FROM employees e
     LEFT JOIN positions p ON p.id = e.position_id
     WHERE e.email = :email
     LIMIT 1'
);
$stmt->execute(['email' => $email]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$user) {
    echo "user_not_found\n";
    exit(1);
}

echo json_encode($user, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;

$permStmt = $pdo->prepare(
    'SELECT module_name, permission_level
     FROM position_access_modules
     WHERE position_id = :position_id
       AND module_name = :module_name
     LIMIT 1'
);

foreach ($hrPages as $page) {
    $permStmt->execute([
        'position_id' => (int) $user['position_id'],
        'module_name' => $page,
    ]);
    $row = $permStmt->fetch(PDO::FETCH_ASSOC);
    $level = $row ? (string) $row['permission_level'] : 'none';
    echo $page . '=' . $level . PHP_EOL;
}
