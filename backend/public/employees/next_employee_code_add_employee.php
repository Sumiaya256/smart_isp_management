<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/read_helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    send_json(['ok' => false, 'message' => 'Method not allowed'], 405);
}

require_page_permission('Add Employee', 'view');

try {
    $pdo = db();

    send_json([
        'ok' => true,
        'next_employee_code' => fetch_next_employee_code_value($pdo),
    ]);
} catch (Throwable $e) {
    send_json([
        'ok' => false,
        'message' => 'Failed to get next employee code',
        'error' => $e->getMessage(),
    ], 500);
}