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
$positionName = trim((string) ($input['position_name'] ?? ''));
$departmentName = trim((string) ($input['department_name'] ?? ''));
$modulePermissions = normalize_module_permissions($input['module_permissions'] ?? []);

if ($positionName === '' || $departmentName === '') {
    send_json(['ok' => false, 'message' => 'position_name and department_name are required'], 422);
}

try {
    $pdo = db();
    $pdo->beginTransaction();

    $findDept = $pdo->prepare('SELECT id FROM departments WHERE department_name = :name LIMIT 1');
    $findDept->execute(['name' => $departmentName]);
    $department = $findDept->fetch();

    if ($department) {
        $departmentId = (int) $department['id'];
    } else {
        $insertDept = $pdo->prepare('INSERT INTO departments (department_name) VALUES (:name)');
        $insertDept->execute(['name' => $departmentName]);
        $departmentId = (int) $pdo->lastInsertId();
    }

    $findPos = $pdo->prepare(
        'SELECT id FROM positions WHERE department_id = :department_id AND position_name = :position_name LIMIT 1'
    );
    $findPos->execute([
        'department_id' => $departmentId,
        'position_name' => $positionName,
    ]);
    $existingPosition = $findPos->fetch();

    if (!$existingPosition) {
        $insertPos = $pdo->prepare(
            'INSERT INTO positions (department_id, position_name) VALUES (:department_id, :position_name)'
        );
        $insertPos->execute([
            'department_id' => $departmentId,
            'position_name' => $positionName,
        ]);
        $positionId = (int) $pdo->lastInsertId();
    } else {
        $positionId = (int) ($existingPosition['id'] ?? 0);
    }

    if ($positionId > 0) {
        save_position_module_permissions($pdo, $positionId, $modulePermissions);
    }

    $pdo->commit();

    send_json([
        'ok' => true,
        'message' => 'Position saved successfully',
        'department_id' => $departmentId,
        'position_id' => $positionId,
    ], 201);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    send_json([
        'ok' => false,
        'message' => 'Failed to save position',
        'error' => $e->getMessage(),
    ], 500);
}
