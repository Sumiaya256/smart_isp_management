<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/database.php';
require_once dirname(__DIR__, 2) . '/config/auth.php';
require_once __DIR__ . '/access_matrix.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json(['ok' => false, 'message' => 'Method not allowed'], 405);
}

require_page_permission('Position', 'edit');

$input = read_json_input();
$positionId = (int) ($input['position_id'] ?? 0);
$newName = trim((string) ($input['new_position_name'] ?? ''));
$newDepartmentName = trim((string) ($input['new_department_name'] ?? ''));
$modulePermissions = normalize_module_permissions($input['module_permissions'] ?? []);

if ($positionId <= 0 || $newName === '' || $newDepartmentName === '') {
    send_json(['ok' => false, 'message' => 'position_id, new_position_name and new_department_name are required'], 422);
}

try {
    $pdo = db();
    $pdo->beginTransaction();

    $findPosition = $pdo->prepare('SELECT id FROM positions WHERE id = :id LIMIT 1');
    $findPosition->execute(['id' => $positionId]);
    if (!$findPosition->fetch()) {
        $pdo->rollBack();
        send_json(['ok' => false, 'message' => 'Position not found'], 404);
    }

    $findDept = $pdo->prepare('SELECT id FROM departments WHERE department_name = :name LIMIT 1');
    $findDept->execute(['name' => $newDepartmentName]);
    $dept = $findDept->fetch();
    if ($dept) {
        $departmentId = (int) $dept['id'];
    } else {
        $insertDept = $pdo->prepare('INSERT INTO departments (department_name) VALUES (:name)');
        $insertDept->execute(['name' => $newDepartmentName]);
        $departmentId = (int) $pdo->lastInsertId();
    }

    $dup = $pdo->prepare(
        'SELECT id FROM positions
         WHERE department_id = :department_id AND position_name = :position_name AND id <> :id
         LIMIT 1'
    );
    $dup->execute([
        'department_id' => $departmentId,
        'position_name' => $newName,
        'id' => $positionId,
    ]);
    if ($dup->fetch()) {
        $pdo->rollBack();
        send_json(['ok' => false, 'message' => 'Position already exists in this department'], 409);
    }

    $upd = $pdo->prepare(
        'UPDATE positions
         SET position_name = :position_name,
             department_id = :department_id
         WHERE id = :id'
    );
    $upd->execute([
        'position_name' => $newName,
        'department_id' => $departmentId,
        'id' => $positionId,
    ]);

    save_position_module_permissions($pdo, $positionId, $modulePermissions);

    $pdo->commit();

    send_json([
        'ok' => true,
        'message' => 'Position updated successfully',
    ]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    send_json([
        'ok' => false,
        'message' => 'Failed to update position',
        'error' => $e->getMessage(),
    ], 500);
}
