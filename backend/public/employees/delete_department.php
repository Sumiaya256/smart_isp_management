<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/database.php';
require_once dirname(__DIR__, 2) . '/config/auth.php';
require_once __DIR__ . '/delete_helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json(['ok' => false, 'message' => 'Method not allowed'], 405);
}

require_page_permission('Department', 'edit');

$input = read_json_input();
$departmentName = trim((string) ($input['department_name'] ?? ''));
$departmentIdInput = (int) ($input['department_id'] ?? 0);

if ($departmentName === '' && $departmentIdInput <= 0) {
    send_json(['ok' => false, 'message' => 'department_name or department_id is required'], 422);
}

try {
    $pdo = db();
    ensure_department_access_table($pdo);
    $pdo->beginTransaction();

    if ($departmentIdInput > 0) {
        $findDept = $pdo->prepare('SELECT id, department_name FROM departments WHERE id = :id LIMIT 1');
        $findDept->execute(['id' => $departmentIdInput]);
    } else {
        $findDept = $pdo->prepare('SELECT id, department_name FROM departments WHERE department_name = :name LIMIT 1');
        $findDept->execute(['name' => $departmentName]);
    }

    $department = $findDept->fetch();
    if (!$department) {
        $pdo->rollBack();
        send_json(['ok' => false, 'message' => 'Department not found'], 404);
    }

    $departmentId = (int) $department['id'];

    $empStmt = $pdo->prepare('SELECT id FROM employees WHERE department_id = :department_id');
    $empStmt->execute(['department_id' => $departmentId]);
    $employeeIds = array_map(static fn ($row) => (int) $row['id'], $empStmt->fetchAll());

    $deletedEmployees = delete_employees_by_ids($pdo, $employeeIds);

    $deletePositions = $pdo->prepare('DELETE FROM positions WHERE department_id = :department_id');
    $deletePositions->execute(['department_id' => $departmentId]);
    $deletedPositions = $deletePositions->rowCount();

    $deleteAccess = $pdo->prepare('DELETE FROM department_access_modules WHERE department_id = :department_id');
    $deleteAccess->execute(['department_id' => $departmentId]);

    $deleteDept = $pdo->prepare('DELETE FROM departments WHERE id = :department_id');
    $deleteDept->execute(['department_id' => $departmentId]);

    $pdo->commit();

    send_json([
        'ok' => true,
        'message' => 'Department and related employee data deleted successfully',
        'department_name' => (string) $department['department_name'],
        'deleted_employees' => $deletedEmployees,
        'deleted_positions' => $deletedPositions,
    ]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    send_json([
        'ok' => false,
        'message' => 'Failed to delete department',
        'error' => $e->getMessage(),
    ], 500);
}
