<?php

declare(strict_types=1);

require __DIR__ . '/../backend/config/database.php';

$pdo = db();

$countStmt = $pdo->query(
    "SELECT COUNT(*) AS c
     FROM employee_profiles ep
     INNER JOIN employees e ON e.id = ep.employee_id
     WHERE e.employee_code LIKE 'EMP-R%'
       AND ep.access_modules IS NOT NULL"
);
$before = (int) ($countStmt->fetch(PDO::FETCH_ASSOC)['c'] ?? 0);

echo 'seeded_overrides_before=' . $before . PHP_EOL;

if ($before > 0) {
    $update = $pdo->prepare(
        "UPDATE employee_profiles ep
         INNER JOIN employees e ON e.id = ep.employee_id
         SET ep.access_modules = NULL
         WHERE e.employee_code LIKE 'EMP-R%'
           AND ep.access_modules IS NOT NULL"
    );
    $update->execute();
    echo 'rows_normalized=' . $update->rowCount() . PHP_EOL;
} else {
    echo 'rows_normalized=0' . PHP_EOL;
}

$afterStmt = $pdo->query(
    "SELECT COUNT(*) AS c
     FROM employee_profiles ep
     INNER JOIN employees e ON e.id = ep.employee_id
     WHERE e.employee_code LIKE 'EMP-R%'
       AND ep.access_modules IS NOT NULL"
);
$after = (int) ($afterStmt->fetch(PDO::FETCH_ASSOC)['c'] ?? 0);

echo 'seeded_overrides_after=' . $after . PHP_EOL;
