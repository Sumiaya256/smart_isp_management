<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/database.php';
require_once dirname(__DIR__, 2) . '/config/auth.php';
require_once __DIR__ . '/payment_helpers.php';
require_once dirname(__DIR__) . '/income/helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json(['ok' => false, 'message' => 'Method not allowed'], 405);
}

$user = require_auth();
$roleName = strtolower(trim((string) ($user['role_name'] ?? '')));
if (!is_access_matrix_disabled() && $roleName !== 'client') {
    send_json(['ok' => false, 'message' => 'Forbidden: client access only'], 403);
}

$input = read_json_input();
$method = strtolower(trim((string) ($input['method'] ?? '')));
$payerReference = trim((string) ($input['payer_reference'] ?? ''));

$allowedMethods = ['bkash', 'nagad', 'card'];
if (!in_array($method, $allowedMethods, true)) {
    send_json(['ok' => false, 'message' => 'Invalid payment method'], 422);
}

$receivers = [
    'bkash' => '01756202157',
    'nagad' => '01756202157',
    'card' => '123123321312',
];

try {
    $pdo = db();
    ensure_client_portal_payments_table($pdo);
    ensure_income_schema($pdo);

    $clientStmt = $pdo->prepare(
        'SELECT c.id,
                c.full_name,
                COALESCE(p.package_name, "") AS package_name,
                COALESCE(p.monthly_price, 0) AS monthly_price
         FROM clients c
         LEFT JOIN internet_packages p ON p.id = c.package_id
         WHERE c.id = :id
         LIMIT 1'
    );
    $clientStmt->execute(['id' => (int) $user['id']]);
    $client = $clientStmt->fetch();

    if (!$client) {
        send_json(['ok' => false, 'message' => 'Client not found'], 404);
    }

    $amount = (float) ($client['monthly_price'] ?? 0);
    if ($amount <= 0) {
        send_json(['ok' => false, 'message' => 'Invalid fixed amount for this client'], 422);
    }

    $billingMonth = date('Y-m');
    $payslipNo = generate_payslip_no($pdo);

    $insert = $pdo->prepare(
        'INSERT INTO client_portal_payments
         (client_id, payslip_no, payment_method, receiver_account, payer_reference, billing_month, amount, status, message)
         VALUES
         (:client_id, :payslip_no, :payment_method, :receiver_account, :payer_reference, :billing_month, :amount, :status, :message)'
    );

    $insert->execute([
        'client_id' => (int) $user['id'],
        'payslip_no' => $payslipNo,
        'payment_method' => $method,
        'receiver_account' => $receivers[$method],
        'payer_reference' => $payerReference !== '' ? $payerReference : null,
        'billing_month' => $billingMonth,
        'amount' => $amount,
        'status' => 'pending_confirmation',
        'message' => 'Payment done. Wait for confirmation.',
    ]);
    $submittedPaymentId = (int) $pdo->lastInsertId();

    $incomeInsert = $pdo->prepare(
        'INSERT INTO income_entries
         (invoice_no, client_name, package_name, income_type, amount, paid_amount, due_date,
          status_label, payment_method, notes, source_payment_id)
         VALUES
         (:invoice_no, :client_name, :package_name, :income_type, :amount, :paid_amount, :due_date,
          :status_label, :payment_method, :notes, :source_payment_id)'
    );
    $incomeInsert->execute([
        'invoice_no' => mb_substr($payslipNo, 0, 60),
        'client_name' => mb_substr((string) ($client['full_name'] ?? 'Client'), 0, 160),
        'package_name' => mb_substr((string) ($client['package_name'] ?? ''), 0, 120) ?: null,
        'income_type' => 'Client Payment Submission',
        'amount' => $amount,
        'paid_amount' => 0,
        'due_date' => date('Y-m-t'),
        'status_label' => 'pending',
        'payment_method' => mb_substr($method, 0, 60),
        'notes' => 'Auto-created from client portal payment submit (awaiting confirmation)',
        'source_payment_id' => $submittedPaymentId,
    ]);

    send_json([
        'ok' => true,
        'message' => 'Payment done. Wait for confirmation.',
        'payment' => [
            'payslip_no' => $payslipNo,
            'payment_method' => $method,
            'receiver_account' => $receivers[$method],
            'billing_month' => $billingMonth,
            'amount' => $amount,
            'status' => 'pending_confirmation',
        ],
    ], 201);
} catch (Throwable $e) {
    send_json(['ok' => false, 'message' => 'Failed to submit payment', 'error' => $e->getMessage()], 500);
}

function generate_payslip_no(PDO $pdo): string
{
    for ($i = 0; $i < 20; $i++) {
        $candidate = 'PSL-' . date('Ym') . '-' . strtoupper(bin2hex(random_bytes(2)));
        $check = $pdo->prepare('SELECT COUNT(*) FROM client_portal_payments WHERE payslip_no = :payslip_no');
        $check->execute(['payslip_no' => $candidate]);
        if ((int) $check->fetchColumn() === 0) {
            return $candidate;
        }
    }

    return 'PSL-' . date('Ym') . '-' . time();
}


