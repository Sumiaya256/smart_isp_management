<?php

declare(strict_types=1);

require __DIR__ . '/../backend/config/database.php';
require __DIR__ . '/../backend/public/employees/access_matrix.php';

$positionId = isset($argv[1]) ? (int) $argv[1] : 0;
if ($positionId <= 0) {
    fwrite(STDERR, "Usage: php tools/inspect_position_permissions.php <position_id>\n");
    exit(1);
}

$pdo = db();

$metaStmt = $pdo->prepare(
    'SELECT p.id, p.position_name, d.department_name
     FROM positions p
     LEFT JOIN departments d ON d.id = p.department_id
     WHERE p.id = :id
     LIMIT 1'
);
$metaStmt->execute(['id' => $positionId]);
$meta = $metaStmt->fetch(PDO::FETCH_ASSOC);

if (!$meta) {
    echo 'position_not_found=' . $positionId . PHP_EOL;
    exit(0);
}

echo 'position_id=' . (int) ($meta['id'] ?? 0) . PHP_EOL;
echo 'position_name=' . (string) ($meta['position_name'] ?? '') . PHP_EOL;
echo 'department_name=' . (string) ($meta['department_name'] ?? '') . PHP_EOL;

$map = load_position_module_permissions($pdo, $positionId);
$all = available_access_modules();

$full = 0;
$view = 0;
$none = 0;

foreach ($all as $moduleName) {
    $level = (string) ($map[$moduleName] ?? 'none');
    if ($level === 'full') {
        $full++;
    } elseif ($level === 'view') {
        $view++;
    } else {
        $none++;
    }
    echo $moduleName . '=' . $level . PHP_EOL;
}

echo 'full_count=' . $full . PHP_EOL;
echo 'view_count=' . $view . PHP_EOL;
echo 'none_count=' . $none . PHP_EOL;
