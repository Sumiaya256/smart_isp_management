<?php

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    send_json(['ok' => false, 'message' => 'Method not allowed'], 405);
}

$user = require_page_permission('Billing', 'edit');

try {
    $pdo = db();
    ensure_billing_tables($pdo);
    ensure_client_scope_columns($pdo);

    $sql =
        'SELECT c.id, c.client_code, c.full_name
         FROM clients c
         WHERE 1 = 1';

    $params = [];
    if (is_limited_module_permission($user, 'Billing')) {
        $scope = apply_client_scope_where($user, 'c', 'scope_employee_id');
        $sql .= $scope['sql'];
        $params = array_merge($params, $scope['params']);
    }

    $sql .= ' ORDER BY c.id DESC LIMIT 500';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    send_json([
        'ok' => true,
        'clients' => $stmt->fetchAll(),
    ]);
} catch (Throwable $e) {
    send_json(['ok' => false, 'message' => 'Failed to fetch clients', 'error' => $e->getMessage()], 500);
}
