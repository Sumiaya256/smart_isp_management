<?php

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    send_json(['ok' => false, 'message' => 'Method not allowed'], 405);
}

require_auth();
require_module_permission('Inventory', 'view', false);

$search = trim((string) ($_GET['search'] ?? ''));
$category = trim((string) ($_GET['category'] ?? ''));
$status = trim((string) ($_GET['status'] ?? ''));
$dateFrom = trim((string) ($_GET['date_from'] ?? ''));
$dateTo = trim((string) ($_GET['date_to'] ?? ''));
$limit = max(50, min(5000, (int) ($_GET['limit'] ?? 1000)));

try {
    $pdo = db();
    ensure_inventory_schema($pdo);

    $user = current_user();
    $where = ' WHERE 1=1';
    $params = [];

    if ($status === 'active') {
        $where .= ' AND i.active_status = 1';
    } elseif ($status === 'inactive') {
        $where .= ' AND i.active_status = 0';
    }

    if ($search !== '') {
        $where .= ' AND (i.item_code LIKE :search_code OR i.item_name LIKE :search_name)';
        $params['search_code'] = '%' . $search . '%';
        $params['search_name'] = '%' . $search . '%';
    }

    if ($category !== '') {
        $where .= ' AND i.category_name = :category';
        $params['category'] = $category;
    }
    if ($dateFrom !== '') {
        $where .= ' AND DATE(i.created_at) >= :date_from';
        $params['date_from'] = $dateFrom;
    }
    if ($dateTo !== '') {
        $where .= ' AND DATE(i.created_at) <= :date_to';
        $params['date_to'] = $dateTo;
    }

    $scope = inventory_scope_sql($user, 'i', 'scope_employee_id');
    $where .= $scope['sql'];
    $params = array_merge($params, $scope['params']);

    $sql =
        'SELECT i.item_code, i.item_name, i.category_name, i.unit_label,
                i.current_stock, i.min_stock, i.unit_cost, i.active_status
         FROM inventory_items i' .
        $where .
        ' ORDER BY i.id DESC LIMIT ' . (int) $limit;

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    $filename = 'inventory_report_' . date('Ymd_His') . '.csv';
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $out = fopen('php://output', 'wb');
    if ($out === false) {
        throw new RuntimeException('Unable to open output stream');
    }

    fputcsv($out, ['Item Code', 'Item Name', 'Category', 'Unit', 'Current Stock', 'Min Stock', 'Unit Cost', 'Stock Value', 'Status']);
    foreach ($rows as $row) {
        $stock = (float) ($row['current_stock'] ?? 0);
        $cost = (float) ($row['unit_cost'] ?? 0);
        fputcsv($out, [
            $row['item_code'] ?? '',
            $row['item_name'] ?? '',
            $row['category_name'] ?? '',
            $row['unit_label'] ?? '',
            $stock,
            $row['min_stock'] ?? 0,
            $cost,
            $stock * $cost,
            ((int) ($row['active_status'] ?? 1)) === 1 ? 'Active' : 'Inactive',
        ]);
    }

    fclose($out);
    exit;
} catch (Throwable $e) {
    send_json([
        'ok' => false,
        'message' => 'Failed to export inventory report',
        'error' => $e->getMessage(),
    ], 500);
}
