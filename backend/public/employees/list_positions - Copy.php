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

    send_json([
        'ok' => true,
        'positions' => fetch_position_payload($pdo),
    ]);
} catch (Throwable $e) {
    send_json([
        'ok' => false,
        'message' => 'Failed to fetch positions',
        'error' => $e->getMessage(),
    ], 500);
}
