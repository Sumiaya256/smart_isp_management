<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/database.php';
require_once dirname(__DIR__, 2) . '/config/auth.php';
require_once __DIR__ . '/helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json(['ok' => false, 'message' => 'Method not allowed'], 405);
}

$user = require_page_permission('Bulk Client Import', 'edit');

try {
    $pdo = db();
    ensure_mikrotik_bulk_table($pdo);
    cleanup_expired_mikrotik_rows($pdo);

    $scope = mikrotik_scope_sql($user, 'r', 'scope_employee_id');

    if ($scope['sql'] !== '') {
        $sql = 'DELETE FROM mikrotik_bulk_import_rows r WHERE 1 = 1' . $scope['sql'];
        $stmt = $pdo->prepare($sql);
        $stmt->execute($scope['params']);
        $deleted = $stmt->rowCount();
    } else {
        $stmt = $pdo->prepare('DELETE FROM mikrotik_bulk_import_rows');
        $stmt->execute();
        $deleted = $stmt->rowCount();
    }

    send_json(['ok' => true, 'message' => 'Rows cleared', 'deleted' => $deleted]);
} catch (Throwable $e) {
    send_json(['ok' => false, 'message' => 'Failed to clear rows', 'error' => $e->getMessage()], 500);
}
