<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/database.php';
require_once dirname(__DIR__, 2) . '/config/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    send_json([
        'ok' => false,
        'message' => 'Method not allowed',
    ], 405);
}

require_page_permission('Support Team', 'view');

try {
    $pdo = db();

    $stmt = $pdo->query(
        'SELECT
            e.id,
            COALESCE(NULLIF(TRIM(e.employee_code), ""), CONCAT("EMP-", e.id)) AS employee_code,
            COALESCE(NULLIF(TRIM(e.full_name), ""), "Unknown") AS full_name,
            COALESCE(NULLIF(TRIM(e.email), ""), "") AS email,
            COALESCE(NULLIF(TRIM(e.phone), ""), "") AS phone,
            COALESCE(NULLIF(TRIM(d.department_name), ""), "") AS department_name,
            COALESCE(NULLIF(TRIM(p.position_name), ""), "Support Team") AS position_name,
            COALESCE(NULLIF(TRIM(e.employment_status), ""), "active") AS employment_status
         FROM employees e
         LEFT JOIN departments d ON d.id = e.department_id
         LEFT JOIN positions p ON p.id = e.position_id
         WHERE LOWER(COALESCE(d.department_name, "")) = "support"
         ORDER BY CASE WHEN LOWER(COALESCE(e.employment_status, "active")) = "active" THEN 0 ELSE 1 END,
                  e.full_name ASC'
    );

    $rows = $stmt->fetchAll();

    $members = array_map(static function (array $row): array {
        $status = strtolower(trim((string) ($row['employment_status'] ?? 'active')));
        return [
            'id' => (int) ($row['id'] ?? 0),
            'employee_code' => (string) ($row['employee_code'] ?? ''),
            'full_name' => (string) ($row['full_name'] ?? ''),
            'email' => (string) ($row['email'] ?? ''),
            'phone' => (string) ($row['phone'] ?? ''),
            'department_name' => (string) ($row['department_name'] ?? ''),
            'position_name' => (string) ($row['position_name'] ?? ''),
            'is_online' => $status === 'active',
            'employment_status' => $status,
        ];
    }, $rows ?: []);

    send_json([
        'ok' => true,
        'members' => $members,
        'summary' => [
            'total' => count($members),
            'online' => count(array_filter($members, static fn (array $m): bool => !empty($m['is_online']))),
        ],
    ]);
} catch (Throwable $e) {
    send_json([
        'ok' => false,
        'message' => 'Failed to load support team',
        'error' => $e->getMessage(),
    ], 500);
}
