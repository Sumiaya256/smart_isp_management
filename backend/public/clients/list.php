<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/database.php';
require_once dirname(__DIR__, 2) . '/config/auth.php';
require_once __DIR__ . '/scope_helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    send_json(['ok' => false, 'message' => 'Method not allowed'], 405);
}

$user = require_page_permission('Client List', 'view');

$search = trim((string) ($_GET['search'] ?? ''));
$zone = trim((string) ($_GET['zone'] ?? ''));
$ward = trim((string) ($_GET['ward'] ?? ''));
$clientCode = trim((string) ($_GET['client_code'] ?? ''));

try {
    $pdo = db();
    ensure_client_scope_columns($pdo);

    $sql =
        'SELECT c.id, c.client_code, c.full_name, c.phone, c.email, c.nid, c.birth_date,
                c.address_line, c.ward, c.zone_name, c.road_no, c.status,
                c.connection_type, c.connection_start_date, c.payment_cycle_date,
                c.connection_email, c.onu_mac, c.router_ip,
                c.referral_name, c.emergency_contact, c.notes,
                COALESCE(p.package_name, "N/A") AS package_name,
                COALESCE(p.speed_mbps, 0) AS speed_mbps,
                COALESCE(p.monthly_price, 0) AS monthly_price,
                COALESCE(i.status, "unbilled") AS billing_status,
                i.invoice_no AS latest_invoice_no,
                i.due_date AS latest_due_date
         FROM clients c
         LEFT JOIN internet_packages p ON p.id = c.package_id
         LEFT JOIN (
            SELECT i1.client_id, i1.invoice_no, i1.status, i1.due_date
            FROM invoices i1
            INNER JOIN (
                SELECT client_id, MAX(id) AS max_id
                FROM invoices
                GROUP BY client_id
            ) x ON x.client_id = i1.client_id AND x.max_id = i1.id
         ) i ON i.client_id = c.id
         WHERE 1 = 1';

    $params = [];

    if ($search !== '') {
        $sql .= ' AND (c.full_name LIKE :search OR c.phone LIKE :search OR c.client_code LIKE :search)';
        $params['search'] = '%' . $search . '%';
    }

    if ($zone !== '') {
        $sql .= ' AND c.zone_name = :zone';
        $params['zone'] = $zone;
    }

    if ($ward !== '') {
        $sql .= ' AND c.ward = :ward';
        $params['ward'] = $ward;
    }

    if ($clientCode !== '') {
        $sql .= ' AND c.client_code = :client_code';
        $params['client_code'] = $clientCode;
    }

    $scope = apply_client_scope_where($user, 'c', 'scope_employee_id');
    $sql .= $scope['sql'];
    $params = array_merge($params, $scope['params']);

    $sql .= ' ORDER BY c.id DESC LIMIT 500';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    send_json([
        'ok' => true,
        'count' => count($rows),
        'clients' => $rows,
        'can_edit' => user_has_page_action_access($user, 'Client List', 'edit'),
        'can_delete' => user_has_page_action_access($user, 'Client List', 'edit'),
    ]);
} catch (Throwable $e) {
    send_json(['ok' => false, 'message' => 'Failed to fetch clients', 'error' => $e->getMessage()], 500);
}
