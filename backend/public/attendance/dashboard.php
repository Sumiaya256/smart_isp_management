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

    $today = date('Y-m-d');
    $todayRecord = null;
    if ($employeeId) {
        $todayStmt = $pdo->prepare(
            'SELECT attendance_date, status, remarks, check_in_at, check_out_at
             FROM attendance
             WHERE employee_id = :employee_id AND attendance_date = :attendance_date
             LIMIT 1'
        );
        $todayStmt->execute([
            'employee_id' => $employeeId,
            'attendance_date' => $today,
        ]);
        $todayRow = $todayStmt->fetch();

        if ($todayRow) {
            $todayMinutes = attendance_minutes_between((string) ($todayRow['check_in_at'] ?? ''), (string) ($todayRow['check_out_at'] ?? ''));
            $todayRecord = [
                'date' => (string) ($todayRow['attendance_date'] ?? ''),
                'status' => ucfirst((string) ($todayRow['status'] ?? 'present')),
                'remarks' => (string) ($todayRow['remarks'] ?? ''),
                'check_in_time' => attendance_format_time((string) ($todayRow['check_in_at'] ?? '')),
                'check_out_time' => attendance_format_time((string) ($todayRow['check_out_at'] ?? '')),
                'worked_minutes' => $todayMinutes,
                'worked_hours' => round($todayMinutes / 60, 2),
            ];
        }
    }

    $statsStmt = $pdo->prepare(
        'SELECT
            SUM(CASE WHEN status = "present" THEN 1 ELSE 0 END) AS present_count,
            SUM(CASE WHEN status = "absent" THEN 1 ELSE 0 END) AS absent_count,
            SUM(CASE WHEN status = "leave" THEN 1 ELSE 0 END) AS leave_count,
            COUNT(*) AS total_count
         FROM attendance
         WHERE attendance_date = :attendance_date'
    );
    $statsStmt->execute(['attendance_date' => $today]);
    $stats = $statsStmt->fetch() ?: [];

    $birthdaysStmt = $pdo->query(
        'SELECT
            e.id,
            e.full_name,
            e.employee_code,
            COALESCE(d.department_name, "-") AS department_name,
            ep.dob,
            CASE
                WHEN DATE_FORMAT(ep.dob, "%m-%d") >= DATE_FORMAT(CURDATE(), "%m-%d")
                THEN DATEDIFF(
                    STR_TO_DATE(CONCAT(YEAR(CURDATE()), "-", DATE_FORMAT(ep.dob, "%m-%d")), "%Y-%m-%d"),
                    CURDATE()
                )
                ELSE DATEDIFF(
                    STR_TO_DATE(CONCAT(YEAR(CURDATE()) + 1, "-", DATE_FORMAT(ep.dob, "%m-%d")), "%Y-%m-%d"),
                    CURDATE()
                )
            END AS days_left
         FROM employees e
         LEFT JOIN departments d ON d.id = e.department_id
         LEFT JOIN employee_profiles ep ON ep.employee_id = e.id
         WHERE e.employment_status = "active"
           AND ep.dob IS NOT NULL
           AND ep.dob <> "0000-00-00"
         ORDER BY days_left ASC, e.full_name ASC
         LIMIT 12'
    );
    $birthdayRows = $birthdaysStmt->fetchAll();
    $birthdays = array_values(array_map(static function (array $row): ?array {
        $daysLeft = (int) ($row['days_left'] ?? 0);
        if ($daysLeft > 45) {
            return null;
        }

        return [
            'id' => (int) ($row['id'] ?? 0),
            'full_name' => (string) ($row['full_name'] ?? ''),
            'employee_code' => (string) ($row['employee_code'] ?? ''),
            'department_name' => (string) ($row['department_name'] ?? '-'),
            'dob' => (string) ($row['dob'] ?? ''),
            'days_left' => $daysLeft,
        ];
    }, $birthdayRows));
    $birthdays = array_values(array_filter($birthdays, static fn ($row) => is_array($row)));

    $newJoinersStmt = $pdo->query(
        'SELECT
            e.id,
            e.full_name,
            e.employee_code,
            e.join_date,
            COALESCE(d.department_name, "-") AS department_name,
            COALESCE(p.position_name, "-") AS position_name,
            DATEDIFF(CURDATE(), e.join_date) AS days_since_join
         FROM employees e
         LEFT JOIN departments d ON d.id = e.department_id
         LEFT JOIN positions p ON p.id = e.position_id
         WHERE e.employment_status = "active"
           AND e.join_date IS NOT NULL
           AND e.join_date >= DATE_SUB(CURDATE(), INTERVAL 120 DAY)
         ORDER BY e.join_date DESC, e.id DESC
         LIMIT 12'
    );
    $newJoiners = array_map(static function (array $row): array {
        return [
            'id' => (int) ($row['id'] ?? 0),
            'full_name' => (string) ($row['full_name'] ?? ''),
            'employee_code' => (string) ($row['employee_code'] ?? ''),
            'join_date' => (string) ($row['join_date'] ?? ''),
            'department_name' => (string) ($row['department_name'] ?? '-'),
            'position_name' => (string) ($row['position_name'] ?? '-'),
            'days_since_join' => (int) ($row['days_since_join'] ?? 0),
        ];
    }, $newJoinersStmt->fetchAll());

    send_json([
        'ok' => true,
        'today' => [
            'date' => $today,
            'present_count' => (int) ($stats['present_count'] ?? 0),
            'absent_count' => (int) ($stats['absent_count'] ?? 0),
            'leave_count' => (int) ($stats['leave_count'] ?? 0),
            'total_count' => (int) ($stats['total_count'] ?? 0),
            'employee_record' => $todayRecord,
        ],
        'upcoming_birthdays' => $birthdays,
        'new_joiners' => $newJoiners,
    ]);
} catch (Throwable $e) {
    send_json([
        'ok' => false,
        'message' => 'Failed to load attendance dashboard data',
        'error' => $e->getMessage(),
    ], 500);
}
