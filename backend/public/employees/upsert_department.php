<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/database.php';
require_once dirname(__DIR__, 2) . '/config/auth.php';
require_once __DIR__ . '/delete_helpers.php';
require_once __DIR__ . '/access_matrix.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json(['ok' => false, 'message' => 'Method not allowed'], 405);
}

require_page_permission('Department', 'edit');

$input = read_json_input();
$departmentName = trim((string) ($input['department_name'] ?? ''));
$modules = $input['access_modules'] ?? [];

if ($departmentName === '') {
    send_json(['ok' => false, 'message' => 'department_name is required'], 422);
}

$modules = is_array($modules)
    ? array_values(array_unique(array_filter(array_map(static fn ($m) => trim((string) $m), $modules), static fn ($m) => $m !== '')))
    : [];

$allowed = array_flip(available_access_modules());
$modules = array_values(array_filter($modules, static fn ($moduleName) => isset($allowed[$moduleName])));

try {
    $pdo = db();
    ensure_department_access_table($pdo);
    $pdo->beginTransaction();

    $find = $pdo->prepare('SELECT id FROM departments WHERE department_name = :name LIMIT 1');
    $find->execute(['name' => $departmentName]);
    $row = $find->fetch();

    if ($row) {
        $departmentId = (int) $row['id'];
    } else {
        $insert = $pdo->prepare('INSERT INTO departments (department_name) VALUES (:name)');
        $insert->execute(['name' => $departmentName]);
        $departmentId = (int) $pdo->lastInsertId();
    }

    $deleteOld = $pdo->prepare('DELETE FROM department_access_modules WHERE department_id = :department_id');
    $deleteOld->execute(['department_id' => $departmentId]);

    if (!empty($modules)) {
        $insertAccess = $pdo->prepare(
            'INSERT INTO department_access_modules (department_id, module_name) VALUES (:department_id, :module_name)'
        );

        foreach ($modules as $moduleName) {
            $insertAccess->execute([
                'department_id' => $departmentId,
                'module_name' => $moduleName,
            ]);
        }
    }

    $pdo->commit();

    send_json([
        'ok' => true,
        'message' => 'Department saved successfully',
        'department_id' => $departmentId,
    ], 201);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    send_json([
        'ok' => false,
        'message' => 'Failed to save department',
        'error' => $e->getMessage(),
    ], 500);
}
