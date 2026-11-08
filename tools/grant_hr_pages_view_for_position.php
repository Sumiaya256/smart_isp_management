<?php

declare(strict_types=1);

require __DIR__ . '/../backend/config/database.php';

$positionId = isset($argv[1]) ? (int) $argv[1] : 84;

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
];

$pdo = db();

$upsert = $pdo->prepare(
    'INSERT INTO position_access_modules (position_id, module_name, permission_level)
     VALUES (:position_id, :module_name, "view")
     ON DUPLICATE KEY UPDATE
       permission_level = CASE
         WHEN permission_level = "full" THEN "full"
         WHEN permission_level = "limited" THEN "view"
         ELSE "view"
       END'
);

$pdo->beginTransaction();
try {
    foreach ($hrPages as $page) {
        $upsert->execute([
            'position_id' => $positionId,
            'module_name' => $page,
        ]);
    }
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    throw $e;
}

echo 'updated_position=' . $positionId . PHP_EOL;
foreach ($hrPages as $page) {
    $s = $pdo->prepare('SELECT permission_level FROM position_access_modules WHERE position_id = :position_id AND module_name = :module_name LIMIT 1');
    $s->execute(['position_id' => $positionId, 'module_name' => $page]);
    $lvl = (string) ($s->fetchColumn() ?: 'none');
    echo $page . '=' . $lvl . PHP_EOL;
}
