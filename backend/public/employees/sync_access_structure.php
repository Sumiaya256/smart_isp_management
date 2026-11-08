<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/database.php';
require_once dirname(__DIR__, 2) . '/config/auth.php';
require_once __DIR__ . '/access_matrix.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json(['ok' => false, 'message' => 'Method not allowed'], 405);
}

$user = require_auth();

if (!user_has_any_page_action_access($user, ['Department', 'Position'], 'edit')) {
    send_json(['ok' => false, 'message' => 'Forbidden: insufficient page permission', 'pages' => ['Department', 'Position'], 'action' => 'edit'], 403);
}

try {
    $pdo = db();
    $stats = sync_department_and_position_access($pdo);

    send_json([
        'ok' => true,
        'message' => 'Department and positional access structure synced successfully.',
        'stats' => $stats,
    ]);
} catch (Throwable $e) {
    send_json([
        'ok' => false,
        'message' => 'Failed to sync access structure',
        'error' => $e->getMessage(),
    ], 500);
}
