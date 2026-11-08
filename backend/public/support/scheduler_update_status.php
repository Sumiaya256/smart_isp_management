<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/database.php';
require_once dirname(__DIR__, 2) . '/config/auth.php';
require_once __DIR__ . '/scheduler_helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json(['ok' => false, 'message' => 'Method not allowed'], 405);
}

$user = require_page_permission('Scheduler', 'edit');
$input = read_json_input();

$appointmentId = (int) ($input['appointment_id'] ?? 0);
$status = normalize_appointment_status((string) ($input['status'] ?? 'scheduled'));

if ($appointmentId <= 0) {
    send_json(['ok' => false, 'message' => 'Invalid appointment ID'], 422);
}

try {
    $pdo = db();
    ensure_support_appointments_table($pdo);

    $scopeSql = '';
    $params = [
        'id' => $appointmentId,
        'status' => $status,
    ];

    if (is_limited_module_permission($user, 'Support & Ticketing')) {
        $scopeEmployeeId = (int) ($user['id'] ?? 0);
        if ($scopeEmployeeId <= 0) {
            send_json(['ok' => false, 'message' => 'Forbidden: invalid employee scope'], 403);
        }
        $scopeSql = ' AND (created_by_employee_id = :scope_employee_id OR assigned_to_employee_id = :scope_employee_id)';
        $params['scope_employee_id'] = $scopeEmployeeId;
    }

    $stmt = $pdo->prepare(
        'UPDATE support_appointments
         SET status = :status
         WHERE id = :id' . $scopeSql
    );
    $stmt->execute($params);

    if ($stmt->rowCount() === 0) {
        send_json(['ok' => false, 'message' => 'Appointment not found or not allowed'], 404);
    }

    send_json(['ok' => true, 'message' => 'Appointment status updated']);
} catch (Throwable $e) {
    send_json(['ok' => false, 'message' => 'Failed to update appointment status', 'error' => $e->getMessage()], 500);
}
