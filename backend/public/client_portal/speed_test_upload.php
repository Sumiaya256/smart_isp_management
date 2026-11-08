<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json(['ok' => false, 'message' => 'Method not allowed'], 405);
}

$user = require_auth();
$roleName = strtolower(trim((string) ($user['role_name'] ?? '')));
if (!is_access_matrix_disabled() && $roleName !== 'client') {
    send_json(['ok' => false, 'message' => 'Forbidden: client access only'], 403);
}

$raw = file_get_contents('php://input');
$bytes = is_string($raw) ? strlen($raw) : 0;

send_json([
    'ok' => true,
    'received_bytes' => $bytes,
    'message' => 'Upload payload received',
]);
