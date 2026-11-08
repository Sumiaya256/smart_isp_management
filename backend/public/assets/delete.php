<?php

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $_SERVER['REQUEST_METHOD'] !== 'DELETE') {
    send_json(['ok' => false, 'message' => 'Method not allowed'], 405);
}

$user = require_auth();
require_module_permission('Assets', 'edit', true);

$input = read_json_input();
$id = (int) ($input['id'] ?? 0);
if ($id <= 0) {
    send_json(['ok' => false, 'message' => 'id is required'], 422);
}

try {
    $pdo = db();
    ensure_assets_schema($pdo);
    enforce_assets_scope($pdo, $user, $id);

    $stmt = $pdo->prepare('DELETE FROM assets_items WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $id]);

    if ($stmt->rowCount() <= 0) {
        send_json(['ok' => false, 'message' => 'Asset not found'], 404);
    }

    send_json(['ok' => true, 'message' => 'Asset deleted']);
} catch (Throwable $e) {
    send_json(['ok' => false, 'message' => 'Failed to delete asset', 'error' => $e->getMessage()], 500);
}
