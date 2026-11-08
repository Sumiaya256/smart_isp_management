<?php

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json(['ok' => false, 'message' => 'Method not allowed'], 405);
}

$user = require_page_permission('Billing', 'edit');

$input = read_json_input();
$clientId = (int) ($input['client_id'] ?? 0);
$billingMonth = trim((string) ($input['billing_month'] ?? ''));
$dueDate = trim((string) ($input['due_date'] ?? ''));

if ($clientId <= 0 || $billingMonth === '' || $dueDate === '') {
    send_json(['ok' => false, 'message' => 'client_id, billing_month and due_date are required'], 422);
}

try {
    $pdo = db();
    ensure_billing_tables($pdo);
    ensure_client_scope_columns($pdo);
    if (is_limited_module_permission($user, 'Billing')) {
        enforce_client_scope_for_client_id($pdo, $user, $clientId);
    }

    $clientStmt = $pdo->prepare(
        'SELECT c.id, c.client_code, c.full_name, p.monthly_price
         FROM clients c
         LEFT JOIN internet_packages p ON p.id = c.package_id
         WHERE c.id = :client_id
         LIMIT 1'
    );
    $clientStmt->execute(['client_id' => $clientId]);
    $client = $clientStmt->fetch();

    if (!$client) {
        send_json(['ok' => false, 'message' => 'Client not found'], 404);
    }

    $amount = (float) ($client['monthly_price'] ?? 0);
    if ($amount <= 0) {
        send_json(['ok' => false, 'message' => 'Client package has no monthly price'], 422);
    }

    $existsStmt = $pdo->prepare('SELECT id FROM invoices WHERE client_id = :client_id AND billing_month = :billing_month LIMIT 1');
    $existsStmt->execute([
        'client_id' => $clientId,
        'billing_month' => $billingMonth,
    ]);

    if ($existsStmt->fetch()) {
        send_json(['ok' => false, 'message' => 'Invoice already exists for this client and billing month'], 409);
    }

    $invoiceNo = 'INV-' . date('YmdHis') . '-' . $clientId;

    $insertStmt = $pdo->prepare(
        'INSERT INTO invoices (invoice_no, client_id, billing_month, due_date, amount, status)
         VALUES (:invoice_no, :client_id, :billing_month, :due_date, :amount, :status)'
    );

    $insertStmt->execute([
        'invoice_no' => $invoiceNo,
        'client_id' => $clientId,
        'billing_month' => $billingMonth,
        'due_date' => $dueDate,
        'amount' => $amount,
        'status' => 'unpaid',
    ]);

    send_json([
        'ok' => true,
        'message' => 'Invoice created successfully',
        'invoice' => [
            'id' => (int) $pdo->lastInsertId(),
            'invoice_no' => $invoiceNo,
            'client_id' => $clientId,
            'amount' => $amount,
            'status' => 'unpaid',
        ],
    ], 201);
} catch (Throwable $e) {
    send_json(['ok' => false, 'message' => 'Failed to create invoice', 'error' => $e->getMessage()], 500);
}
