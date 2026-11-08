<?php

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    send_json(['ok' => false, 'message' => 'Method not allowed'], 405);
}

$user = require_page_permission('Billing', 'view');
$roleName = strtolower(trim((string) ($user['role_name'] ?? '')));

$clientId = $roleName === 'client'
    ? (int) ($user['id'] ?? 0)
    : (int) ($_GET['client_id'] ?? 0);
$status = trim((string) ($_GET['status'] ?? ''));

try {
    $pdo = db();
    ensure_billing_tables($pdo);
    ensure_client_scope_columns($pdo);

    $sql =
        'SELECT i.id, i.invoice_no, i.client_id, c.client_code, c.full_name, i.billing_month, i.due_date, i.amount, i.status, i.generated_at, i.paid_at
         FROM invoices i
         INNER JOIN clients c ON c.id = i.client_id
         WHERE 1 = 1';

    $params = [];

    if ($clientId > 0) {
        $sql .= ' AND i.client_id = :client_id';
        $params['client_id'] = $clientId;
    }

    if ($status !== '') {
        $sql .= ' AND i.status = :status';
        $params['status'] = $status;
    }

    if ($roleName !== 'client' && is_limited_module_permission($user, 'Billing')) {
        $scope = apply_client_scope_where($user, 'c', 'scope_employee_id');
        $sql .= $scope['sql'];
        $params = array_merge($params, $scope['params']);
    }

    $sql .= ' ORDER BY i.id DESC LIMIT 500';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    send_json([
        'ok' => true,
        'invoices' => $stmt->fetchAll(),
        'can_create_invoice' => user_has_page_action_access($user, 'Billing', 'edit'),
        'can_collect_payment' => user_has_page_action_access($user, 'Billing', 'edit'),
    ]);
} catch (Throwable $e) {
    send_json(['ok' => false, 'message' => 'Failed to fetch invoices', 'error' => $e->getMessage()], 500);
}
