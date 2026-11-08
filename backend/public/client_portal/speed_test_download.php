<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    send_json(['ok' => false, 'message' => 'Method not allowed'], 405);
}

$user = require_auth();
$roleName = strtolower(trim((string) ($user['role_name'] ?? '')));
if (!is_access_matrix_disabled() && $roleName !== 'client') {
    send_json(['ok' => false, 'message' => 'Forbidden: client access only'], 403);
}

$requestedSize = isset($_GET['size']) ? (int) $_GET['size'] : 0;
$minSize = 256 * 1024;
$maxSize = 20 * 1024 * 1024;
$size = max($minSize, min($maxSize, $requestedSize > 0 ? $requestedSize : 6 * 1024 * 1024));

header('Content-Type: application/octet-stream');
header('Content-Length: ' . $size);
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$chunk = random_bytes(8192);
$remaining = $size;

while ($remaining > 0) {
    $writeSize = $remaining >= 8192 ? 8192 : $remaining;
    echo substr($chunk, 0, $writeSize);
    $remaining -= $writeSize;

    if (ob_get_level() > 0) {
        ob_flush();
    }
    flush();
}
