<?php

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';
require_once dirname(__DIR__) . '/income/helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json(['ok' => false, 'message' => 'Method not allowed'], 405);
}

$collector = require_page_permission('Billing', 'edit');

$input = read_json_input();
$invoiceId = (int) ($input['invoice_id'] ?? 0);
$amount = (float) ($input['amount'] ?? 0);
$method = trim((string) ($input['method'] ?? 'cash'));
$transactionRef = trim((string) ($input['transaction_ref'] ?? ''));

$allowedMethods = ['cash', 'bank', 'mobile_banking', 'card'];
if ($invoiceId <= 0 || $amount <= 0) {
    send_json(['ok' => false, 'message' => 'invoice_id and amount are required'], 422);
}
if (!in_array($method, $allowedMethods, true)) {
    send_json(['ok' => false, 'message' => 'Invalid payment method'], 422);
}

try {
    $pdo = db();
    ensure_billing_tables($pdo);
    ensure_income_schema($pdo);
    ensure_client_scope_columns($pdo);

    $collectorUserId = null;
    $collectorEmail = trim((string) ($collector['email'] ?? ''));
    if ($collectorEmail !== '') {
        $collectorUserStmt = $pdo->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
        $collectorUserStmt->execute(['email' => $collectorEmail]);
        $resolvedCollectorUserId = $collectorUserStmt->fetchColumn();
        if ($resolvedCollectorUserId !== false) {
            $collectorUserId = (int) $resolvedCollectorUserId;
        }
    }

    $pdo->beginTransaction();

    $invoiceStmt = $pdo->prepare(
        'SELECT i.id, i.invoice_no, i.client_id, i.amount, i.due_date,
                c.full_name AS client_name,
                p.package_name
         FROM invoices i
         INNER JOIN clients c ON c.id = i.client_id
         LEFT JOIN internet_packages p ON p.id = c.package_id
         WHERE i.id = :id
         LIMIT 1
         FOR UPDATE'
    );
    $invoiceStmt->execute(['id' => $invoiceId]);
    $invoice = $invoiceStmt->fetch();

    if (!$invoice) {
        $pdo->rollBack();
        send_json(['ok' => false, 'message' => 'Invoice not found'], 404);
    }

    if (is_limited_module_permission($collector, 'Billing')) {
        enforce_client_scope_for_client_id($pdo, $collector, (int) ($invoice['client_id'] ?? 0));
    }

    $insertPayment = $pdo->prepare(
           'INSERT INTO payments (invoice_id, client_id, amount, method, transaction_ref, collected_by)
            VALUES (:invoice_id, :client_id, :amount, :method, :transaction_ref, :collected_by)'
    );
    $insertPayment->execute([
        'invoice_id' => $invoiceId,
        'client_id' => $invoice['client_id'],
        'amount' => $amount,
        'method' => $method,
        'transaction_ref' => $transactionRef !== '' ? $transactionRef : null,
        'collected_by' => $collectorUserId,
    ]);
    $paymentRecordId = (int) $pdo->lastInsertId();

    $sumStmt = $pdo->prepare('SELECT COALESCE(SUM(amount), 0) AS paid_total FROM payments WHERE invoice_id = :invoice_id');
    $sumStmt->execute(['invoice_id' => $invoiceId]);
    $paidTotal = (float) ($sumStmt->fetch()['paid_total'] ?? 0);

    $invoiceAmount = (float) $invoice['amount'];
    $status = 'unpaid';
    $paidAt = null;

    if ($paidTotal >= $invoiceAmount) {
        $status = 'paid';
        $paidAt = date('Y-m-d H:i:s');
    } elseif ($paidTotal > 0) {
        $status = 'partial';
    } elseif (date('Y-m-d') > (string) $invoice['due_date']) {
        $status = 'overdue';
    }

    $updateStmt = $pdo->prepare('UPDATE invoices SET status = :status, paid_at = :paid_at WHERE id = :id');
    $updateStmt->bindValue(':status', $status);
    if ($paidAt === null) {
        $updateStmt->bindValue(':paid_at', null, PDO::PARAM_NULL);
    } else {
        $updateStmt->bindValue(':paid_at', $paidAt);
    }
    $updateStmt->bindValue(':id', $invoiceId, PDO::PARAM_INT);
    $updateStmt->execute();

    $portalUpdate = $pdo->prepare(
        'UPDATE client_portal_payments
         SET status = :status,
             message = :message
         WHERE invoice_id = :invoice_id'
    );
    $portalStatus = $status === 'paid' ? 'paid_confirmed' : 'invoice_generated';
    $portalMessage = $status === 'paid'
        ? 'Payment confirmed and collected. Invoice settled.'
        : 'Invoice generated. Waiting for full collection.';
    $portalUpdate->execute([
        'status' => $portalStatus,
        'message' => $portalMessage,
        'invoice_id' => $invoiceId,
    ]);

    $incomeStatus = $status === 'paid' ? 'paid' : ($status === 'partial' ? 'partial' : 'pending');

    $incomeCheck = $pdo->prepare('SELECT id FROM income_entries WHERE source_invoice_id = :source_invoice_id LIMIT 1');
    $incomeCheck->execute(['source_invoice_id' => $invoiceId]);
    $incomeId = (int) $incomeCheck->fetchColumn();

    $incomePayload = [
        'invoice_no' => mb_substr((string) ($invoice['invoice_no'] ?? ('INV-' . $invoiceId)), 0, 60),
        'client_name' => mb_substr((string) ($invoice['client_name'] ?? 'Client'), 0, 160),
        'package_name' => mb_substr((string) ($invoice['package_name'] ?? ''), 0, 120),
        'income_type' => 'Client Billing Collection',
        'amount' => (float) $invoiceAmount,
        'paid_amount' => (float) min($paidTotal, $invoiceAmount),
        'due_date' => (string) ($invoice['due_date'] ?? null),
        'status_label' => $incomeStatus,
        'payment_method' => mb_substr($method, 0, 60),
        'notes' => 'Auto-created from billing payment collection',
        'source_invoice_id' => $invoiceId,
        'source_payment_id' => $paymentRecordId,
        'employee_id' => (int) ($collector['id'] ?? 0) ?: null,
    ];

    if ($incomeId > 0) {
        $incomeUp = $pdo->prepare(
            'UPDATE income_entries
             SET invoice_no = :invoice_no,
                 client_name = :client_name,
                 package_name = :package_name,
                 income_type = :income_type,
                 amount = :amount,
                 paid_amount = :paid_amount,
                 due_date = :due_date,
                 status_label = :status_label,
                 payment_method = :payment_method,
                 notes = :notes,
                 source_payment_id = :source_payment_id,
                 assigned_to_employee_id = :employee_id
             WHERE id = :id'
        );
        $incomeUp->execute([
            'invoice_no' => $incomePayload['invoice_no'],
            'client_name' => $incomePayload['client_name'],
            'package_name' => $incomePayload['package_name'] !== '' ? $incomePayload['package_name'] : null,
            'income_type' => $incomePayload['income_type'],
            'amount' => $incomePayload['amount'],
            'paid_amount' => $incomePayload['paid_amount'],
            'due_date' => $incomePayload['due_date'] !== '' ? $incomePayload['due_date'] : null,
            'status_label' => $incomePayload['status_label'],
            'payment_method' => $incomePayload['payment_method'],
            'notes' => $incomePayload['notes'],
            'source_payment_id' => $incomePayload['source_payment_id'],
            'employee_id' => $incomePayload['employee_id'],
            'id' => $incomeId,
        ]);
    } else {
        $incomeIns = $pdo->prepare(
            'INSERT INTO income_entries
             (invoice_no, client_name, package_name, income_type, amount, paid_amount, due_date,
              status_label, payment_method, notes, source_invoice_id, source_payment_id,
              created_by_employee_id, assigned_to_employee_id)
             VALUES
             (:invoice_no, :client_name, :package_name, :income_type, :amount, :paid_amount, :due_date,
              :status_label, :payment_method, :notes, :source_invoice_id, :source_payment_id,
              :created_by_employee_id, :assigned_to_employee_id)'
        );
        $incomeIns->execute([
            'invoice_no' => $incomePayload['invoice_no'],
            'client_name' => $incomePayload['client_name'],
            'package_name' => $incomePayload['package_name'] !== '' ? $incomePayload['package_name'] : null,
            'income_type' => $incomePayload['income_type'],
            'amount' => $incomePayload['amount'],
            'paid_amount' => $incomePayload['paid_amount'],
            'due_date' => $incomePayload['due_date'] !== '' ? $incomePayload['due_date'] : null,
            'status_label' => $incomePayload['status_label'],
            'payment_method' => $incomePayload['payment_method'],
            'notes' => $incomePayload['notes'],
            'source_invoice_id' => $incomePayload['source_invoice_id'],
            'source_payment_id' => $incomePayload['source_payment_id'],
            'created_by_employee_id' => $incomePayload['employee_id'],
            'assigned_to_employee_id' => $incomePayload['employee_id'],
        ]);
    }

    $pdo->commit();

    send_json([
        'ok' => true,
        'message' => 'Payment collected successfully',
        'invoice_id' => $invoiceId,
        'invoice_status' => $status,
        'paid_total' => $paidTotal,
        'invoice_amount' => $invoiceAmount,
    ]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    $errorMessage = trim((string) $e->getMessage());
    send_json([
        'ok' => false,
        'message' => $errorMessage !== '' ? $errorMessage : 'Failed to collect payment',
        'error' => $errorMessage,
    ], 500);
}
