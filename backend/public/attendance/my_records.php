<?php

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    send_json(['ok' => false, 'message' => 'Method not allowed'], 405);
}

$user = require_page_permission('Attendance', 'view');

try {
    $pdo = db();
    ensure_attendance_schema($pdo);

    $employeeId = attendance_resolve_employee_id($pdo, $user);
    if (!$employeeId) {
        send_json([
            'ok' => true,
            'employee' => null,
            'records' => [],
            'total_count' => 0,
        ]);
    }

    $limit = max(10, min(1000, (int) ($_GET['limit'] ?? 365)));

    $empStmt = $pdo->prepare(
        'SELECT e.id, e.employee_code, e.full_name, e.join_date,
                COALESCE(d.department_name, "-") AS department_name,
                COALESCE(p.position_name, "-") AS position_name
         FROM employees e
         LEFT JOIN departments d ON d.id = e.department_id
         LEFT JOIN positions p ON p.id = e.position_id
         WHERE e.id = :id
         LIMIT 1'
    );
    $empStmt->execute(['id' => $employeeId]);
    $employee = $empStmt->fetch();

    $stmt = $pdo->prepare(
        'SELECT id, attendance_date, status, remarks, check_in_at, check_out_at, created_at, updated_at
         FROM attendance
         WHERE employee_id = :employee_id
         ORDER BY attendance_date DESC, id DESC
         LIMIT ' . (int) $limit
    );
    $stmt->execute(['employee_id' => $employeeId]);
    $rows = $stmt->fetchAll();

    $records = array_map(static function (array $row): array {
        $checkInAt = (string) ($row['check_in_at'] ?? '');
        $checkOutAt = (string) ($row['check_out_at'] ?? '');
        $workedMinutes = attendance_minutes_between($checkInAt, $checkOutAt);

        return [
            'id' => (int) ($row['id'] ?? 0),
            'date' => (string) ($row['attendance_date'] ?? ''),
            'status' => ucfirst((string) ($row['status'] ?? 'present')),
            'remarks' => (string) ($row['remarks'] ?? ''),
            'check_in_time' => attendance_format_time($checkInAt),
            'check_out_time' => attendance_format_time($checkOutAt),
            'worked_minutes' => $workedMinutes,
            'worked_hours' => round($workedMinutes / 60, 2),
            'created_at' => (string) ($row['created_at'] ?? ''),
            'updated_at' => (string) ($row['updated_at'] ?? ''),
        ];
    }, $rows);

    send_json([
        'ok' => true,
        'employee' => $employee ? [
            'id' => (int) ($employee['id'] ?? 0),
            'employee_code' => (string) ($employee['employee_code'] ?? ''),
            'full_name' => (string) ($employee['full_name'] ?? ''),
            'department_name' => (string) ($employee['department_name'] ?? '-'),
            'position_name' => (string) ($employee['position_name'] ?? '-'),
            'join_date' => (string) ($employee['join_date'] ?? ''),
        ] : null,
        'records' => $records,
        'total_count' => count($records),
    ]);
} catch (Throwable $e) {
    send_json([
        'ok' => false,
        'message' => 'Failed to load attendance records',
        'error' => $e->getMessage(),
    ], 500);
}
