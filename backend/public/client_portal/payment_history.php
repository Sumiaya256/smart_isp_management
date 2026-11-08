<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/database.php';
require_once dirname(__DIR__, 2) . '/config/auth.php';
require_once __DIR__ . '/payment_helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    send_json(['ok' => false, 'message' => 'Method not allowed'], 405);
}

$user = require_auth();
$roleName = strtolower(trim((string) ($user['role_name'] ?? '')));
if (!is_access_matrix_disabled() && $roleName !== 'client') {
    send_json(['ok' => false, 'message' => 'Forbidden: client access only'], 403);
}

try {
    $pdo = db();
    ensure_client_portal_payments_table($pdo);

    $stmt = $pdo->prepare(
        'SELECT p.id, p.payslip_no, p.payment_method, p.receiver_account, p.payer_reference,
            p.billing_month, p.amount, p.status, p.message, p.invoice_id, p.created_at,
            i.invoice_no, i.status AS invoice_status, i.due_date, i.paid_at
         FROM client_portal_payments p
         LEFT JOIN invoices i ON i.id = p.invoice_id
         WHERE p.client_id = :client_id
         ORDER BY p.id DESC
         LIMIT 500'
    );
    $stmt->execute(['client_id' => (int) $user['id']]);

    send_json([
        'ok' => true,
        'payments' => $stmt->fetchAll(),
    ]);
} catch (Throwable $e) {
    send_json(['ok' => false, 'message' => 'Failed to fetch payment history', 'error' => $e->getMessage()], 500);
}


