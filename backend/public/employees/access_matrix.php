<?php

declare(strict_types=1);

if (!function_exists('available_access_modules')) {
    function available_access_modules(): array
    {
        return array_values(array_unique(array_merge(
            [
                'Dashboard',
                'Client',
                'Billing',
                'Mikrotik Server',
                'HR & Payroll',
                'Leave Management',
                'Events & Holidays',
                'Support & Ticketing',
                'Task Management',
                'Purchase',
                'Inventory',
                'Assets',
                'Income',
            ],
            available_page_access_modules(),
        )));
    }
}

if (!function_exists('available_page_access_modules')) {
    function available_page_access_modules(): array
    {
        return [
            'Task Management',
            'New Request',
            'Add New Client',
            'Client List',
            'Left Client',
            'Scheduler',
            'Change Request',
            'Portal Manage',
            'Billing',
            'Bulk Client Import',
            'Employee List',
            'Add Employee',
            'Attendance',
            'Salary Sheet',
            'Department',
            'Position',
            'Payhead',
            'Payroll',
            'Resign Rule',
            'Resignation',
            'Internet Packages',
            'Leave Management',
            'Apply Leave',
            'Events & Holidays',
            'Ticket List',
            'New Ticket',
            'Support Team',
            'Ticket Reports',
            'Service History',
            'Purchase',
            'Inventory',
            'Assets',
            'Income',
        ];
    }
}

if (!function_exists('default_department_module_map')) {
    function default_department_module_map(): array
    {
        return [
            'Administration' => [
                'Dashboard', 'Client', 'Billing', 'Mikrotik Server', 'HR & Payroll',
                'Leave Management', 'Events & Holidays', 'Support & Ticketing', 'Task Management',
                'Purchase', 'Inventory', 'Assets', 'Income',
            ],
            'IT & Network' => ['Dashboard', 'Client', 'Mikrotik Server', 'Assets', 'Inventory', 'Task Management'],
            'NOC' => ['Dashboard', 'Client', 'Mikrotik Server', 'Support & Ticketing', 'Task Management'],
            'Support' => ['Dashboard', 'Client', 'Support & Ticketing', 'Task Management'],
            'Accounts' => ['Dashboard', 'Billing', 'Income', 'Purchase', 'Inventory', 'Assets'],
            'HR' => ['Dashboard', 'HR & Payroll', 'Leave Management', 'Events & Holidays', 'Task Management'],
            'Sales' => ['Dashboard', 'Client', 'Billing', 'Income'],
            'Operations / Field' => ['Dashboard', 'Client', 'Support & Ticketing', 'Inventory', 'Assets', 'Task Management'],
            'Procurement / Store' => ['Dashboard', 'Purchase', 'Inventory', 'Assets'],
        ];
    }
}

if (!function_exists('default_position_permission_matrix')) {
    function default_position_permission_matrix(): array
    {
        return [
            'Administration' => [
                'Admin / Director' => ['*' => 'full'],
                'Admin Staff' => [
                    'Dashboard' => 'view',
                    'Task Management' => 'full',
                    'Events & Holidays' => 'full',
                ],
            ],
            'IT & Network' => [
                'IT Manager' => [
                    'Dashboard' => 'view',
                    'Mikrotik Server' => 'full',
                    'Client' => 'view',
                    'Inventory' => 'full',
                    'Assets' => 'full',
                    'Task Management' => 'full',
                ],
                'IT Staff' => [
                    'Dashboard' => 'view',
                    'Mikrotik Server' => 'limited',
                    'Client' => 'view',
                    'Inventory' => 'view',
                    'Assets' => 'limited',
                    'Task Management' => 'full',
                ],
            ],
            'NOC' => [
                'NOC Manager' => [
                    'Dashboard' => 'view',
                    'Mikrotik Server' => 'full',
                    'Client' => 'view',
                    'Support & Ticketing' => 'full',
                    'Task Management' => 'full',
                ],
                'NOC Engineer' => [
                    'Dashboard' => 'view',
                    'Mikrotik Server' => 'limited',
                    'Client' => 'view',
                    'Support & Ticketing' => 'full',
                    'Task Management' => 'full',
                ],
            ],
            'Support' => [
                'Support Manager' => [
                    'Dashboard' => 'view',
                    'Client' => 'full',
                    'Support & Ticketing' => 'full',
                    'Task Management' => 'full',
                ],
                'Support Staff' => [
                    'Dashboard' => 'view',
                    'Client' => 'limited',
                    'Support & Ticketing' => 'full',
                    'Task Management' => 'limited',
                ],
            ],
            'Accounts' => [
                'Accounts Manager' => [
                    'Dashboard' => 'view',
                    'Billing' => 'full',
                    'Income' => 'full',
                    'Purchase' => 'full',
                    'Inventory' => 'full',
                    'Assets' => 'full',
                ],
                'Accounts Staff' => [
                    'Dashboard' => 'view',
                    'Billing' => 'limited',
                    'Income' => 'limited',
                    'Purchase' => 'limited',
                    'Inventory' => 'view',
                    'Assets' => 'view',
                ],
            ],
            'HR' => [
                'HR Manager' => [
                    'Dashboard' => 'view',
                    'HR & Payroll' => 'full',
                    'Leave Management' => 'full',
                    'Events & Holidays' => 'full',
                    'Task Management' => 'full',
                ],
                'HR Staff' => [
                    'Dashboard' => 'view',
                    'HR & Payroll' => 'limited',
                    'Leave Management' => 'full',
                    'Events & Holidays' => 'view',
                    'Task Management' => 'limited',
                ],
            ],
            'Sales' => [
                'Sales Manager' => [
                    'Dashboard' => 'view',
                    'Client' => 'full',
                    'Billing' => 'view',
                    'Income' => 'view',
                ],
                'Sales Executive' => [
                    'Dashboard' => 'view',
                    'Client' => 'full',
                    'Billing' => 'limited',
                    'Income' => 'none',
                ],
            ],
            'Operations / Field' => [
                'Operations Manager' => [
                    'Dashboard' => 'view',
                    'Client' => 'full',
                    'Support & Ticketing' => 'full',
                    'Inventory' => 'full',
                    'Assets' => 'full',
                    'Task Management' => 'full',
                ],
                'Field Staff' => [
                    'Dashboard' => 'view',
                    'Client' => 'limited',
                    'Support & Ticketing' => 'full',
                    'Inventory' => 'limited',
                    'Assets' => 'limited',
                    'Task Management' => 'full',
                ],
            ],
            'Procurement / Store' => [
                'Procurement Manager' => [
                    'Dashboard' => 'view',
                    'Purchase' => 'full',
                    'Inventory' => 'full',
                    'Assets' => 'full',
                ],
                'Store Keeper' => [
                    'Dashboard' => 'view',
                    'Purchase' => 'limited',
                    'Inventory' => 'full',
                    'Assets' => 'limited',
                ],
            ],
        ];
    }
}

