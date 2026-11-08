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

    $stmt = $pdo->query(
        'SELECT u.id, u.full_name, u.email, u.phone, u.is_active, u.last_login_at, r.name AS role_name, u.created_at
         FROM users u
         INNER JOIN roles r ON r.id = u.role_id
         ORDER BY u.id DESC'
    );

    send_json([
        'ok' => true,
        'users' => $stmt->fetchAll(),
    ]);
} catch (Throwable $e) {
    send_json(['ok' => false, 'message' => 'Failed to fetch users', 'error' => $e->getMessage()], 500);
}
