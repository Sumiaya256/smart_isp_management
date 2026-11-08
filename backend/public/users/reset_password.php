<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/database.php';
require_once dirname(__DIR__, 2) . '/config/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json(['ok' => false, 'message' => 'Method not allowed'], 405);
}

require_roles(['Admin']);

$input = read_json_input();
$userId = (int) ($input['user_id'] ?? 0);
$newPassword = (string) ($input['new_password'] ?? '');

if ($userId <= 0 || $newPassword === '') {
    send_json(['ok' => false, 'message' => 'user_id and new_password are required'], 422);
}

if (strlen($newPassword) < 8) {
    send_json(['ok' => false, 'message' => 'Password must be at least 8 characters'], 422);
}

try {
    $pdo = db();

    $stmt = $pdo->prepare('UPDATE users SET password_hash = :password_hash WHERE id = :id');
    $stmt->execute([
        'password_hash' => password_hash($newPassword, PASSWORD_DEFAULT),
        'id' => $userId,
    ]);

    if ($stmt->rowCount() === 0) {
        send_json(['ok' => false, 'message' => 'User not found'], 404);
    }

    send_json(['ok' => true, 'message' => 'Password reset successfully']);
} catch (Throwable $e) {
    send_json(['ok' => false, 'message' => 'Failed to reset password', 'error' => $e->getMessage()], 500);
}
