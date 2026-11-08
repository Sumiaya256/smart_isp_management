<?php

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json(['ok' => false, 'message' => 'Method not allowed'], 405);
}

$user = require_page_permission('Billing', 'edit');
$input = read_json_input();
$paymentId = (int) ($input['payment_id'] ?? 0);
$decision = strtolower(trim((string) ($input['decision'] ?? 'approve')));

if ($paymentId <= 0) {
    send_json(['ok' => false, 'message' => 'payment_id is required'], 422);
}
if (!in_array($decision, ['approve', 'reject'], true)) {
    send_json(['ok' => false, 'message' => 'decision must be approve or reject'], 422);
}

try {
    $pdo = db();
    ensure_billing_tables($pdo);

    $stmt = $pdo->prepare(
        'SELECT p.id, p.client_id, p.status
         FROM client_portal_payments p
         WHERE p.id = :id
         LIMIT 1'
    );
    $stmt->execute(['id' => $paymentId]);
    $payment = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$payment) {
        send_json(['ok' => false, 'message' => 'Payment submission not found'], 404);
    }

    billing_client_scope_guard($pdo, $user, (int) ($payment['client_id'] ?? 0));

    $newStatus = $decision === 'approve' ? 'approved' : 'rejected';
    $newMessage = $decision === 'approve'
        ? 'Payment approved. Invoice can be generated now.'
        : 'Payment rejected. Please contact support.';

    $update = $pdo->prepare(
        'UPDATE client_portal_payments
         SET status = :status,
             message = :message,
             approved_by_employee_id = :approved_by,
             approved_at = NOW()
         WHERE id = :id'
    );
    $update->execute([
        'status' => $newStatus,
        'message' => $newMessage,
        'approved_by' => (int) ($user['id'] ?? 0) ?: null,
        'id' => $paymentId,
    ]);

    send_json([
        'ok' => true,
        'message' => $decision === 'approve' ? 'Payment approved' : 'Payment rejected',
        'status' => $newStatus,
    ]);
} catch (Throwable $e) {
    send_json(['ok' => false, 'message' => 'Failed to update payment status', 'error' => $e->getMessage()], 500);
}
