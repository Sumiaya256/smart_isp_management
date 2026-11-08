<?php

declare(strict_types=1);

require __DIR__ . '/../backend/config/database.php';
require __DIR__ . '/../backend/public/employees/access_matrix.php';

$pdo = db();

$emails = [
    'mim.rahman.r843@promee.internet',
    'naim.sarker.r893@promee.internet',
    'karim.islam.r851@promee.internet',
    'sohan.rahman.r771@promee.internet',
    'hasib.chowdhury.r781@promee.internet',
];

$sql = 'SELECT e.email, e.position_id, p.position_name
        FROM employees e
        LEFT JOIN positions p ON p.id = e.position_id
        WHERE e.email = :email
        LIMIT 1';
$stmt = $pdo->prepare($sql);

foreach ($emails as $email) {
    $stmt->execute(['email' => $email]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        echo $email . ' => not_found' . PHP_EOL;
        continue;
    }

    $permissions = load_position_module_permissions($pdo, (int) $row['position_id']);
    $resignRule = $permissions['Resign Rule'] ?? 'none';
    $resignation = $permissions['Resignation'] ?? 'none';

    echo $email . ' => position=' . ($row['position_name'] ?? '')
        . ', resign_rule=' . $resignRule
        . ', resignation=' . $resignation
        . PHP_EOL;
}
