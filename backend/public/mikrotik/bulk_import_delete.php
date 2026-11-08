<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/database.php';
require_once dirname(__DIR__, 2) . '/config/auth.php';
require_once __DIR__ . '/helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json(['ok' => false, 'message' => 'Method not allowed'], 405);
}

$user = require_page_permission('Bulk Client Import', 'edit');
$input = read_json_input();
$rowId = (int) ($input['row_id'] ?? 0);

if ($rowId <= 0) {
    send_json(['ok' => false, 'message' => 'Invalid row ID'], 422);
}

try {
    $pdo = db();
    ensure_mikrotik_bulk_table($pdo);

    $scope = mikrotik_scope_sql($user, 'r', 'scope_employee_id');
    $sql = 'DELETE FROM mikrotik_bulk_import_rows r WHERE r.id = :id' . $scope['sql'];
    $params = array_merge(['id' => $rowId], $scope['params']);

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    if ($stmt->rowCount() < 1) {
        send_json(['ok' => false, 'message' => 'Row not found or not allowed'], 404);
    }

    send_json(['ok' => true, 'message' => 'Row deleted']);
} catch (Throwable $e) {
    send_json(['ok' => false, 'message' => 'Failed to delete row', 'error' => $e->getMessage()], 500);
}
