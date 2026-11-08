<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/database.php';
require_once dirname(__DIR__, 2) . '/config/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json(['ok' => false, 'message' => 'Method not allowed'], 405);
}

$actor = require_roles(['Admin']);

$input = read_json_input();
$userId = (int) ($input['user_id'] ?? 0);

if ($userId <= 0) {
    send_json(['ok' => false, 'message' => 'user_id is required'], 422);
}

if ($actor['id'] === $userId) {
    send_json(['ok' => false, 'message' => 'You cannot deactivate your own account'], 422);
}

try {
    $pdo = db();

    $stmt = $pdo->prepare('UPDATE users SET is_active = 0 WHERE id = :id');
    $stmt->execute(['id' => $userId]);

    if ($stmt->rowCount() === 0) {
        send_json(['ok' => false, 'message' => 'User not found or already inactive'], 404);
    }

    send_json(['ok' => true, 'message' => 'User deactivated successfully']);
} catch (Throwable $e) {
    send_json(['ok' => false, 'message' => 'Failed to deactivate user', 'error' => $e->getMessage()], 500);
}
