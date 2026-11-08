<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/database.php';
require_once dirname(__DIR__, 2) . '/config/auth.php';
require_once __DIR__ . '/delete_helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json(['ok' => false, 'message' => 'Method not allowed'], 405);
}

require_page_permission('Employee List', 'edit');

$input = read_json_input();
$employeeCode = trim((string) ($input['employee_code'] ?? ''));
$employeeIdInput = trim((string) ($input['employee_id'] ?? ''));

if ($employeeCode === '' && $employeeIdInput === '') {
    send_json(['ok' => false, 'message' => 'employee_code or employee_id is required'], 422);
}

try {
    $pdo = db();
    $pdo->beginTransaction();

    if ($employeeCode !== '') {
        $find = $pdo->prepare('SELECT id, employee_code, full_name FROM employees WHERE employee_code = :employee_code LIMIT 1');
        $find->execute(['employee_code' => $employeeCode]);
    } else {
        $id = (int) $employeeIdInput;
        $find = $pdo->prepare('SELECT id, employee_code, full_name FROM employees WHERE id = :id LIMIT 1');
        $find->execute(['id' => $id]);
    }

    $employee = $find->fetch();
    if (!$employee) {
        $pdo->rollBack();
        send_json(['ok' => false, 'message' => 'Employee not found'], 404);
    }

    $deleted = delete_employees_by_ids($pdo, [(int) $employee['id']]);

    $pdo->commit();

    send_json([
        'ok' => true,
        'message' => 'Employee and related data deleted successfully',
        'deleted_count' => $deleted,
        'employee_code' => (string) $employee['employee_code'],
    ]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    send_json([
        'ok' => false,
        'message' => 'Failed to delete employee',
        'error' => $e->getMessage(),
    ], 500);
}
