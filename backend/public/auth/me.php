<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    send_json([
        'ok' => false,
        'message' => 'Method not allowed',
    ], 405);
}

$user = require_auth();

send_json([
    'ok' => true,
    'user' => $user,
]);
