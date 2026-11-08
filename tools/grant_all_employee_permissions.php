<?php

declare(strict_types=1);

require_once __DIR__ . '/../backend/config/database.php';
require_once __DIR__ . '/../backend/public/employees/access_matrix.php';

function table_exists_perm(PDO $pdo, string $tableName): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*)
         FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name'
    );
    $stmt->execute(['table_name' => $tableName]);
    return (int) $stmt->fetchColumn() > 0;
}

try {
    $pdo = db();
    $modules = available_access_modules();
    $permissionMap = [];
    foreach ($modules as $moduleName) {
        $permissionMap[(string) $moduleName] = 'full';
    }
    $modulesJson = json_encode($permissionMap, JSON_UNESCAPED_UNICODE);

    if (!$modulesJson) {
        throw new RuntimeException('Failed to encode module list');
    }

    $pdo->beginTransaction();

    $updatedProfiles = 0;
    if (table_exists_perm($pdo, 'employee_profiles')) {
        $stmt = $pdo->prepare('UPDATE employee_profiles SET access_modules = :access_modules');
        $stmt->execute(['access_modules' => $modulesJson]);
        $updatedProfiles = $stmt->rowCount();
    }

    $updatedDeptRows = 0;
    if (table_exists_perm($pdo, 'department_access_modules') && table_exists_perm($pdo, 'departments')) {
        $pdo->exec('DELETE FROM department_access_modules');

        $departments = $pdo->query('SELECT id FROM departments')->fetchAll(PDO::FETCH_COLUMN);
        if (!empty($departments)) {
            $ins = $pdo->prepare(
                'INSERT INTO department_access_modules (department_id, module_name)
                 VALUES (:department_id, :module_name)'
            );
            foreach ($departments as $departmentId) {
                foreach ($modules as $moduleName) {
                    $ins->execute([
                        'department_id' => (int) $departmentId,
                        'module_name' => (string) $moduleName,
                    ]);
                    $updatedDeptRows++;
                }
            }
        }
    }

    $updatedPosRows = 0;
    if (table_exists_perm($pdo, 'position_access_modules') && table_exists_perm($pdo, 'positions')) {
        $pdo->exec('DELETE FROM position_access_modules');

        $positions = $pdo->query('SELECT id FROM positions')->fetchAll(PDO::FETCH_COLUMN);
        if (!empty($positions)) {
            $ins = $pdo->prepare(
                'INSERT INTO position_access_modules (position_id, module_name, permission_level)
                 VALUES (:position_id, :module_name, :permission_level)'
            );
            foreach ($positions as $positionId) {
                foreach ($modules as $moduleName) {
                    $ins->execute([
                        'position_id' => (int) $positionId,
                        'module_name' => (string) $moduleName,
                        'permission_level' => 'full',
                    ]);
                    $updatedPosRows++;
                }
            }
        }
    }

    $pdo->commit();

    echo 'All employee permissions are now FULL for all modules.' . PHP_EOL;
    echo 'employee_profiles updated: ' . $updatedProfiles . PHP_EOL;
    echo 'department_access_modules rows inserted: ' . $updatedDeptRows . PHP_EOL;
    echo 'position_access_modules rows inserted: ' . $updatedPosRows . PHP_EOL;
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    fwrite(STDERR, 'Failed to grant permissions: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
