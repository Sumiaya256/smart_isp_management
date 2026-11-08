<?php

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json(['ok' => false, 'message' => 'Method not allowed'], 405);
}

$user = require_auth();
require_module_permission('Leave Management', 'edit', true);

$input = read_json_input();
$id = (int) ($input['id'] ?? 0);
$employeeCode = trim((string) ($input['employee_code'] ?? ''));
$employeeName = trim((string) ($input['employee_name'] ?? ''));
$departmentName = trim((string) ($input['department_name'] ?? ''));
$leaveType = trim((string) ($input['leave_type'] ?? ''));
$startDate = trim((string) ($input['start_date'] ?? ''));
$endDate = trim((string) ($input['end_date'] ?? ''));
$reasonText = trim((string) ($input['reason_text'] ?? $input['reason'] ?? ''));
$statusLabel = trim((string) ($input['status_label'] ?? 'pending'));
$rejectReason = trim((string) ($input['reject_reason'] ?? ''));

if ($employeeCode === '' || $employeeName === '' || $leaveType === '' || $startDate === '' || $endDate === '') {
    send_json(['ok' => false, 'message' => 'employee_code, employee_name, leave_type, start_date and end_date are required'], 422);
}

$allowed = ['pending', 'approved', 'rejected', 'cancelled'];
if (!in_array($statusLabel, $allowed, true)) {
    $statusLabel = 'pending';
}

try {
    $pdo = db();
    ensure_leave_schema($pdo);

    $employeeId = (int) ($user['id'] ?? 0);
    $legacyEmployeeId = null;
    $hasLegacyEmployeeId = leave_column_exists($pdo, 'employee_id');
    if ($hasLegacyEmployeeId) {
        $legacyEmployeeId = leave_resolve_employee_id($pdo, $user, $employeeCode, $employeeName);
        if ($legacyEmployeeId === null) {
            send_json([
                'ok' => false,
                'message' => 'Failed to map employee profile for legacy leave schema',
                'error' => 'No matching employees.id found for this logged-in account',
            ], 422);
        }
    }

    $totalDays = max(1, (int) ((strtotime($endDate) - strtotime($startDate)) / 86400) + 1);

    if ($id > 0) {
        enforce_leave_scope($pdo, $user, $id);

        $updateSql =
            'UPDATE leave_requests
             SET employee_code = :employee_code,
                 employee_name = :employee_name,
                 department_name = :department_name,
                 leave_type = :leave_type,
                 start_date = :start_date,
                 end_date = :end_date,
                 total_days = :total_days,
                 reason_text = :reason_text,
                 status_label = :status_label,
                 reject_reason = :reject_reason,
                 assigned_to_employee_id = :assigned_to_employee_id';

        if ($hasLegacyEmployeeId) {
            $updateSql .= ', employee_id = :employee_id';
        }

        $updateSql .= ' WHERE id = :id';

        $stmt = $pdo->prepare($updateSql);

        $updateParams = [
            'employee_code' => leave_str_cut($employeeCode, 40),
            'employee_name' => leave_str_cut($employeeName, 140),
            'department_name' => $departmentName !== '' ? leave_str_cut($departmentName, 100) : null,
            'leave_type' => leave_str_cut($leaveType, 60),
            'start_date' => $startDate,
            'end_date' => $endDate,
            'total_days' => $totalDays,
            'reason_text' => $reasonText !== '' ? $reasonText : null,
            'status_label' => $statusLabel,
            'reject_reason' => $rejectReason !== '' ? $rejectReason : null,
            'assigned_to_employee_id' => $employeeId > 0 ? $employeeId : null,
            'id' => $id,
        ];

        if ($hasLegacyEmployeeId) {
            $updateParams['employee_id'] = $legacyEmployeeId;
        }

        $stmt->execute($updateParams);
    } else {
        $insertColumns = [
            'employee_code', 'employee_name', 'department_name', 'leave_type',
            'start_date', 'end_date', 'total_days', 'reason_text', 'applied_on',
            'status_label', 'reject_reason', 'created_by_employee_id', 'assigned_to_employee_id',
        ];
        $insertValues = [
            ':employee_code', ':employee_name', ':department_name', ':leave_type',
            ':start_date', ':end_date', ':total_days', ':reason_text', ':applied_on',
            ':status_label', ':reject_reason', ':created_by_employee_id', ':assigned_to_employee_id',
        ];
        if ($hasLegacyEmployeeId) {
            $insertColumns[] = 'employee_id';
            $insertValues[] = ':employee_id';
        }

        $stmt = $pdo->prepare(
            'INSERT INTO leave_requests (' . implode(', ', $insertColumns) . ')
             VALUES (' . implode(', ', $insertValues) . ')'
        );

        $insertParams = [
            'employee_code' => leave_str_cut($employeeCode, 40),
            'employee_name' => leave_str_cut($employeeName, 140),
            'department_name' => $departmentName !== '' ? leave_str_cut($departmentName, 100) : null,
            'leave_type' => leave_str_cut($leaveType, 60),
            'start_date' => $startDate,
            'end_date' => $endDate,
            'total_days' => $totalDays,
            'reason_text' => $reasonText !== '' ? $reasonText : null,
            'applied_on' => date('Y-m-d'),
            'status_label' => $statusLabel,
            'reject_reason' => $rejectReason !== '' ? $rejectReason : null,
            'created_by_employee_id' => $employeeId > 0 ? $employeeId : null,
            'assigned_to_employee_id' => $employeeId > 0 ? $employeeId : null,
        ];
        if ($hasLegacyEmployeeId) {
            $insertParams['employee_id'] = $legacyEmployeeId;
        }

        $stmt->execute($insertParams);
        $id = (int) $pdo->lastInsertId();
    }

    send_json(['ok' => true, 'id' => $id, 'message' => 'Leave request saved']);
} catch (Throwable $e) {
    send_json(['ok' => false, 'message' => 'Failed to save leave request', 'error' => $e->getMessage()], 500);
}
