<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/database.php';
require_once dirname(__DIR__, 2) . '/config/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json(['ok' => false, 'message' => 'Method not allowed'], 405);
}

require_page_permission('Internet Packages', 'edit');

$input = read_json_input();
$id = (int) ($input['id'] ?? 0);

if ($id <= 0) {
    send_json(['ok' => false, 'message' => 'id is required'], 422);
}

try {
    $pdo = db();

    $usedStmt = $pdo->prepare('SELECT COUNT(*) FROM clients WHERE package_id = :id');
    $usedStmt->execute(['id' => $id]);
    $usedCount = (int) $usedStmt->fetchColumn();

    if ($usedCount > 0) {
        $deactivateStmt = $pdo->prepare('UPDATE internet_packages SET is_active = 0 WHERE id = :id');
        $deactivateStmt->execute(['id' => $id]);

        send_json([
            'ok' => true,
            'message' => 'Package is assigned to clients. Marked as inactive instead of deleting.',
            'mode' => 'deactivated',
        ]);
    }

    $deleteStmt = $pdo->prepare('DELETE FROM internet_packages WHERE id = :id');
    $deleteStmt->execute(['id' => $id]);

    send_json([
        'ok' => true,
        'message' => 'Package deleted successfully',
        'mode' => 'deleted',
    ]);
} catch (Throwable $e) {
    send_json([
        'ok' => false,
        'message' => 'Failed to delete package',
        'error' => $e->getMessage(),
    ], 500);
}
