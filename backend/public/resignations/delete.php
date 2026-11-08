<?php

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json(['ok' => false, 'message' => 'Method not allowed'], 405);
}

$user = require_explicit_page_permission('Resignation', 'edit');

$input = read_json_input();
$resignationId = clean_i($input, 'resignationId');

if ($resignationId <= 0) {
    send_json([
        'ok' => false,
        'message' => 'resignationId is required',
    ], 422);
}

try {
    $pdo = db();
    ensure_resignation_schema($pdo);

    $stmt = $pdo->prepare('DELETE FROM hr_resignations WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $resignationId]);

    if ($stmt->rowCount() === 0) {
        send_json([
            'ok' => false,
            'message' => 'Resignation not found',
        ], 404);
    }

    send_json([
        'ok' => true,
        'message' => 'Resignation deleted successfully',
    ]);
} catch (Throwable $e) {
    send_json([
        'ok' => false,
        'message' => 'Failed to delete resignation',
        'error' => $e->getMessage(),
    ], 500);
}
