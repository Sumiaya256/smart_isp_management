<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/database.php';
require_once dirname(__DIR__, 2) . '/config/auth.php';
require_once __DIR__ . '/helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    send_json(['ok' => false, 'message' => 'Method not allowed'], 405);
}

$user = require_page_permission('Bulk Client Import', 'view');

try {
    $pdo = db();
    ensure_mikrotik_bulk_table($pdo);
    cleanup_expired_mikrotik_rows($pdo);

    $sql =
        'SELECT r.id, r.batch_id, r.full_name, r.mobile, r.email, r.national_id, r.address_line, r.zone_name,
                r.connection_type, r.server_name, r.protocol_type, r.profile_name, r.username, r.password_plain,
                r.customer_type, r.package_name, r.billing_status, r.monthly_bill, r.bill_month,
                r.join_date, r.expire_date, r.row_status, r.status_note, r.linked_client_id, r.created_at
         FROM mikrotik_bulk_import_rows r
         WHERE 1 = 1';

    $params = [];
    $scope = mikrotik_scope_sql($user, 'r', 'scope_employee_id');
    $sql .= $scope['sql'];
    $params = array_merge($params, $scope['params']);

    $sql .= ' ORDER BY r.id DESC LIMIT 1000';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    $statsSql =
        'SELECT
            COUNT(*) AS total_rows,
            SUM(CASE WHEN row_status = "pending" THEN 1 ELSE 0 END) AS pending_rows,
            SUM(CASE WHEN row_status = "imported" THEN 1 ELSE 0 END) AS imported_rows,
            SUM(CASE WHEN row_status = "error" THEN 1 ELSE 0 END) AS error_rows
         FROM mikrotik_bulk_import_rows r
         WHERE 1 = 1';

    $statsSql .= $scope['sql'];
    $statsStmt = $pdo->prepare($statsSql);
    $statsStmt->execute($params);
    $stats = $statsStmt->fetch() ?: [];

    send_json([
        'ok' => true,
        'rows' => $rows,
        'can_edit' => user_has_page_action_access($user, 'Bulk Client Import', 'edit'),
        'stats' => [
            'total_rows' => (int) ($stats['total_rows'] ?? 0),
            'pending_rows' => (int) ($stats['pending_rows'] ?? 0),
            'imported_rows' => (int) ($stats['imported_rows'] ?? 0),
            'error_rows' => (int) ($stats['error_rows'] ?? 0),
        ],
    ]);
} catch (Throwable $e) {
    send_json(['ok' => false, 'message' => 'Failed to load bulk import rows', 'error' => $e->getMessage()], 500);
}
