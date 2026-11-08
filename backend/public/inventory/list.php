<?php

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    send_json(['ok' => false, 'message' => 'Method not allowed'], 405);
}

$user = require_auth();

$search = trim((string) ($_GET['search'] ?? ''));
$category = trim((string) ($_GET['category'] ?? ''));
$status = trim((string) ($_GET['status'] ?? 'active'));
$stockStatus = trim((string) ($_GET['stock_status'] ?? ''));
$limit = max(20, min(500, (int) ($_GET['limit'] ?? 200)));
$offset = max(0, (int) ($_GET['offset'] ?? 0));

try {
    $pdo = db();
    ensure_inventory_schema($pdo);

    $whereSql = ' WHERE 1=1';
    $params = [];

    if ($status === 'active') {
        $whereSql .= ' AND i.active_status = 1';
    } elseif ($status === 'inactive') {
        $whereSql .= ' AND i.active_status = 0';
    }

    if ($search !== '') {
        $whereSql .= ' AND (i.item_code LIKE :search_code OR i.item_name LIKE :search_name)';
        $params['search_code'] = '%' . $search . '%';
        $params['search_name'] = '%' . $search . '%';
    }

    if ($category !== '') {
        $whereSql .= ' AND i.category_name = :category';
        $params['category'] = $category;
    }

    if ($stockStatus === 'good') {
        $whereSql .= ' AND i.current_stock > i.min_stock';
    } elseif ($stockStatus === 'critical') {
        $whereSql .= ' AND i.current_stock <= i.min_stock';
    } elseif ($stockStatus === 'low') {
        $whereSql .= ' AND i.current_stock > 0 AND i.current_stock <= i.min_stock';
    } elseif ($stockStatus === 'out') {
        $whereSql .= ' AND i.current_stock <= 0';
    }

    $scope = inventory_scope_sql($user, 'i', 'scope_employee_id');
    $whereSql .= $scope['sql'];
    $params = array_merge($params, $scope['params']);

    $sql =
        'SELECT i.id, i.item_code, i.item_name, i.category_name, i.unit_label,
                i.min_stock, i.current_stock, i.unit_cost, i.active_status,
                i.created_by_employee_id, i.assigned_to_employee_id,
                i.created_at, i.updated_at
         FROM inventory_items i' .
        $whereSql .
        ' ORDER BY i.id DESC LIMIT ' . (int) $limit . ' OFFSET ' . (int) $offset;

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $items = $stmt->fetchAll();

    $countStmt = $pdo->prepare('SELECT COUNT(*) FROM inventory_items i' . $whereSql);
    $countStmt->execute($params);
    $totalCount = (int) $countStmt->fetchColumn();

    send_json([
        'ok' => true,
        'items' => $items,
        'total_count' => $totalCount,
    ]);
} catch (Throwable $e) {
    send_json([
        'ok' => false,
        'message' => 'Failed to load inventory items: ' . $e->getMessage(),
        'error' => $e->getMessage(),
    ], 500);
}
