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
$departmentId = (int) ($input['department_id'] ?? 0);
$newName = trim((string) ($input['new_department_name'] ?? ''));
$modules = $input['access_modules'] ?? [];

if ($departmentId <= 0 || $newName === '') {
    send_json(['ok' => false, 'message' => 'department_id and new_department_name are required'], 422);
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

    $find = $pdo->prepare('SELECT id FROM departments WHERE id = :id LIMIT 1');
    $find->execute(['id' => $departmentId]);
    if (!$find->fetch()) {
        $pdo->rollBack();
        send_json(['ok' => false, 'message' => 'Department not found'], 404);
    }

    $dup = $pdo->prepare('SELECT id FROM departments WHERE department_name = :name AND id <> :id LIMIT 1');
    $dup->execute([
        'name' => $newName,
        'id' => $departmentId,
    ]);
    if ($dup->fetch()) {
        $pdo->rollBack();
        send_json(['ok' => false, 'message' => 'Department name already exists'], 409);
    }

    $upd = $pdo->prepare('UPDATE departments SET department_name = :name WHERE id = :id');
    $upd->execute([
        'name' => $newName,
        'id' => $departmentId,
    ]);

    $del = $pdo->prepare('DELETE FROM department_access_modules WHERE department_id = :department_id');
    $del->execute(['department_id' => $departmentId]);

    if (!empty($modules)) {
        $ins = $pdo->prepare(
            'INSERT INTO department_access_modules (department_id, module_name) VALUES (:department_id, :module_name)'
        );
        foreach ($modules as $moduleName) {
            $ins->execute([
                'department_id' => $departmentId,
                'module_name' => $moduleName,
            ]);
        }
    }

    $pdo->commit();

    send_json([
        'ok' => true,
        'message' => 'Department updated successfully',
    ]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    send_json([
        'ok' => false,
        'message' => 'Failed to update department',
        'error' => $e->getMessage(),
    ], 500);
}
