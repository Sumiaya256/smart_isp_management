<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/database.php';
require_once dirname(__DIR__, 2) . '/config/auth.php';
require_once __DIR__ . '/scope_helpers.php';
require_once __DIR__ . '/left_helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    send_json(['ok' => false, 'message' => 'Method not allowed'], 405);
}

$user = require_page_permission('Left Client', 'view');

$search = trim((string) ($_GET['search'] ?? ''));
$reason = trim((string) ($_GET['reason'] ?? ''));
$package = trim((string) ($_GET['package'] ?? ''));
$fromDate = trim((string) ($_GET['from_date'] ?? ''));
$toDate = trim((string) ($_GET['to_date'] ?? ''));

try {
    $pdo = db();
    ensure_client_scope_columns($pdo);
    ensure_left_clients_table($pdo);

    $sql = 'SELECT lc.id, c.id as client_id, c.client_code, c.full_name, c.phone, c.email,
                   COALESCE(p.package_name, "N/A") AS package_name,
                   c.connection_start_date, lc.termination_date, lc.termination_reason,
                   lc.pending_dues, lc.equipment_status, lc.final_reading, lc.notes
            FROM left_clients lc
            INNER JOIN clients c ON lc.client_id = c.id
            LEFT JOIN internet_packages p ON c.package_id = p.id
            WHERE 1 = 1';

    $params = [];

    if ($search !== '') {
        $sql .= ' AND (c.full_name LIKE :search OR c.phone LIKE :search OR c.client_code LIKE :search)';
        $params['search'] = '%' . $search . '%';
    }

    if ($reason !== '') {
        $sql .= ' AND lc.termination_reason = :reason';
        $params['reason'] = $reason;
    }

    if ($package !== '') {
        $sql .= ' AND p.package_name = :package';
        $params['package'] = $package;
    }

    if ($fromDate !== '') {
        $sql .= ' AND lc.termination_date >= :from_date';
        $params['from_date'] = $fromDate;
    }

    if ($toDate !== '') {
        $sql .= ' AND lc.termination_date <= :to_date';
        $params['to_date'] = $toDate;
    }

    $scope = apply_client_scope_where($user, 'c', 'scope_employee_id');
    $sql .= $scope['sql'];
    $params = array_merge($params, $scope['params']);

    $sql .= ' ORDER BY lc.termination_date DESC LIMIT 500';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    $statsSql = 'SELECT COUNT(*) as total_left,
                        COUNT(CASE WHEN MONTH(lc.termination_date) = MONTH(NOW()) AND YEAR(lc.termination_date) = YEAR(NOW()) THEN 1 END) as this_month_left,
                        COALESCE(SUM(lc.pending_dues), 0) as total_dues,
                        COUNT(CASE WHEN lc.equipment_status = "not-returned" THEN 1 END) as equipment_pending
                 FROM left_clients lc
                 INNER JOIN clients c ON lc.client_id = c.id
                 LEFT JOIN internet_packages p ON c.package_id = p.id
                 WHERE 1 = 1';

    $statsParams = [];
    if ($search !== '') {
        $statsSql .= ' AND (c.full_name LIKE :search OR c.phone LIKE :search OR c.client_code LIKE :search)';
        $statsParams['search'] = '%' . $search . '%';
    }
    if ($reason !== '') {
        $statsSql .= ' AND lc.termination_reason = :reason';
        $statsParams['reason'] = $reason;
    }
    if ($package !== '') {
        $statsSql .= ' AND p.package_name = :package';
        $statsParams['package'] = $package;
    }
    if ($fromDate !== '') {
        $statsSql .= ' AND lc.termination_date >= :from_date';
        $statsParams['from_date'] = $fromDate;
    }
    if ($toDate !== '') {
        $statsSql .= ' AND lc.termination_date <= :to_date';
        $statsParams['to_date'] = $toDate;
    }

    $statsScope = apply_client_scope_where($user, 'c', 'stats_scope_employee_id');
    $statsSql .= $statsScope['sql'];
    $statsParams = array_merge($statsParams, $statsScope['params']);

    $statsStmt = $pdo->prepare($statsSql);
    $statsStmt->execute($statsParams);
    $stats = $statsStmt->fetch();

    send_json([
        'ok' => true,
        'count' => count($rows),
        'stats' => $stats,
        'can_terminate' => user_has_page_action_access($user, 'Left Client', 'edit'),
        'clients' => $rows,
    ]);
} catch (Throwable $e) {
    send_json(['ok' => false, 'message' => 'Failed to fetch left clients', 'error' => $e->getMessage()], 500);
}
