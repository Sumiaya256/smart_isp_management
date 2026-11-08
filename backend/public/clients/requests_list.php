<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/database.php';
require_once dirname(__DIR__, 2) . '/config/auth.php';
require_once __DIR__ . '/request_helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    send_json(['ok' => false, 'message' => 'Method not allowed'], 405);
}

$user = require_page_permission('New Request', 'view');

$search = trim((string) ($_GET['search'] ?? ''));
$status = normalize_request_status((string) ($_GET['status'] ?? ''));
$statusRaw = strtolower(trim((string) ($_GET['status'] ?? '')));
$limit = (int) ($_GET['limit'] ?? 50);
$limit = max(10, min(200, $limit));

try {
    $pdo = db();
    ensure_connection_requests_table($pdo);

    $whereSql = ' WHERE 1 = 1';
    $params = [];

    if ($search !== '') {
        $whereSql .= ' AND (r.client_name LIKE :search OR r.phone LIKE :search OR r.request_code LIKE :search OR r.address_line LIKE :search)';
        $params['search'] = '%' . $search . '%';
    }

    if ($statusRaw !== '' && in_array($statusRaw, ['pending', 'scheduled', 'completed', 'cancelled'], true)) {
        $whereSql .= ' AND r.status = :status';
        $params['status'] = $status;
    }

    $scope = apply_connection_request_scope_where($user, 'r', 'scope_employee_id');
    $whereSql .= $scope['sql'];
    $params = array_merge($params, $scope['params']);

    $sql =
        'SELECT r.id, r.request_code, r.client_name, r.phone, r.email, r.address_line,
                r.package_slug, r.package_name, r.connection_type, r.preferred_date, r.preferred_time,
                r.notes, r.status, r.created_by_employee_id, r.assigned_to_employee_id, r.created_at, r.updated_at
         FROM client_connection_requests r' .
        $whereSql .
        ' ORDER BY r.id DESC LIMIT ' . (int) $limit;

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    $statsStmt = $pdo->prepare(
        'SELECT
            SUM(CASE WHEN r.status = "pending" THEN 1 ELSE 0 END) AS pending_count,
            SUM(CASE WHEN r.status = "scheduled" THEN 1 ELSE 0 END) AS scheduled_count,
            SUM(CASE WHEN r.status = "completed" THEN 1 ELSE 0 END) AS completed_count,
            COUNT(*) AS total_count
         FROM client_connection_requests r' . $whereSql
    );
    $statsStmt->execute($params);
    $stats = $statsStmt->fetch() ?: [];

    send_json([
        'ok' => true,
        'requests' => $rows,
        'count' => count($rows),
        'stats' => [
            'pending' => (int) ($stats['pending_count'] ?? 0),
            'scheduled' => (int) ($stats['scheduled_count'] ?? 0),
            'completed' => (int) ($stats['completed_count'] ?? 0),
            'total' => (int) ($stats['total_count'] ?? 0),
        ],
        'can_create' => user_has_page_action_access($user, 'New Request', 'edit'),
    ]);
} catch (Throwable $e) {
    send_json(['ok' => false, 'message' => 'Failed to load requests', 'error' => $e->getMessage()], 500);
}
