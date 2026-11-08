<?php

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    send_json(['ok' => false, 'message' => 'Method not allowed'], 405);
}

$user = require_auth();

$status = trim((string) ($_GET['status'] ?? ''));
$vendor = trim((string) ($_GET['vendor'] ?? ''));
$category = trim((string) ($_GET['category'] ?? ''));
$search = trim((string) ($_GET['search'] ?? ''));
$limit = max(20, min(500, (int) ($_GET['limit'] ?? 200)));
$offset = max(0, (int) ($_GET['offset'] ?? 0));

try {
    $pdo = db();
    ensure_purchase_schema($pdo);

    $whereSql = ' WHERE 1=1';
    $params = [];

    if ($status !== '') {
        $whereSql .= ' AND po.status_label = :status';
        $params['status'] = $status;
    }
    if ($vendor !== '') {
        $whereSql .= ' AND po.vendor_name = :vendor';
        $params['vendor'] = $vendor;
    }
    if ($category !== '') {
        $whereSql .= ' AND po.category_name = :category';
        $params['category'] = $category;
    }
    if ($search !== '') {
        $whereSql .= ' AND (po.po_number LIKE :search_po OR po.vendor_name LIKE :search_vendor OR po.requested_by_name LIKE :search_req)';
        $params['search_po'] = '%' . $search . '%';
        $params['search_vendor'] = '%' . $search . '%';
        $params['search_req'] = '%' . $search . '%';
    }

    $scope = purchase_scope_sql($user, 'po', 'scope_employee_id');
    $whereSql .= $scope['sql'];
    $params = array_merge($params, $scope['params']);

    $listSql =
        'SELECT po.id, po.po_number, po.order_date, po.vendor_name, po.category_name, po.requested_by_name,
                po.delivery_date, po.status_label, po.notes, po.total_amount,
                po.created_by_employee_id, po.assigned_to_employee_id,
                po.created_at, po.updated_at
         FROM purchase_orders po' .
        $whereSql .
        ' ORDER BY po.id DESC LIMIT ' . (int) $limit . ' OFFSET ' . (int) $offset;

    $stmt = $pdo->prepare($listSql);
    $stmt->execute($params);
    $orders = $stmt->fetchAll();

    $countSql = 'SELECT COUNT(*) FROM purchase_orders po' . $whereSql;
    $countStmt = $pdo->prepare($countSql);
    $countStmt->execute($params);
    $totalCount = (int) $countStmt->fetchColumn();

    $statusParams = [];
    $statusWhere = ' WHERE 1=1';

    if ($vendor !== '') {
        $statusWhere .= ' AND po.vendor_name = :vendor';
        $statusParams['vendor'] = $vendor;
    }
    if ($category !== '') {
        $statusWhere .= ' AND po.category_name = :category';
        $statusParams['category'] = $category;
    }
    if ($search !== '') {
        $statusWhere .= ' AND (po.po_number LIKE :search_po OR po.vendor_name LIKE :search_vendor OR po.requested_by_name LIKE :search_req)';
        $statusParams['search_po'] = '%' . $search . '%';
        $statusParams['search_vendor'] = '%' . $search . '%';
        $statusParams['search_req'] = '%' . $search . '%';
    }

    $scopeStatus = purchase_scope_sql($user, 'po', 'scope_employee_id');
    $statusWhere .= $scopeStatus['sql'];
    $statusParams = array_merge($statusParams, $scopeStatus['params']);

    $statusSql =
        'SELECT po.status_label, COUNT(*) AS total
         FROM purchase_orders po' .
        $statusWhere .
        ' GROUP BY po.status_label';
    $statusStmt = $pdo->prepare($statusSql);
    $statusStmt->execute($statusParams);

    $statusCounts = [
        'all' => 0,
        'Pending' => 0,
        'Approved' => 0,
        'Received' => 0,
        'Cancelled' => 0,
        'Partial' => 0,
    ];
    foreach ($statusStmt->fetchAll() as $row) {
        $label = (string) ($row['status_label'] ?? '');
        $total = (int) ($row['total'] ?? 0);
        $statusCounts['all'] += $total;
        if (array_key_exists($label, $statusCounts)) {
            $statusCounts[$label] = $total;
        }
    }

    $itemStmt = $pdo->prepare(
        'SELECT purchase_order_id, item_name, quantity, unit_price, line_total
         FROM purchase_order_items
         WHERE purchase_order_id = :purchase_order_id
         ORDER BY id ASC'
    );

    foreach ($orders as $idx => $order) {
        $itemStmt->execute(['purchase_order_id' => (int) $order['id']]);
        $orders[$idx]['items'] = $itemStmt->fetchAll();
    }

    send_json([
        'ok' => true,
        'orders' => $orders,
        'total_count' => $totalCount,
        'status_counts' => $statusCounts,
    ]);
} catch (Throwable $e) {
    send_json([
        'ok' => false,
        'message' => 'Failed to load purchase orders',
        'error' => $e->getMessage(),
    ], 500);
}
