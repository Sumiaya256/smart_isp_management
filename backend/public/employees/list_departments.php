<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/database.php';
require_once dirname(__DIR__, 2) . '/config/auth.php';
require_once __DIR__ . '/read_helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    send_json(['ok' => false, 'message' => 'Method not allowed'], 405);
}

require_page_permission('Employee List', 'view');

try {
    $pdo = db();
    if (isset($_GET['sync_defaults']) && (string) $_GET['sync_defaults'] === '1') {
        sync_department_and_position_access($pdo);
    }
    ensure_department_access_table($pdo);

    $deptRows = $pdo->query('SELECT id, department_name FROM departments ORDER BY department_name ASC')->fetchAll();

    $accessRows = $pdo->query('SELECT department_id, module_name FROM department_access_modules ORDER BY module_name ASC')->fetchAll();
    $accessMap = [];
    foreach ($accessRows as $row) {
        $deptId = (int) $row['department_id'];
        if (!isset($accessMap[$deptId])) {
            $accessMap[$deptId] = [];
        }
        $accessMap[$deptId][] = (string) $row['module_name'];
    }

    $departments = array_map(static function (array $row) use ($accessMap): array {
        $id = (int) $row['id'];
        return [
            'id' => $id,
            'name' => (string) $row['department_name'],
            'options' => $accessMap[$id] ?? [],
        ];
    }, $deptRows);

    send_json([
        'ok' => true,
        'departments' => fetch_department_payload($pdo),
    ]);
} catch (Throwable $e) {
    send_json([
        'ok' => false,
        'message' => 'Failed to fetch departments',
        'error' => $e->getMessage(),
    ], 500);
}
