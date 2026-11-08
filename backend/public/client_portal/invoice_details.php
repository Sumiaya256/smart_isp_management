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

$invoiceId = (int) ($_GET['invoice_id'] ?? 0);
if ($invoiceId <= 0) {
    send_json(['ok' => false, 'message' => 'invoice_id is required'], 422);
}

try {
    $pdo = db();
    ensure_client_portal_payments_table($pdo);

    $stmt = $pdo->prepare(
        'SELECT i.id, i.invoice_no, i.billing_month, i.due_date, i.amount, i.status, i.generated_at, i.paid_at,
                c.client_code, c.full_name, c.phone, c.email,
                ip.package_name, ip.speed_mbps
         FROM invoices i
         INNER JOIN clients c ON c.id = i.client_id
         LEFT JOIN internet_packages ip ON ip.id = c.package_id
         WHERE i.id = :invoice_id
           AND i.client_id = :client_id
         LIMIT 1'
    );
    $stmt->execute([
        'invoice_id' => $invoiceId,
        'client_id' => (int) ($user['id'] ?? 0),
    ]);

    $invoice = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$invoice) {
        send_json(['ok' => false, 'message' => 'Invoice not found'], 404);
    }

    send_json([
        'ok' => true,
        'invoice' => $invoice,
    ]);
} catch (Throwable $e) {
    send_json(['ok' => false, 'message' => 'Failed to load invoice details', 'error' => $e->getMessage()], 500);
}
