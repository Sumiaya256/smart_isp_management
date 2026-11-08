<?php

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    send_json(['ok' => false, 'message' => 'Method not allowed'], 405);
}

$user = require_page_permission('Billing', 'view');
$status = trim((string) ($_GET['status'] ?? ''));

try {
    $pdo = db();
    ensure_billing_tables($pdo);

    $sql =
        'SELECT p.id, p.client_id, c.client_code, c.full_name, p.payslip_no, p.payment_method, p.receiver_account,
                p.payer_reference, p.billing_month, p.amount, p.status, p.message, p.invoice_id, p.created_at,
                i.invoice_no, i.status AS invoice_status
         FROM client_portal_payments p
         INNER JOIN clients c ON c.id = p.client_id
         LEFT JOIN invoices i ON i.id = p.invoice_id
         WHERE 1 = 1';

    $params = [];
    if ($status !== '') {
        $sql .= ' AND p.status = :status';
        $params['status'] = $status;
    }

    if (is_limited_module_permission($user, 'Billing')) {
        $scope = apply_client_scope_where($user, 'c', 'scope_employee_id');
        $sql .= $scope['sql'];
        $params = array_merge($params, $scope['params']);
    }

    $sql .= ' ORDER BY p.id DESC LIMIT 500';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    send_json([
        'ok' => true,
        'items' => $stmt->fetchAll(),
        'can_approve' => user_has_page_action_access($user, 'Billing', 'edit'),
        'can_create_invoice' => user_has_page_action_access($user, 'Billing', 'edit'),
    ]);
} catch (Throwable $e) {
    send_json(['ok' => false, 'message' => 'Failed to load payment submissions', 'error' => $e->getMessage()], 500);
}
