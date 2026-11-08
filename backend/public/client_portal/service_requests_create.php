<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/database.php';
require_once dirname(__DIR__, 2) . '/config/auth.php';
require_once dirname(__DIR__) . '/clients/service_request_helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json(['ok' => false, 'message' => 'Method not allowed'], 405);
}

$user = require_auth();
$roleName = strtolower(trim((string) ($user['role_name'] ?? '')));
if (!is_access_matrix_disabled() && $roleName !== 'client') {
    send_json(['ok' => false, 'message' => 'Forbidden'], 403);
}

$clientId = (int) ($user['id'] ?? 0);
if ($clientId <= 0) {
    send_json(['ok' => false, 'message' => 'Invalid client account'], 400);
}

$input = read_json_input();

$requestKind = normalize_service_request_kind((string) ($input['request_kind'] ?? 'change'));
$requestType = normalize_service_request_type((string) ($input['request_type'] ?? 'other'), $requestKind);
$currentValue = trim((string) ($input['current_value'] ?? ''));
$newValue = trim((string) ($input['new_value'] ?? ''));
$effectiveDate = trim((string) ($input['effective_date'] ?? ''));
$priority = normalize_service_request_priority((string) ($input['priority'] ?? 'normal'));
$terminationReason = normalize_close_reason((string) ($input['termination_reason'] ?? 'other'));
$reason = trim((string) ($input['reason'] ?? ''));
$notes = trim((string) ($input['notes'] ?? ''));

if ($reason === '') {
    send_json(['ok' => false, 'message' => 'Reason is required'], 422);
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
    ensure_client_service_requests_table($pdo);

    $clientStmt = $pdo->prepare('SELECT id FROM clients WHERE id = :id LIMIT 1');
    $clientStmt->execute(['id' => $clientId]);
    if (!$clientStmt->fetch()) {
        send_json(['ok' => false, 'message' => 'Client not found'], 404);
    }

    $code = generate_service_request_code($pdo);
    $stmt = $pdo->prepare(
        'INSERT INTO client_service_requests
        (request_code, client_id, request_kind, request_type, current_value, new_value, effective_date, priority, status,
         termination_reason, reason, notes, requested_by_client)
        VALUES
        (:request_code, :client_id, :request_kind, :request_type, :current_value, :new_value, :effective_date, :priority, :status,
         :termination_reason, :reason, :notes, 1)'
    );

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
    ]);

    send_json([
        'ok' => true,
        'message' => 'Service request submitted successfully',
        'request' => [
            'id' => (int) $pdo->lastInsertId(),
            'request_code' => $code,
            'request_kind' => $requestKind,
            'request_type' => $requestType,
            'status' => 'pending',
        ],
    ], 201);
} catch (Throwable $e) {
    send_json(['ok' => false, 'message' => 'Failed to submit service request', 'error' => $e->getMessage()], 500);
}
