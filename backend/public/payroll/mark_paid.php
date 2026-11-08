<?php

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json(['ok' => false, 'message' => 'Method not allowed'], 405);
}

$user = require_auth();
$positionName = (string) ($user['position_name'] ?? '');
$isAccountsPayrollRole = in_array($positionName, ['Accounts Manager', 'Accounts Staff', 'Admin / Director'], true);

if (!$isAccountsPayrollRole && !user_has_page_action_access($user, 'Payroll', 'edit')) {
    send_json(['ok' => false, 'message' => 'Forbidden: insufficient module permission'], 403);
}
$input = read_json_input();

$payrollMonth = payroll_month_or_fail((string) ($input['payrollMonth'] ?? ''));
$paymentStatus = trim((string) ($input['paymentStatus'] ?? 'Paid'));

if (!in_array($paymentStatus, ['Pending', 'Processed', 'Paid', 'Hold'], true)) {
    send_json([
        'ok' => false,
        'message' => 'Invalid paymentStatus',
    ], 422);
}

$employeeIds = isset($input['employeeIds']) && is_array($input['employeeIds'])
    ? array_values(array_unique(array_filter(array_map('intval', $input['employeeIds']), static fn ($v) => $v > 0)))
    : [];

try {
    $pdo = db();
    ensure_payroll_schema($pdo);

    $runStmt = $pdo->prepare('SELECT id FROM hr_payroll_runs WHERE payroll_month = :month LIMIT 1');
    $runStmt->execute(['month' => $payrollMonth]);
    $run = $runStmt->fetch();

    if (!$run) {
        send_json([
            'ok' => false,
            'message' => 'No payroll run found for selected month',
        ], 404);
    }

    $runId = (int) $run['id'];

    if (is_limited_module_permission($user, 'HR & Payroll')) {
        $ownerStmt = $pdo->prepare('SELECT generated_by FROM hr_payroll_runs WHERE id = :id LIMIT 1');
        $ownerStmt->execute(['id' => $runId]);
        $owner = $ownerStmt->fetch();
        $generatedBy = (int) ($owner['generated_by'] ?? 0);
        if ($generatedBy > 0 && $generatedBy !== (int) ($user['id'] ?? 0)) {
            send_json([
                'ok' => false,
                'message' => 'Forbidden: limited access allows updating only own payroll runs',
            ], 403);
        }
    }

    $paidAt = $paymentStatus === 'Paid' ? date('Y-m-d H:i:s') : null;

    if (empty($employeeIds)) {
        $update = $pdo->prepare(
            'UPDATE hr_payroll_items
             SET payment_status = :payment_status,
                 paid_at = :paid_at
             WHERE payroll_run_id = :run_id'
        );
        $update->execute([
            'payment_status' => $paymentStatus,
            'paid_at' => $paidAt,
            'run_id' => $runId,
        ]);

        send_json([
            'ok' => true,
            'message' => 'Payroll payment status updated',
            'updatedCount' => $update->rowCount(),
        ]);
    }

    $in = implode(',', array_fill(0, count($employeeIds), '?'));
    $sql =
        'UPDATE hr_payroll_items
         SET payment_status = ?,
             paid_at = ?
         WHERE payroll_run_id = ?
           AND employee_id IN (' . $in . ')';

    $params = array_merge([$paymentStatus, $paidAt, $runId], $employeeIds);

    $update = $pdo->prepare($sql);
    $update->execute($params);

    send_json([
        'ok' => true,
        'message' => 'Payroll payment status updated',
        'updatedCount' => $update->rowCount(),
    ]);
} catch (Throwable $e) {
    send_json([
        'ok' => false,
        'message' => 'Failed to update payment status',
        'error' => $e->getMessage(),
    ], 500);
}
