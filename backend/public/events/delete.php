<?php

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json(['ok' => false, 'message' => 'Method not allowed'], 405);
}

$user = require_auth();
require_module_permission('Events & Holidays', 'edit', true);

$input = read_json_input();
$id = (int) ($input['id'] ?? 0);

if ($id <= 0) {
    send_json(['ok' => false, 'message' => 'Valid id is required'], 422);
}

try {
    $pdo = db();
    ensure_events_schema($pdo);
    enforce_events_scope($pdo, $user, $id);

    $stmt = $pdo->prepare('DELETE FROM events_holidays WHERE id = :id');
    $stmt->execute(['id' => $id]);

    send_json(['ok' => true, 'message' => 'Event deleted']);
} catch (Throwable $e) {
    send_json(['ok' => false, 'message' => 'Failed to delete event', 'error' => $e->getMessage()], 500);
}
