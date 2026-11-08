<?php

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json(['ok' => false, 'message' => 'Method not allowed'], 405);
}

$user = require_explicit_page_permission('Resign Rule', 'edit');

$input = read_json_input();
$ruleId = clean_i($input, 'ruleId');

if ($ruleId <= 0) {
    send_json([
        'ok' => false,
        'message' => 'ruleId is required',
    ], 422);
}

try {
    $pdo = db();
    ensure_resignation_schema($pdo);

    $stmt = $pdo->prepare('DELETE FROM hr_resignation_rules WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $ruleId]);

    if ($stmt->rowCount() === 0) {
        send_json([
            'ok' => false,
            'message' => 'Rule not found',
        ], 404);
    }

    send_json([
        'ok' => true,
        'message' => 'Resign rule deleted successfully',
    ]);
} catch (Throwable $e) {
    send_json([
        'ok' => false,
        'message' => 'Failed to delete resign rule',
        'error' => $e->getMessage(),
    ], 500);
}
