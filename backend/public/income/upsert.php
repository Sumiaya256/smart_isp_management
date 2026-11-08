<?php

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json(['ok' => false, 'message' => 'Method not allowed'], 405);
}

$user = require_auth();
require_module_permission('Income', 'edit', true);

$input = read_json_input();
$id = (int) ($input['id'] ?? 0);
$invoiceNo = trim((string) ($input['invoice_no'] ?? ''));
$clientName = trim((string) ($input['client_name'] ?? ''));
$packageName = trim((string) ($input['package_name'] ?? ''));
$incomeType = trim((string) ($input['income_type'] ?? ''));
$amount = max(0, (float) ($input['amount'] ?? 0));
$paidAmount = max(0, (float) ($input['paid_amount'] ?? 0));
$dueDate = trim((string) ($input['due_date'] ?? ''));
$statusLabel = trim((string) ($input['status_label'] ?? 'pending'));
$paymentMethod = trim((string) ($input['payment_method'] ?? ''));
$notes = trim((string) ($input['notes'] ?? ''));

if ($clientName === '' || $incomeType === '' || $amount <= 0) {
    send_json(['ok' => false, 'message' => 'client_name, income_type and amount are required'], 422);
}

if ($invoiceNo === '') {
    $invoiceNo = 'INV-' . date('Ymd') . '-' . random_int(1000, 9999);
}

$allowedStatus = ['paid', 'pending', 'partial'];
if (!in_array($statusLabel, $allowedStatus, true)) {
    $statusLabel = 'pending';
}

try {
    $pdo = db();
    ensure_income_schema($pdo);

    $employeeId = (int) ($user['id'] ?? 0);

    if ($id > 0) {
        enforce_income_scope($pdo, $user, $id);

        $stmt = $pdo->prepare(
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
                 assigned_to_employee_id = :assigned_to_employee_id
             WHERE id = :id'
        );
        $stmt->execute([
            'invoice_no' => mb_substr($invoiceNo, 0, 60),
            'client_name' => mb_substr($clientName, 0, 160),
            'package_name' => $packageName !== '' ? mb_substr($packageName, 0, 120) : null,
            'income_type' => mb_substr($incomeType, 0, 80),
            'amount' => $amount,
            'paid_amount' => $paidAmount,
            'due_date' => $dueDate !== '' ? $dueDate : null,
            'status_label' => $statusLabel,
            'payment_method' => $paymentMethod !== '' ? mb_substr($paymentMethod, 0, 60) : null,
            'notes' => $notes !== '' ? $notes : null,
            'assigned_to_employee_id' => $employeeId > 0 ? $employeeId : null,
            'id' => $id,
        ]);
    } else {
        $stmt = $pdo->prepare(
            'INSERT INTO income_entries (
                invoice_no, client_name, package_name, income_type, amount, paid_amount,
                due_date, status_label, payment_method, notes, created_by_employee_id, assigned_to_employee_id
             ) VALUES (
                :invoice_no, :client_name, :package_name, :income_type, :amount, :paid_amount,
                :due_date, :status_label, :payment_method, :notes, :created_by_employee_id, :assigned_to_employee_id
             )'
        );
        $stmt->execute([
            'invoice_no' => mb_substr($invoiceNo, 0, 60),
            'client_name' => mb_substr($clientName, 0, 160),
            'package_name' => $packageName !== '' ? mb_substr($packageName, 0, 120) : null,
            'income_type' => mb_substr($incomeType, 0, 80),
            'amount' => $amount,
            'paid_amount' => $paidAmount,
            'due_date' => $dueDate !== '' ? $dueDate : null,
            'status_label' => $statusLabel,
            'payment_method' => $paymentMethod !== '' ? mb_substr($paymentMethod, 0, 60) : null,
            'notes' => $notes !== '' ? $notes : null,
            'created_by_employee_id' => $employeeId > 0 ? $employeeId : null,
            'assigned_to_employee_id' => $employeeId > 0 ? $employeeId : null,
        ]);
        $id = (int) $pdo->lastInsertId();
    }

    send_json(['ok' => true, 'id' => $id, 'message' => 'Income entry saved']);
} catch (Throwable $e) {
    send_json(['ok' => false, 'message' => 'Failed to save income entry', 'error' => $e->getMessage()], 500);
}
