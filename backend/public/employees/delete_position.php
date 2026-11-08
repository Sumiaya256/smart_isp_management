<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/database.php';
require_once dirname(__DIR__, 2) . '/config/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json(['ok' => false, 'message' => 'Method not allowed'], 405);
}

require_page_permission('Position', 'edit');

$input = read_json_input();
$positionName = trim((string) ($input['position_name'] ?? ''));
$departmentName = trim((string) ($input['department_name'] ?? ''));

if ($positionName === '' || $departmentName === '') {
    send_json(['ok' => false, 'message' => 'position_name and department_name are required'], 422);
}

try {
    $pdo = db();
    $pdo->beginTransaction();

    $find = $pdo->prepare(
        'SELECT p.id
         FROM positions p
         INNER JOIN departments d ON d.id = p.department_id
         WHERE p.position_name = :position_name AND d.department_name = :department_name
         LIMIT 1'
    );
    $find->execute([
        'position_name' => $positionName,
        'department_name' => $departmentName,
    ]);
    $position = $find->fetch();

    if (!$position) {
        $pdo->rollBack();
        send_json(['ok' => false, 'message' => 'Position not found'], 404);
    }

    $positionId = (int) $position['id'];

    $checkEmployee = $pdo->prepare('SELECT COUNT(*) FROM employees WHERE position_id = :position_id');
    $checkEmployee->execute(['position_id' => $positionId]);
    $employeeCount = (int) $checkEmployee->fetchColumn();

    if ($employeeCount > 0) {
        $pdo->rollBack();
        send_json([
            'ok' => false,
            'message' => 'Cannot delete this position because employees are assigned to it. Delete or reassign employees first.',
        ], 409);
    }

    $delete = $pdo->prepare('DELETE FROM positions WHERE id = :id');
    $delete->execute(['id' => $positionId]);

    $pdo->commit();

    send_json([
        'ok' => true,
        'message' => 'Position deleted successfully',
    ]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    send_json([
        'ok' => false,
        'message' => 'Failed to delete position',
        'error' => $e->getMessage(),
    ], 500);
}
