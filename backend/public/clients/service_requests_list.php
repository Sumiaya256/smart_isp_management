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

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    send_json(['ok' => false, 'message' => 'Method not allowed'], 405);
}

$user = require_auth();

$kind = strtolower(trim((string) ($_GET['kind'] ?? '')));
$status = normalize_service_request_status((string) ($_GET['status'] ?? ''));
$statusRaw = strtolower(trim((string) ($_GET['status'] ?? '')));
$search = trim((string) ($_GET['search'] ?? ''));

try {
    if ($kind === 'close_connection') {
        if (!user_has_page_action_access($user, 'Left Client', 'view')) {
            send_json(['ok' => false, 'message' => 'Forbidden: insufficient page permission'], 403);
        }
    } elseif ($kind === 'change') {
        if (!user_has_page_action_access($user, 'Change Request', 'view')) {
            send_json(['ok' => false, 'message' => 'Forbidden: insufficient page permission'], 403);
        }
    } elseif (!user_has_any_page_action_access($user, ['Change Request', 'Left Client'], 'view')) {
        send_json(['ok' => false, 'message' => 'Forbidden: insufficient page permission'], 403);
    }

    $pdo = db();
    ensure_client_scope_columns($pdo);
    ensure_client_service_requests_table($pdo);

    $sql =
        'SELECT csr.id, csr.request_code, csr.client_id, csr.request_kind, csr.request_type, csr.current_value,
                csr.new_value, csr.effective_date, csr.priority, csr.status, csr.termination_reason,
                csr.reason, csr.notes, csr.requested_by_client, csr.created_at, csr.updated_at,
                c.client_code, c.full_name, c.phone,
                COALESCE(p.package_name, "N/A") AS package_name
         FROM client_service_requests csr
         INNER JOIN clients c ON c.id = csr.client_id
         LEFT JOIN internet_packages p ON p.id = c.package_id
         WHERE 1 = 1';

    $params = [];

    if ($kind !== '' && in_array($kind, ['change', 'close_connection'], true)) {
        $sql .= ' AND csr.request_kind = :kind';
        $params['kind'] = $kind;
    }

    if ($statusRaw !== '' && in_array($statusRaw, ['pending', 'in_progress', 'scheduled', 'completed', 'cancelled', 'rejected'], true)) {
        $sql .= ' AND csr.status = :status';
        $params['status'] = $status;
    }

    if ($search !== '') {
        $sql .= ' AND (csr.request_code LIKE :search OR c.client_code LIKE :search OR c.full_name LIKE :search OR c.phone LIKE :search)';
        $params['search'] = '%' . $search . '%';
    }

    $scope = apply_client_scope_where($user, 'c', 'scope_employee_id');
    $sql .= $scope['sql'];
    $params = array_merge($params, $scope['params']);

    $sql .= ' ORDER BY csr.id DESC LIMIT 500';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    $statsSql =
        'SELECT
            SUM(CASE WHEN csr.status = "pending" THEN 1 ELSE 0 END) AS pending_count,
            SUM(CASE WHEN csr.status = "in_progress" THEN 1 ELSE 0 END) AS in_progress_count,
            SUM(CASE WHEN csr.status = "completed" THEN 1 ELSE 0 END) AS completed_count,
            SUM(CASE WHEN csr.request_kind = "change" AND csr.request_type = "package_change" THEN 1 ELSE 0 END) AS package_change_count,
            COUNT(*) AS total_count
         FROM client_service_requests csr
         INNER JOIN clients c ON c.id = csr.client_id
         WHERE 1 = 1';

    $statsParams = [];
    if ($kind !== '' && in_array($kind, ['change', 'close_connection'], true)) {
        $statsSql .= ' AND csr.request_kind = :kind';
        $statsParams['kind'] = $kind;
    }
    if ($statusRaw !== '' && in_array($statusRaw, ['pending', 'in_progress', 'scheduled', 'completed', 'cancelled', 'rejected'], true)) {
        $statsSql .= ' AND csr.status = :status';
        $statsParams['status'] = $status;
    }
    if ($search !== '') {
        $statsSql .= ' AND (csr.request_code LIKE :search OR c.client_code LIKE :search OR c.full_name LIKE :search OR c.phone LIKE :search)';
        $statsParams['search'] = '%' . $search . '%';
    }
    $statsScope = apply_client_scope_where($user, 'c', 'stats_scope_employee_id');
    $statsSql .= $statsScope['sql'];
    $statsParams = array_merge($statsParams, $statsScope['params']);

    $statsStmt = $pdo->prepare($statsSql);
    $statsStmt->execute($statsParams);
    $stats = $statsStmt->fetch() ?: [];

    $canEdit = false;
    if ($kind === 'close_connection') {
        $canEdit = user_has_page_action_access($user, 'Left Client', 'edit');
    } elseif ($kind === 'change') {
        $canEdit = user_has_page_action_access($user, 'Change Request', 'edit');
    } else {
        $canEdit = user_has_any_page_action_access($user, ['Change Request', 'Left Client'], 'edit');
    }

    send_json([
        'ok' => true,
        'count' => count($rows),
        'requests' => $rows,
        'stats' => [
            'pending' => (int) ($stats['pending_count'] ?? 0),
            'in_progress' => (int) ($stats['in_progress_count'] ?? 0),
            'completed' => (int) ($stats['completed_count'] ?? 0),
            'package_change' => (int) ($stats['package_change_count'] ?? 0),
            'total' => (int) ($stats['total_count'] ?? 0),
        ],
        'can_edit' => $canEdit,
    ]);
} catch (Throwable $e) {
    send_json(['ok' => false, 'message' => 'Failed to load service requests', 'error' => $e->getMessage()], 500);
}