if (!function_exists('normalize_permission_level')) {
    function normalize_permission_level(string $level): string
    {
        $value = strtolower(trim($level));
        if ($value === 'full' || $value === 'view' || $value === 'limited' || $value === 'none') {
            return $value;
        }
        return 'none';
    }
}

if (!function_exists('normalize_module_permissions')) {
    function normalize_module_permissions($raw): array
    {
        $modules = available_access_modules();
        $allowed = array_flip($modules);
        $normalized = [];

        if (!is_array($raw)) {
            return [];
        }

        foreach ($raw as $moduleName => $level) {
            $moduleName = trim((string) $moduleName);
            if ($moduleName === '' || !isset($allowed[$moduleName])) {
                continue;
            }
            $permission = normalize_permission_level((string) $level);
            if ($permission !== 'none') {
                $normalized[$moduleName] = $permission;
            }
        }

        $ordered = [];
        foreach ($modules as $module) {
            if (isset($normalized[$module])) {
                $ordered[$module] = $normalized[$module];
            }
        }

        return $ordered;
    }
}

if (!function_exists('module_permissions_to_access_modules')) {
    function module_permissions_to_access_modules(array $modulePermissions): array
    {
        $access = [];
        foreach ($modulePermissions as $module => $permission) {
            $permission = normalize_permission_level((string) $permission);
            if ($permission === 'full' || $permission === 'view' || $permission === 'limited') {
                $access[] = (string) $module;
            }
        }

        return array_values(array_unique($access));
    }
}

if (!function_exists('access_matrix_table_exists')) {
    function access_matrix_table_exists(PDO $pdo, string $tableName): bool
    {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*)
             FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name'
        );
        $stmt->execute(['table_name' => $tableName]);
        return (int) $stmt->fetchColumn() > 0;
    }
}

if (!function_exists('ensure_department_access_table')) {
    function ensure_department_access_table(PDO $pdo): void
    {
        if (access_matrix_table_exists($pdo, 'department_access_modules')) {
            return;
        }

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS department_access_modules (
                department_id BIGINT UNSIGNED NOT NULL,
                module_name VARCHAR(120) NOT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (department_id, module_name),
                CONSTRAINT fk_dept_access_department FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE CASCADE
            ) ENGINE=InnoDB'
        );
    }
}

if (!function_exists('ensure_position_access_table')) {
    function ensure_position_access_table(PDO $pdo): void
    {
        if (access_matrix_table_exists($pdo, 'position_access_modules')) {
            return;
        }

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS position_access_modules (
                position_id BIGINT UNSIGNED NOT NULL,
                module_name VARCHAR(120) NOT NULL,
                permission_level ENUM("full", "view", "limited", "none") NOT NULL DEFAULT "none",
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (position_id, module_name),
                CONSTRAINT fk_position_access_position FOREIGN KEY (position_id) REFERENCES positions(id) ON DELETE CASCADE
            ) ENGINE=InnoDB'
        );
    }
}

