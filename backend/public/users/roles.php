<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/database.php';
require_once dirname(__DIR__, 2) . '/config/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    send_json(['ok' => false, 'message' => 'Method not allowed'], 405);
}

require_roles(['Admin', 'Administration']);

try {
    $pdo = db();
    $stmt = $pdo->query('SELECT id, name, description FROM roles ORDER BY id ASC');

    send_json([
        'ok' => true,
        'roles' => $stmt->fetchAll(),
    ]);
} catch (Throwable $e) {
    send_json(['ok' => false, 'message' => 'Failed to fetch roles', 'error' => $e->getMessage()], 500);
}
