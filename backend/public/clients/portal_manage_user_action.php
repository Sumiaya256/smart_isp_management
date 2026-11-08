<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/database.php';
require_once dirname(__DIR__, 2) . '/config/auth.php';
require_once __DIR__ . '/portal_manage_helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json(['ok' => false, 'message' => 'Method not allowed'], 405);
}

$user = require_page_permission('Portal Manage', 'edit');
$input = read_json_input();
$clientId = (int) ($input['client_id'] ?? 0);
$action = strtolower(trim((string) ($input['action'] ?? '')));

if ($clientId <= 0 || $action === '') {
    send_json(['ok' => false, 'message' => 'Invalid request'], 422);
}

function normalize_portal_status(string $status): string
{
    $normalized = strtolower(trim($status));
    if ($normalized === '' || $normalized === 'active') {
        return 'active';
    }
    if ($normalized === 'paused' || $normalized === 'locked' || $normalized === 'inactive') {
        return 'locked';
    }
    return $normalized;
}

try {
    $pdo = db();
    ensure_client_portal_auth_columns($pdo);
    ensure_client_portal_tracking_columns($pdo);

    $clientStmt = $pdo->prepare('SELECT id, full_name, client_code, connection_email, status, left_date FROM clients WHERE id = :id LIMIT 1');
    $clientStmt->execute(['id' => $clientId]);
    $client = $clientStmt->fetch();
    if (!$client) {
        send_json(['ok' => false, 'message' => 'Client not found'], 404);
    }

    $clientStatus = strtolower(trim((string) ($client['status'] ?? '')));
    $isTerminated = !empty($client['left_date']) || in_array($clientStatus, ['disconnected', 'left', 'resigned'], true);

    if ($action === 'lock' || $action === 'unlock' || $action === 'activate') {
        if ($isTerminated) {
            send_json(['ok' => false, 'message' => 'Status cannot be changed for terminated clients'], 409);
        }

        $targetStatus = $action === 'lock' ? 'paused' : 'active';
        $stmt = $pdo->prepare('UPDATE clients SET status = :status WHERE id = :id');
        $stmt->execute([
            'status' => $targetStatus,
            'id' => $clientId,
        ]);

        $verifyStmt = $pdo->prepare('SELECT status FROM clients WHERE id = :id LIMIT 1');
        $verifyStmt->execute(['id' => $clientId]);
        $updatedStatus = strtolower(trim((string) ($verifyStmt->fetchColumn() ?: '')));
        $portalStatus = normalize_portal_status($updatedStatus);
        $expectedPortalStatus = $action === 'lock' ? 'locked' : 'active';

        if ($portalStatus !== $expectedPortalStatus) {
            send_json([
                'ok' => false,
                'message' => 'Status update did not persist',
                'error' => 'Stored status is "' . $updatedStatus . '" after update.',
            ], 500);
        }

        send_json([
            'ok' => true,
            'message' => $expectedPortalStatus === 'locked' ? 'Account locked' : 'Account activated',
            'status' => $updatedStatus,
            'portal_status' => $portalStatus,
        ]);
    }

    if ($action === 'reset_password') {
        $tempPassword = 'PM' . strtoupper(bin2hex(random_bytes(4)));
        $hash = password_hash($tempPassword, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare('UPDATE clients SET connection_password_hash = :hash WHERE id = :id');
        $stmt->execute(['hash' => $hash, 'id' => $clientId]);
        send_json(['ok' => true, 'message' => 'Password reset', 'temp_password' => $tempPassword]);
    }

    send_json(['ok' => false, 'message' => 'Unsupported action'], 422);
} catch (Throwable $e) {
    send_json(['ok' => false, 'message' => 'Failed to process action', 'error' => $e->getMessage()], 500);
}