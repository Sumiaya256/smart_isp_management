<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/database.php';
require_once dirname(__DIR__, 2) . '/config/auth.php';
require_once dirname(__DIR__) . '/employees/delete_helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json(['ok' => false, 'message' => 'Method not allowed'], 405);
}

$actor = require_roles(['Admin']);

$input = read_json_input();
$roleId = (int) ($input['role_id'] ?? 0);

if ($roleId <= 0) {
    send_json(['ok' => false, 'message' => 'role_id is required'], 422);
}

try {
    $pdo = db();
    $pdo->beginTransaction();

    $roleStmt = $pdo->prepare('SELECT id, name FROM roles WHERE id = :id LIMIT 1');
    $roleStmt->execute(['id' => $roleId]);
    $role = $roleStmt->fetch();

    if (!$role) {
        $pdo->rollBack();
        send_json(['ok' => false, 'message' => 'Role not found'], 404);
    }

    $roleName = (string) $role['name'];

    $actorUserStmt = $pdo->prepare('SELECT role_id FROM users WHERE id = :id LIMIT 1');
    $actorUserStmt->execute(['id' => (int) $actor['id']]);
    $actorUser = $actorUserStmt->fetch();
    if ($actorUser && (int) $actorUser['role_id'] === $roleId) {
        $pdo->rollBack();
        send_json(['ok' => false, 'message' => 'You cannot delete your own role'], 422);
    }

    $employeeRoleStmt = $pdo->prepare('SELECT employee_id FROM employee_profiles WHERE LOWER(role_name) = LOWER(:role_name)');
    $employeeRoleStmt->execute(['role_name' => $roleName]);
    $employeeIds = array_map(static fn ($row) => (int) $row['employee_id'], $employeeRoleStmt->fetchAll());
    $deletedEmployees = delete_employees_by_ids($pdo, $employeeIds);

    $userStmt = $pdo->prepare('SELECT id FROM users WHERE role_id = :role_id');
    $userStmt->execute(['role_id' => $roleId]);
    $userIds = array_values(array_unique(array_map(static fn ($row) => (int) $row['id'], $userStmt->fetchAll())));

    if (!empty($userIds)) {
        $in = sql_in_placeholders(count($userIds));

        $stmt = $pdo->prepare("UPDATE leave_requests SET approved_by = NULL WHERE approved_by IN ($in)");
        $stmt->execute($userIds);

        $stmt = $pdo->prepare("UPDATE payments SET collected_by = NULL WHERE collected_by IN ($in)");
        $stmt->execute($userIds);

        $stmt = $pdo->prepare("UPDATE purchases SET created_by = NULL WHERE created_by IN ($in)");
        $stmt->execute($userIds);

        $stmt = $pdo->prepare("UPDATE support_tickets SET assigned_to = NULL WHERE assigned_to IN ($in)");
        $stmt->execute($userIds);

        $stmt = $pdo->prepare("DELETE FROM support_tickets WHERE created_by IN ($in)");
        $stmt->execute($userIds);

        $runStmt = $pdo->prepare("SELECT id FROM payroll_runs WHERE generated_by IN ($in)");
        $runStmt->execute($userIds);
        $runIds = array_map(static fn ($row) => (int) $row['id'], $runStmt->fetchAll());
        if (!empty($runIds)) {
            $runIn = sql_in_placeholders(count($runIds));
            $delItems = $pdo->prepare("DELETE FROM payroll_items WHERE payroll_run_id IN ($runIn)");
            $delItems->execute($runIds);

            $delRuns = $pdo->prepare("DELETE FROM payroll_runs WHERE id IN ($runIn)");
            $delRuns->execute($runIds);
        }

        $stmt = $pdo->prepare("DELETE FROM users WHERE id IN ($in)");
        $stmt->execute($userIds);
    }

    $deleteRole = $pdo->prepare('DELETE FROM roles WHERE id = :id');
    $deleteRole->execute(['id' => $roleId]);

    $pdo->commit();

    send_json([
        'ok' => true,
        'message' => 'Role and related data deleted successfully',
        'deleted_users' => count($userIds),
        'deleted_employees' => $deletedEmployees,
    ]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    send_json([
        'ok' => false,
        'message' => 'Failed to delete role',
        'error' => $e->getMessage(),
    ], 500);
}
