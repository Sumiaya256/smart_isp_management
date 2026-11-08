<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/database.php';
require_once dirname(__DIR__, 2) . '/config/auth.php';
require_once __DIR__ . '/service_request_helpers.php';

function can_manage_service_requests(array $user): bool
{
    $roleKey = preg_replace('/[^a-z]/', '', strtolower(trim((string) ($user['role_name'] ?? ''))));
    $positionKey = preg_replace('/[^a-z]/', '', strtolower(trim((string) ($user['position_name'] ?? ''))));

    if (user_has_module_action_access($user, 'Client', 'edit', true)) {
        return true;
    }
    if (user_has_module_action_access($user, 'Client', 'add', true)) {
        return true;
    }

    return in_array($roleKey, ['admindirector', 'adminstaff', 'superadmin', 'admin', 'administration'], true)
        || in_array($positionKey, ['admindirector', 'adminstaff', 'superadmin', 'admin'], true);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json(['ok' => false, 'message' => 'Method not allowed'], 405);
}

$user = require_auth();
$input = read_json_input();

$requestId = (int) ($input['request_id'] ?? 0);
$status = normalize_service_request_status((string) ($input['status'] ?? 'pending'));
$notes = trim((string) ($input['notes'] ?? ''));

if ($requestId <= 0) {
    send_json(['ok' => false, 'message' => 'Invalid request ID'], 422);
}

try {
    $pdo = db();
    ensure_client_scope_columns($pdo);
    ensure_client_service_requests_table($pdo);

    $kindStmt = $pdo->prepare('SELECT request_kind FROM client_service_requests WHERE id = :id LIMIT 1');
    $kindStmt->execute(['id' => $requestId]);
    $requestKind = strtolower(trim((string) ($kindStmt->fetchColumn() ?: '')));

    if ($requestKind === 'close_connection') {
        if (!user_has_page_action_access($user, 'Left Client', 'edit')) {
            send_json(['ok' => false, 'message' => 'Forbidden: insufficient page permission'], 403);
        }
    } else {
        if (!user_has_page_action_access($user, 'Change Request', 'edit')) {
            send_json(['ok' => false, 'message' => 'Forbidden: insufficient page permission'], 403);
        }
    }

    enforce_service_request_scope_for_id($pdo, $user, $requestId);

    $existingStmt = $pdo->prepare('SELECT notes FROM client_service_requests WHERE id = :id LIMIT 1');
    $existingStmt->execute(['id' => $requestId]);
    $existingNotes = (string) ($existingStmt->fetchColumn() ?: '');

    $mergedNotes = $existingNotes;
    if ($notes !== '') {
        $mergedNotes = $existingNotes !== '' ? $existingNotes . "\n" . $notes : $notes;
    }

    $stmt = $pdo->prepare(
        'UPDATE client_service_requests
         SET status = :status,
             notes = :notes,
             updated_by_employee_id = :updated_by_employee_id
         WHERE id = :id'
    );

    $employeeId = (int) ($user['id'] ?? 0);

    $stmt->execute([
        'status' => $status,
        'notes' => $mergedNotes !== '' ? $mergedNotes : null,
        'updated_by_employee_id' => $employeeId > 0 ? $employeeId : null,
        'id' => $requestId,
    ]);

    send_json(['ok' => true, 'message' => 'Request status updated']);
} catch (Throwable $e) {
    send_json(['ok' => false, 'message' => 'Failed to update request status', 'error' => $e->getMessage()], 500);
}
