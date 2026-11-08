<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/database.php';
require_once dirname(__DIR__, 2) . '/config/auth.php';
require_once __DIR__ . '/scope_helpers.php';
require_once __DIR__ . '/portal_manage_helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    send_json(['ok' => false, 'message' => 'Method not allowed'], 405);
}

$user = require_page_permission('Portal Manage', 'view');
$search = trim((string) ($_GET['search'] ?? ''));
$status = trim((string) ($_GET['status'] ?? ''));

try {
    $pdo = db();
    try {
        ensure_client_scope_columns($pdo);
        ensure_client_portal_tracking_columns($pdo);
    } catch (Throwable $e) {
        // Keep loading even if bootstrap DDL is not available.
    }

    $settings = [];
    try {
        $settings = read_client_portal_settings($pdo);
    } catch (Throwable $e) {
        $settings = [];
    }

    $sql = 'SELECT c.id, c.client_code, c.full_name, c.connection_username, c.connection_email, c.status,
                   c.connection_start_date, c.payment_cycle_date, c.portal_last_login_at, c.portal_login_count,
                   COALESCE(p.package_name, "N/A") AS package_name,
                   COALESCE(p.speed_mbps, 0) AS speed_mbps,
                   COALESCE(p.monthly_price, 0) AS monthly_price
            FROM clients c
            LEFT JOIN internet_packages p ON p.id = c.package_id
            WHERE 1 = 1';
    $params = [];

    if ($search !== '') {
        $sql .= ' AND (c.full_name LIKE :search OR c.client_code LIKE :search OR c.connection_email LIKE :search)';
        $params['search'] = '%' . $search . '%';
    }

    if ($status !== '') {
        $normalizedStatus = strtolower(trim($status));
        if ($normalizedStatus === 'locked') {
            $sql .= ' AND LOWER(TRIM(COALESCE(c.status, ""))) IN ("paused", "locked", "inactive")';
        } else {
            $sql .= ' AND LOWER(TRIM(COALESCE(c.status, ""))) = :status';
            $params['status'] = $normalizedStatus;
        }
    }

    $scope = apply_client_scope_where($user, 'c', 'scope_employee_id');
    $sql .= $scope['sql'];
    $params = array_merge($params, $scope['params']);
    $sql .= ' ORDER BY c.id DESC LIMIT 400';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $users = $stmt->fetchAll();

    $statsSql =
        'SELECT
            SUM(CASE WHEN COALESCE(NULLIF(LOWER(TRIM(c.status)), ""), "active") = "active" THEN 1 ELSE 0 END) AS active_portal_users,
            SUM(CASE WHEN LOWER(TRIM(COALESCE(c.status, ""))) IN ("paused", "locked", "inactive") THEN 1 ELSE 0 END) AS locked_accounts,
            SUM(CASE WHEN DATE_FORMAT(c.connection_start_date, "%Y-%m") = DATE_FORMAT(CURDATE(), "%Y-%m") THEN 1 ELSE 0 END) AS new_registrations,
            SUM(CASE WHEN DATE_FORMAT(c.portal_last_login_at, "%Y-%m") = DATE_FORMAT(CURDATE(), "%Y-%m") THEN 1 ELSE 0 END) AS monthly_logins
         FROM clients c
         WHERE 1 = 1';

    $statsParams = [];
    $statsScope = apply_client_scope_where($user, 'c', 'stats_scope_employee_id');
    $statsSql .= $statsScope['sql'];
    $statsParams = array_merge($statsParams, $statsScope['params']);

    $statsStmt = $pdo->prepare($statsSql);
    $statsStmt->execute($statsParams);
    $statsRow = $statsStmt->fetch() ?: [];

    $stats = [
        'active_portal_users' => (int) ($statsRow['active_portal_users'] ?? 0),
        'new_registrations' => (int) ($statsRow['new_registrations'] ?? 0),
        'monthly_logins' => (int) ($statsRow['monthly_logins'] ?? 0),
        'locked_accounts' => (int) ($statsRow['locked_accounts'] ?? 0),
    ];

    send_json([
        'ok' => true,
        'users' => $users,
        'stats' => $stats,
        'settings' => $settings,
        'can_edit' => user_has_page_action_access($user, 'Portal Manage', 'edit'),
    ]);
} catch (Throwable $e) {
    send_json(['ok' => false, 'message' => 'Failed to load portal management data', 'error' => $e->getMessage()], 500);
}
