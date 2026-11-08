<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/database.php';
require_once dirname(__DIR__, 2) . '/config/auth.php';
require_once __DIR__ . '/read_helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    send_json(['ok' => false, 'message' => 'Method not allowed'], 405);
}

$user = require_auth();
require_module_permission('HR & Payroll', 'view');

try {
    $pdo = db();

    send_json([
        'ok' => true,
        'employees' => fetch_employee_list_payload($pdo),
    ]);
} catch (Throwable $e) {
    send_json([
        'ok' => false,
        'message' => 'Failed to fetch employees',
        'error' => $e->getMessage(),
    ], 500);
}
