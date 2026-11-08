<?php

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json(['ok' => false, 'message' => 'Method not allowed'], 405);
}

$user = require_auth();
$positionName = (string) ($user['position_name'] ?? '');
$isAccountsPayrollRole = in_array($positionName, ['Accounts Manager', 'Accounts Staff', 'Admin / Director'], true);

if (!$isAccountsPayrollRole && !user_has_page_action_access($user, 'Payhead', 'edit')) {
    send_json(['ok' => false, 'message' => 'Forbidden: insufficient module permission'], 403);
}

$input = read_json_input();
$payheadId = (int) ($input['payheadId'] ?? 0);

if ($payheadId <= 0) {
    send_json([
        'ok' => false,
        'message' => 'payheadId is required',
    ], 422);
}

try {
    $pdo = db();
    ensure_payheads_schema($pdo);

    if (is_limited_module_permission($user, 'HR & Payroll')) {
        $scopeStmt = $pdo->prepare(
            'SELECT id FROM hr_payheads
             WHERE id = :id AND (created_by = :employee_id OR updated_by = :employee_id)
             LIMIT 1'
        );
        $scopeStmt->execute([
            'id' => $payheadId,
            'employee_id' => (int) ($user['id'] ?? 0),
        ]);
        if (!$scopeStmt->fetch()) {
            send_json([
                'ok' => false,
                'message' => 'Forbidden: limited access allows deleting only own payheads',
            ], 403);
        }
    }

    $stmt = $pdo->prepare('DELETE FROM hr_payheads WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $payheadId]);

    if ($stmt->rowCount() === 0) {
        send_json([
            'ok' => false,
            'message' => 'Payhead not found',
        ], 404);
    }

    send_json([
        'ok' => true,
        'message' => 'Payhead deleted successfully',
    ]);
} catch (Throwable $e) {
    send_json([
        'ok' => false,
        'message' => 'Failed to delete payhead',
        'error' => $e->getMessage(),
    ], 500);
}
