<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../public/employees/access_matrix.php';

try {
    $pdo = db();
    $stats = sync_department_and_position_access($pdo);

    echo "Access structure sync completed." . PHP_EOL;
    echo 'Departments: ' . $stats['departments'] . PHP_EOL;
    echo 'Positions: ' . $stats['positions'] . PHP_EOL;
    echo 'Department Modules: ' . $stats['department_modules'] . PHP_EOL;
    echo 'Position Permissions: ' . $stats['position_permissions'] . PHP_EOL;
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'Sync failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
