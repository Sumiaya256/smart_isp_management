<?php

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    send_json(['ok' => false, 'message' => 'Method not allowed'], 405);
}

$user = require_auth();
require_module_permission('Leave Management', 'view', true);

try {
    $pdo = db();
    ensure_leave_schema($pdo);

    $employeeId = (int) ($user['id'] ?? 0);

    $profile = [
        'employee_id' => $employeeId,
        'employee_code' => '',
        'full_name' => (string) ($user['full_name'] ?? ''),
        'email' => (string) ($user['email'] ?? ''),
        'phone' => '',
        'department_name' => (string) ($user['role_name'] ?? ''),
        'position_name' => (string) ($user['position_name'] ?? ''),
        'manager_name' => '',
        'join_date' => '',
    ];

    $row = null;
    if (leave_table_exists($pdo, 'employees')) {
        if ($employeeId > 0) {
            $stmt = $pdo->prepare(
                'SELECT e.id, e.employee_code, e.full_name, e.email, e.phone, e.join_date,
                        COALESCE(d.department_name, "") AS department_name,
                        COALESCE(p.position_name, "") AS position_name,
                        COALESCE(ep.manager_name, "") AS manager_name
                 FROM employees e
                 LEFT JOIN departments d ON d.id = e.department_id
                 LEFT JOIN positions p ON p.id = e.position_id
                 LEFT JOIN employee_profiles ep ON ep.employee_id = e.id
                 WHERE e.id = :id
                 LIMIT 1'
            );
            $stmt->execute(['id' => $employeeId]);
            $row = $stmt->fetch();
        }

        if (!$row) {
            $email = trim((string) ($user['email'] ?? ''));
            if ($email !== '') {
                $stmt = $pdo->prepare(
                    'SELECT e.id, e.employee_code, e.full_name, e.email, e.phone, e.join_date,
                            COALESCE(d.department_name, "") AS department_name,
                            COALESCE(p.position_name, "") AS position_name,
                            COALESCE(ep.manager_name, "") AS manager_name
                     FROM employees e
                     LEFT JOIN departments d ON d.id = e.department_id
                     LEFT JOIN positions p ON p.id = e.position_id
                     LEFT JOIN employee_profiles ep ON ep.employee_id = e.id
                     WHERE e.email = :email
                     LIMIT 1'
                );
                $stmt->execute(['email' => $email]);
                $row = $stmt->fetch();
            }
        }
    }

    if ($row) {
        $profile = [
            'employee_id' => (int) $row['id'],
            'employee_code' => (string) ($row['employee_code'] ?? ''),
            'full_name' => (string) ($row['full_name'] ?? ''),
            'email' => (string) ($row['email'] ?? ''),
            'phone' => (string) ($row['phone'] ?? ''),
            'department_name' => (string) ($row['department_name'] ?? ''),
            'position_name' => (string) ($row['position_name'] ?? ''),
            'manager_name' => (string) ($row['manager_name'] ?? ''),
            'join_date' => (string) ($row['join_date'] ?? ''),
        ];
    }

    if ($profile['employee_code'] === '') {
        $profile['employee_code'] = 'EMP' . str_pad((string) max(1, $employeeId), 3, '0', STR_PAD_LEFT);
    }

        $managerRows = [];
        if (leave_table_exists($pdo, 'employees')) {
                try {
                        $managerStmt = $pdo->query(
                                'SELECT e.full_name, COALESCE(p.position_name, "Manager") AS position_name
                                 FROM employees e
                                 LEFT JOIN positions p ON p.id = e.position_id
                                 WHERE e.employment_status = "active"
                                     AND (
                                                LOWER(COALESCE(p.position_name, "")) LIKE "%manager%"
                                         OR LOWER(COALESCE(p.position_name, "")) LIKE "%head%"
                                         OR LOWER(COALESCE(p.position_name, "")) LIKE "%lead%"
                                     )
                                 ORDER BY e.full_name ASC
                                 LIMIT 120'
                        );
                        $managerRows = $managerStmt ? $managerStmt->fetchAll() : [];
                } catch (Throwable $e) {
                        $managerRows = [];
                }
        }
    $managers = [];
    foreach ($managerRows as $m) {
        $name = trim((string) ($m['full_name'] ?? ''));
        if ($name === '') {
            continue;
        }
        $managers[] = [
            'name' => $name,
            'label' => $name . ' (' . trim((string) ($m['position_name'] ?? 'Manager')) . ')',
        ];
    }

    $year = (int) date('Y');
    $balanceBase = [
        'sick' => 14,
        'casual' => 10,
        'annual' => 24,
        'emergency' => 5,
        'maternity' => 90,
        'unpaid' => 365,
    ];

    $usageStmt = $pdo->prepare(
        'SELECT LOWER(TRIM(leave_type)) AS leave_type_key, SUM(total_days) AS used_days
         FROM leave_requests
         WHERE status_label = "approved"
           AND YEAR(start_date) = :year
           AND (
                employee_code = :employee_code
             OR created_by_employee_id = :employee_id
           )
         GROUP BY LOWER(TRIM(leave_type))'
    );
    $usageStmt->execute([
        'year' => $year,
        'employee_code' => (string) $profile['employee_code'],
        'employee_id' => $employeeId,
    ]);

    $usedMap = [];
    foreach ($usageStmt->fetchAll() as $row) {
        $key = (string) ($row['leave_type_key'] ?? '');
        if ($key === '') {
            continue;
        }
        $usedMap[$key] = (int) ($row['used_days'] ?? 0);
    }

    $balances = [];
    foreach ($balanceBase as $type => $quota) {
        $used = (int) ($usedMap[$type] ?? 0);
        $left = max(0, $quota - $used);
        $balances[$type] = [
            'quota' => $quota,
            'used' => $used,
            'left' => $left,
        ];
    }

    $pendingStmt = $pdo->prepare(
        'SELECT COUNT(*)
         FROM leave_requests
         WHERE status_label = "pending"
           AND (
                employee_code = :employee_code
             OR created_by_employee_id = :employee_id
           )'
    );
    $pendingStmt->execute([
        'employee_code' => (string) $profile['employee_code'],
        'employee_id' => $employeeId,
    ]);

    $recentStmt = $pdo->prepare(
        'SELECT id, leave_type, start_date, end_date, total_days, status_label, reason_text, applied_on
         FROM leave_requests
         WHERE employee_code = :employee_code
            OR created_by_employee_id = :employee_id
         ORDER BY id DESC
         LIMIT 8'
    );
    $recentStmt->execute([
        'employee_code' => (string) $profile['employee_code'],
        'employee_id' => $employeeId,
    ]);

    send_json([
        'ok' => true,
        'profile' => $profile,
        'managers' => $managers,
        'balances' => $balances,
        'pending_count' => (int) $pendingStmt->fetchColumn(),
        'recent_requests' => $recentStmt->fetchAll(),
        'current_year' => $year,
    ]);
} catch (Throwable $e) {
    send_json([
        'ok' => false,
        'message' => 'Failed to load leave apply context',
        'error' => $e->getMessage(),
    ], 500);
}
