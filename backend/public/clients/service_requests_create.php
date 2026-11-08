<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/database.php';
require_once dirname(__DIR__, 2) . '/config/auth.php';
require_once __DIR__ . '/service_request_helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json(['ok' => false, 'message' => 'Method not allowed'], 405);
}

$user = require_page_permission('Change Request', 'edit');
$input = read_json_input();

$clientId = (int) ($input['client_id'] ?? 0);
$requestKind = normalize_service_request_kind((string) ($input['request_kind'] ?? 'change'));
$requestType = normalize_service_request_type((string) ($input['request_type'] ?? 'other'), $requestKind);
$currentValue = trim((string) ($input['current_value'] ?? ''));
$newValue = trim((string) ($input['new_value'] ?? ''));
$effectiveDate = trim((string) ($input['effective_date'] ?? ''));
$priority = normalize_service_request_priority((string) ($input['priority'] ?? 'normal'));
$terminationReason = normalize_close_reason((string) ($input['termination_reason'] ?? 'other'));
$reason = trim((string) ($input['reason'] ?? ''));
$notes = trim((string) ($input['notes'] ?? ''));

if ($clientId <= 0 || $reason === '') {
    send_json(['ok' => false, 'message' => 'Client and reason are required'], 422);
}

if ($requestKind === 'change' && $effectiveDate === '') {
    send_json(['ok' => false, 'message' => 'Effective date is required for change request'], 422);
}

if ($effectiveDate !== '') {
    $dt = DateTime::createFromFormat('Y-m-d', $effectiveDate);
    if (!$dt || $dt->format('Y-m-d') !== $effectiveDate) {
        send_json(['ok' => false, 'message' => 'Invalid effective date'], 422);
    }
}

try {
    $pdo = db();
    ensure_client_scope_columns($pdo);
    ensure_client_service_requests_table($pdo);

    enforce_client_scope_for_client_id($pdo, $user, $clientId);

    $clientStmt = $pdo->prepare('SELECT id FROM clients WHERE id = :id LIMIT 1');
    $clientStmt->execute(['id' => $clientId]);
    if (!$clientStmt->fetch()) {
        send_json(['ok' => false, 'message' => 'Client not found'], 404);
    }

    $code = generate_service_request_code($pdo);

    $stmt = $pdo->prepare(
        'INSERT INTO client_service_requests
        (request_code, client_id, request_kind, request_type, current_value, new_value, effective_date, priority, status,
         termination_reason, reason, notes, requested_by_client, created_by_employee_id, updated_by_employee_id)
        VALUES
        (:request_code, :client_id, :request_kind, :request_type, :current_value, :new_value, :effective_date, :priority, :status,
         :termination_reason, :reason, :notes, 0, :created_by_employee_id, :updated_by_employee_id)'
    );

    $employeeId = (int) ($user['id'] ?? 0);

    $stmt->execute([
        'request_code' => $code,
        'client_id' => $clientId,
        'request_kind' => $requestKind,
        'request_type' => $requestType,
        'current_value' => $currentValue !== '' ? mb_substr($currentValue, 0, 255) : null,
        'new_value' => $newValue !== '' ? mb_substr($newValue, 0, 255) : null,
        'effective_date' => $effectiveDate !== '' ? $effectiveDate : null,
        'priority' => $priority,
        'status' => 'pending',
        'termination_reason' => $requestKind === 'close_connection' ? $terminationReason : null,
        'reason' => mb_substr($reason, 0, 5000),
        'notes' => $notes !== '' ? mb_substr($notes, 0, 5000) : null,
        'created_by_employee_id' => $employeeId > 0 ? $employeeId : null,
        'updated_by_employee_id' => $employeeId > 0 ? $employeeId : null,
    ]);

    send_json([
        'ok' => true,
        'message' => 'Service request created successfully',
        'id' => (int) $pdo->lastInsertId(),
        'request_code' => $code,
    ], 201);
} catch (Throwable $e) {
    send_json(['ok' => false, 'message' => 'Failed to create service request', 'error' => $e->getMessage()], 500);
}
