<?php

declare(strict_types=1);

require __DIR__ . '/../backend/config/database.php';
require __DIR__ . '/../backend/public/billing/helpers.php';
require __DIR__ . '/../backend/public/income/helpers.php';

$invoiceId = isset($argv[1]) ? (int) $argv[1] : 1;
$amount = isset($argv[2]) ? (float) $argv[2] : 1400;
$method = isset($argv[3]) ? (string) $argv[3] : 'cash';
$collectorId = 1;

$pdo = db();
ensure_billing_tables($pdo);
ensure_income_schema($pdo);

try {
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
    $invoice = $invoiceStmt->fetch(PDO::FETCH_ASSOC);
    if (!$invoice) {
        throw new RuntimeException('Invoice not found');
    }

    echo "step=insert_payment\n";
    $insertPayment = $pdo->prepare(
        'INSERT INTO payments (invoice_id, client_id, amount, method, transaction_ref, collected_by)
         VALUES (:invoice_id, :client_id, :amount, :method, :transaction_ref, :collected_by)'
    );
    $insertPayment->execute([
        'invoice_id' => $invoiceId,
        'client_id' => $invoice['client_id'],
        'amount' => $amount,
        'method' => $method,
        'transaction_ref' => 'DEBUG-TRX',
        'collected_by' => $collectorId,
    ]);
    $paymentId = (int) $pdo->lastInsertId();
    echo 'payment_id=' . $paymentId . PHP_EOL;

    echo "step=sum\n";
    $sumStmt = $pdo->prepare('SELECT COALESCE(SUM(amount), 0) AS paid_total FROM payments WHERE invoice_id = :invoice_id');
    $sumStmt->execute(['invoice_id' => $invoiceId]);
    $paidTotal = (float) ($sumStmt->fetch(PDO::FETCH_ASSOC)['paid_total'] ?? 0);

    $invoiceAmount = (float) $invoice['amount'];
    $status = $paidTotal >= $invoiceAmount ? 'paid' : ($paidTotal > 0 ? 'partial' : 'unpaid');
    $paidAt = $status === 'paid' ? date('Y-m-d H:i:s') : null;

    echo "step=update_invoice\n";
    $updateStmt = $pdo->prepare('UPDATE invoices SET status = :status, paid_at = :paid_at WHERE id = :id');
    $updateStmt->bindValue(':status', $status);
    if ($paidAt === null) {
        $updateStmt->bindValue(':paid_at', null, PDO::PARAM_NULL);
    } else {
        $updateStmt->bindValue(':paid_at', $paidAt);
    }
    $updateStmt->bindValue(':id', $invoiceId, PDO::PARAM_INT);
    $updateStmt->execute();

    echo "step=update_portal\n";
    $portalUpdate = $pdo->prepare(
        'UPDATE client_portal_payments
         SET status = :status,
             message = :message
         WHERE invoice_id = :invoice_id'
    );
    $portalUpdate->execute([
        'status' => $status === 'paid' ? 'paid_confirmed' : 'invoice_generated',
        'message' => 'Debug sync',
        'invoice_id' => $invoiceId,
    ]);

    if ($status === 'paid') {
        echo "step=income_upsert\n";
        $incomeCheck = $pdo->prepare('SELECT id FROM income_entries WHERE source_invoice_id = :source_invoice_id LIMIT 1');
        $incomeCheck->execute(['source_invoice_id' => $invoiceId]);
        $incomeId = (int) $incomeCheck->fetchColumn();

        if ($incomeId > 0) {
            $incomeUp = $pdo->prepare('UPDATE income_entries SET source_payment_id = :source_payment_id WHERE id = :id');
            $incomeUp->execute(['source_payment_id' => $paymentId, 'id' => $incomeId]);
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
                'invoice_no' => (string) ($invoice['invoice_no'] ?? ('INV-' . $invoiceId)),
                'client_name' => (string) ($invoice['client_name'] ?? 'Client'),
                'package_name' => (string) ($invoice['package_name'] ?? ''),
                'income_type' => 'Client Billing Collection',
                'amount' => $invoiceAmount,
                'paid_amount' => min($paidTotal, $invoiceAmount),
                'due_date' => (string) ($invoice['due_date'] ?? ''),
                'status_label' => 'paid',
                'payment_method' => $method,
                'notes' => 'Debug insert',
                'source_invoice_id' => $invoiceId,
                'source_payment_id' => $paymentId,
                'created_by_employee_id' => $collectorId,
                'assigned_to_employee_id' => $collectorId,
            ]);
        }
    }

    $pdo->rollBack();
    echo "result=ok(rolled_back)\n";
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo 'error=' . $e->getMessage() . PHP_EOL;
    exit(1);
}
