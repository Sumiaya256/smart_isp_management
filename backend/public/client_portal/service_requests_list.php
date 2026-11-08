<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/database.php';
require_once dirname(__DIR__, 2) . '/config/auth.php';
require_once dirname(__DIR__) . '/clients/service_request_helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
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

$kind = strtolower(trim((string) ($_GET['kind'] ?? '')));
$status = normalize_service_request_status((string) ($_GET['status'] ?? ''));
$statusRaw = strtolower(trim((string) ($_GET['status'] ?? '')));

try {
    $pdo = db();
    ensure_client_service_requests_table($pdo);

    $sql =
        'SELECT csr.id, csr.request_code, csr.client_id, csr.request_kind, csr.request_type, csr.current_value,
                csr.new_value, csr.effective_date, csr.priority, csr.status, csr.termination_reason,
                csr.reason, csr.notes, csr.requested_by_client, csr.created_at, csr.updated_at
         FROM client_service_requests csr
         WHERE csr.client_id = :client_id';

    $params = ['client_id' => $clientId];

    if ($kind !== '' && in_array($kind, ['change', 'close_connection'], true)) {
        $sql .= ' AND csr.request_kind = :kind';
        $params['kind'] = $kind;
    }

    if ($statusRaw !== '' && in_array($statusRaw, ['pending', 'in_progress', 'scheduled', 'completed', 'cancelled', 'rejected'], true)) {
        $sql .= ' AND csr.status = :status';
        $params['status'] = $status;
    }

    $sql .= ' ORDER BY csr.id DESC LIMIT 200';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    send_json(['ok' => true, 'requests' => $rows]);
} catch (Throwable $e) {
    send_json(['ok' => false, 'message' => 'Failed to load service requests', 'error' => $e->getMessage()], 500);
}
