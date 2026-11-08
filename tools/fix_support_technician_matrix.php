<?php

declare(strict_types=1);

require __DIR__ . '/../backend/config/database.php';
require __DIR__ . '/../backend/public/employees/access_matrix.php';

$pdo = db();

$positionStmt = $pdo->prepare(
    "SELECT p.id
     FROM positions p
     INNER JOIN departments d ON d.id = p.department_id
     WHERE d.department_name = 'Support' AND p.position_name = 'Technician'
     LIMIT 1"
);
$positionStmt->execute();
$positionId = (int) $positionStmt->fetchColumn();

if ($positionId <= 0) {
    echo "support_technician_position_not_found\n";
    exit(1);
}

$target = [
    'Dashboard' => 'full',
    'Client' => 'view',
    'Billing' => 'view',
    'Mikrotik Server' => 'none',
    'HR & Payroll' => 'view',
    'Leave Management' => 'full',
    'Events & Holidays' => 'view',
    'Support & Ticketing' => 'full',
    'Task Management' => 'view',
    'Purchase' => 'view',
    'Inventory' => 'view',
    'Assets' => 'view',
    'Income' => 'view',
    'New Request' => 'view',
    'Add New Client' => 'view',
    'Client List' => 'view',
    'Left Client' => 'view',
    'Scheduler' => 'view',
    'Change Request' => 'view',
    'Portal Manage' => 'view',
    'Bulk Client Import' => 'none',
    'Employee List' => 'view',
    'Add Employee' => 'view',
    'Attendance' => 'full',
    'Salary Sheet' => 'view',
    'Department' => 'view',
    'Position' => 'view',
    'Payhead' => 'view',
    'Payroll' => 'view',
    'Resign Rule' => 'view',
    'Resignation' => 'view',
    'Internet Packages' => 'view',
    'Apply Leave' => 'full',
    'Ticket List' => 'full',
    'New Ticket' => 'full',
    'Support Team' => 'full',
    'Ticket Reports' => 'full',
    'Service History' => 'full',
];

$all = available_access_modules();
foreach ($all as $moduleName) {
    if (!array_key_exists($moduleName, $target)) {
        $target[$moduleName] = 'none';
    }
}

$pdo->beginTransaction();
try {
    $del = $pdo->prepare('DELETE FROM position_access_modules WHERE position_id = :position_id');
    $del->execute(['position_id' => $positionId]);

    $ins = $pdo->prepare(
        'INSERT INTO position_access_modules (position_id, module_name, permission_level)
         VALUES (:position_id, :module_name, :permission_level)'
    );

    $rows = 0;
    foreach ($all as $moduleName) {
        $ins->execute([
            'position_id' => $positionId,
            'module_name' => $moduleName,
            'permission_level' => $target[$moduleName],
        ]);
        $rows++;
    }

    $pdo->commit();
    echo 'position_id=' . $positionId . PHP_EOL;
    echo 'rows_written=' . $rows . PHP_EOL;
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, 'fix_failed=' . $e->getMessage() . PHP_EOL);
    exit(1);
}
