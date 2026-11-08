<?php

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json(['ok' => false, 'message' => 'Method not allowed'], 405);
}

$user = require_page_permission('Attendance', 'edit');

try {
    $pdo = db();
    ensure_attendance_schema($pdo);

    $employeeId = attendance_resolve_employee_id($pdo, $user);
    if (!$employeeId) {
        send_json([
            'ok' => false,
            'message' => 'No linked employee profile found for this account',
        ], 422);
    }

    $today = date('Y-m-d');
    $now = date('Y-m-d H:i:s');

    $getStmt = $pdo->prepare(
        'SELECT id, employee_id, attendance_date, status, remarks, check_in_at, check_out_at, source_label
         FROM attendance
         WHERE employee_id = :employee_id AND attendance_date = :attendance_date
         LIMIT 1'
    );
    $getStmt->execute([
        'employee_id' => $employeeId,
        'attendance_date' => $today,
    ]);
    $existing = $getStmt->fetch();

    $mode = 'check_in';
    if (!$existing) {
        $insertStmt = $pdo->prepare(
            'INSERT INTO attendance (
                employee_id, attendance_date, status, remarks,
                check_in_at, check_out_at, source_label
             ) VALUES (
                :employee_id, :attendance_date, :status, :remarks,
                :check_in_at, :check_out_at, :source_label
             )'
        );

        $insertStmt->execute([
            'employee_id' => $employeeId,
            'attendance_date' => $today,
            'status' => 'present',
            'remarks' => 'Biometric verified',
            'check_in_at' => $now,
            'check_out_at' => null,
            'source_label' => 'fingerprint',
        ]);
    } else {
        $mode = 'check_out';

        $updateStmt = $pdo->prepare(
            'UPDATE attendance
             SET status = :status,
                 source_label = :source_label,
                 remarks = :remarks,
                 check_in_at = COALESCE(check_in_at, :fallback_check_in),
                 check_out_at = :check_out_at
             WHERE id = :id'
        );
        $updateStmt->execute([
            'status' => 'present',
            'source_label' => 'fingerprint',
            'remarks' => 'Biometric verified',
            'fallback_check_in' => $now,
            'check_out_at' => $now,
            'id' => (int) $existing['id'],
        ]);
    }

    $finalStmt = $pdo->prepare(
        'SELECT a.id, a.attendance_date, a.status, a.remarks, a.check_in_at, a.check_out_at,
                e.id AS employee_id, e.employee_code, e.full_name,
                COALESCE(d.department_name, "-") AS department_name
         FROM attendance a
         INNER JOIN employees e ON e.id = a.employee_id
         LEFT JOIN departments d ON d.id = e.department_id
         WHERE a.employee_id = :employee_id AND a.attendance_date = :attendance_date
         LIMIT 1'
    );
    $finalStmt->execute([
        'employee_id' => $employeeId,
        'attendance_date' => $today,
    ]);
    $row = $finalStmt->fetch();

    $workedMinutes = attendance_minutes_between((string) ($row['check_in_at'] ?? ''), (string) ($row['check_out_at'] ?? ''));

    send_json([
        'ok' => true,
        'message' => $mode === 'check_in' ? 'Entry time captured successfully' : 'Exit time updated successfully',
        'mode' => $mode,
        'record' => [
            'id' => (int) ($row['id'] ?? 0),
            'date' => (string) ($row['attendance_date'] ?? ''),
            'status' => ucfirst((string) ($row['status'] ?? 'present')),
            'remarks' => (string) ($row['remarks'] ?? ''),
            'check_in_time' => attendance_format_time((string) ($row['check_in_at'] ?? '')),
            'check_out_time' => attendance_format_time((string) ($row['check_out_at'] ?? '')),
            'worked_minutes' => $workedMinutes,
            'employee' => [
                'id' => (int) ($row['employee_id'] ?? 0),
                'code' => (string) ($row['employee_code'] ?? ''),
                'name' => (string) ($row['full_name'] ?? ''),
                'department' => (string) ($row['department_name'] ?? '-'),
            ],
        ],
    ]);
} catch (Throwable $e) {
    send_json([
        'ok' => false,
        'message' => 'Failed to record fingerprint attendance',
        'error' => $e->getMessage(),
    ], 500);
}