if (!function_exists('load_position_module_permissions')) {
    function load_position_module_permissions(PDO $pdo, int $positionId): array
    {
        if ($positionId <= 0) {
            return [];
        }

        ensure_position_access_table($pdo);

        $stmt = $pdo->prepare(
            'SELECT module_name, permission_level
             FROM position_access_modules
             WHERE position_id = :position_id
             ORDER BY module_name ASC'
        );
        $stmt->execute(['position_id' => $positionId]);

        $map = [];
        foreach ($stmt->fetchAll() as $row) {
            $moduleName = trim((string) ($row['module_name'] ?? ''));
            $permission = normalize_permission_level((string) ($row['permission_level'] ?? 'none'));
            if ($moduleName !== '' && $permission !== 'none') {
                $map[$moduleName] = $permission;
            }
        }

        return normalize_module_permissions($map);
    }
}

if (!function_exists('save_position_module_permissions')) {
    function save_position_module_permissions(PDO $pdo, int $positionId, array $modulePermissions): void
    {
        if ($positionId <= 0) {
            return;
        }

        ensure_position_access_table($pdo);
        $normalized = normalize_module_permissions($modulePermissions);

        $delete = $pdo->prepare('DELETE FROM position_access_modules WHERE position_id = :position_id');
        $delete->execute(['position_id' => $positionId]);

        if (empty($normalized)) {
            return;
        }

        $insert = $pdo->prepare(
            'INSERT INTO position_access_modules (position_id, module_name, permission_level)
             VALUES (:position_id, :module_name, :permission_level)'
        );

        foreach ($normalized as $moduleName => $permission) {
            $insert->execute([
                'position_id' => $positionId,
                'module_name' => $moduleName,
                'permission_level' => $permission,
            ]);
        }
    }
}

if (!function_exists('sync_department_and_position_access')) {
    function sync_department_and_position_access(PDO $pdo): array
    {
        ensure_department_access_table($pdo);
        ensure_position_access_table($pdo);

        $departmentMap = default_department_module_map();
        $positionMatrix = default_position_permission_matrix();

        $stats = [
            'departments' => 0,
            'positions' => 0,
            'department_modules' => 0,
            'position_permissions' => 0,
        ];

        foreach ($departmentMap as $departmentName => $modules) {
            $findDept = $pdo->prepare('SELECT id FROM departments WHERE department_name = :name LIMIT 1');
            $findDept->execute(['name' => $departmentName]);
            $dept = $findDept->fetch();
            if ($dept) {
                $departmentId = (int) $dept['id'];
            } else {
                $insDept = $pdo->prepare('INSERT INTO departments (department_name) VALUES (:name)');
                $insDept->execute(['name' => $departmentName]);
                $departmentId = (int) $pdo->lastInsertId();
            }
            $stats['departments']++;

            $deleteDeptModules = $pdo->prepare('DELETE FROM department_access_modules WHERE department_id = :department_id');
            $deleteDeptModules->execute(['department_id' => $departmentId]);

            $insDeptModule = $pdo->prepare(
                'INSERT INTO department_access_modules (department_id, module_name)
                 VALUES (:department_id, :module_name)'
            );
            foreach (array_values(array_unique($modules)) as $moduleName) {
                $insDeptModule->execute([
                    'department_id' => $departmentId,
                    'module_name' => $moduleName,
                ]);
                $stats['department_modules']++;
            }

            $positions = $positionMatrix[$departmentName] ?? [];
            foreach ($positions as $positionName => $permissions) {
                $findPos = $pdo->prepare(
                    'SELECT id
                     FROM positions
                     WHERE department_id = :department_id AND position_name = :position_name
                     LIMIT 1'
                );
                $findPos->execute([
                    'department_id' => $departmentId,
                    'position_name' => $positionName,
                ]);
                $position = $findPos->fetch();

                if ($position) {
                    $positionId = (int) $position['id'];
                } else {
                    $insPos = $pdo->prepare(
                        'INSERT INTO positions (department_id, position_name)
                         VALUES (:department_id, :position_name)'
                    );
                    $insPos->execute([
                        'department_id' => $departmentId,
                        'position_name' => $positionName,
                    ]);
                    $positionId = (int) $pdo->lastInsertId();
                }
                $stats['positions']++;

                $permissionMap = [];
                if (isset($permissions['*']) && normalize_permission_level((string) $permissions['*']) === 'full') {
                    foreach (available_access_modules() as $moduleName) {
                        $permissionMap[$moduleName] = 'full';
                    }
                } else {
                    foreach ($permissions as $moduleName => $level) {
                        $permissionMap[(string) $moduleName] = normalize_permission_level((string) $level);
                    }
                }

                save_position_module_permissions($pdo, $positionId, $permissionMap);
                $stats['position_permissions'] += count(normalize_module_permissions($permissionMap));
            }
        }

        return $stats;
    }
}
