<?php

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json(['ok' => false, 'message' => 'Method not allowed'], 405);
}

$user = require_page_permission('Billing', 'edit');
$input = read_json_input();
$paymentId = (int) ($input['payment_id'] ?? 0);
$dueDate = trim((string) ($input['due_date'] ?? ''));

if ($paymentId <= 0) {
    send_json(['ok' => false, 'message' => 'payment_id is required'], 422);
}

try {
    $pdo = db();
    ensure_billing_tables($pdo);

    $pdo->beginTransaction();

    $stmt = $pdo->prepare(
        'SELECT p.*, c.client_code, c.full_name
         FROM client_portal_payments p
         INNER JOIN clients c ON c.id = p.client_id
         WHERE p.id = :id
         LIMIT 1
         FOR UPDATE'
    );
    $stmt->execute(['id' => $paymentId]);
    $payment = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$payment) {
        $pdo->rollBack();
        send_json(['ok' => false, 'message' => 'Payment submission not found'], 404);
    }

    billing_client_scope_guard($pdo, $user, (int) ($payment['client_id'] ?? 0));

    if (!empty($payment['invoice_id'])) {
        $invStmt = $pdo->prepare('SELECT id, invoice_no FROM invoices WHERE id = :id LIMIT 1');
        $invStmt->execute(['id' => (int) $payment['invoice_id']]);
        $inv = $invStmt->fetch(PDO::FETCH_ASSOC);
        $pdo->commit();
        send_json([
            'ok' => true,
            'message' => 'Invoice already generated for this payment',
            'invoice' => $inv,
        ]);
    }

    $invoiceNo = 'INV-' . date('YmdHis') . '-' . (int) $payment['client_id'];
    $billingMonth = (string) ($payment['billing_month'] ?? date('Y-m'));
    $billingMonthDate = $billingMonth . '-01';

    if ($dueDate === '') {
        $dueDate = date('Y-m-d', strtotime('+7 days'));
    }

    $ins = $pdo->prepare(
        'INSERT INTO invoices (invoice_no, client_id, billing_month, due_date, amount, status)
         VALUES (:invoice_no, :client_id, :billing_month, :due_date, :amount, :status)'
    );
    $ins->execute([
        'invoice_no' => $invoiceNo,
        'client_id' => (int) $payment['client_id'],
        'billing_month' => $billingMonthDate,
        'due_date' => $dueDate,
        'amount' => (float) ($payment['amount'] ?? 0),
        'status' => 'unpaid',
    ]);

    $invoiceId = (int) $pdo->lastInsertId();

    $up = $pdo->prepare(
        'UPDATE client_portal_payments
         SET invoice_id = :invoice_id,
             invoice_generated_at = NOW(),
             message = :message
         WHERE id = :id'
    );
    $up->execute([
        'invoice_id' => $invoiceId,
        'message' => 'Invoice generated. Waiting for payment collection.',
        'id' => $paymentId,
    ]);

    $pdo->commit();

    send_json([
        'ok' => true,
        'message' => 'Invoice generated successfully',
        'invoice' => [
            'id' => $invoiceId,
            'invoice_no' => $invoiceNo,
            'client_id' => (int) $payment['client_id'],
            'client_code' => (string) ($payment['client_code'] ?? ''),
            'client_name' => (string) ($payment['full_name'] ?? ''),
            'amount' => (float) ($payment['amount'] ?? 0),
        ],
    ]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    send_json(['ok' => false, 'message' => 'Failed to generate invoice', 'error' => $e->getMessage()], 500);
}
