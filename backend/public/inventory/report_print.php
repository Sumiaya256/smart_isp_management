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

    header('Content-Type: text/html; charset=UTF-8');

    echo '<!doctype html><html><head><meta charset="utf-8"><title>Inventory Report</title>';
    echo '<style>body{font-family:Arial,sans-serif;padding:20px;color:#222}h2{margin:0 0 10px}table{border-collapse:collapse;width:100%}th,td{border:1px solid #ddd;padding:8px;font-size:12px}th{background:#f4f6fa;text-align:left}.meta{color:#666;font-size:12px;margin:0 0 15px}</style>';
    echo '</head><body>';
    echo '<h2>Inventory Report</h2>';
    echo '<p class="meta">Generated: ' . htmlspecialchars(date('Y-m-d H:i:s')) . '</p>';
    echo '<table><thead><tr><th>Item Code</th><th>Item Name</th><th>Category</th><th>Unit</th><th>Current Stock</th><th>Min Stock</th><th>Unit Cost</th><th>Stock Value</th><th>Status</th></tr></thead><tbody>';

    $grand = 0.0;
    foreach ($rows as $row) {
        $stock = (float) ($row['current_stock'] ?? 0);
        $cost = (float) ($row['unit_cost'] ?? 0);
        $value = $stock * $cost;
        $grand += $value;

        echo '<tr>';
        echo '<td>' . htmlspecialchars((string) ($row['item_code'] ?? '')) . '</td>';
        echo '<td>' . htmlspecialchars((string) ($row['item_name'] ?? '')) . '</td>';
        echo '<td>' . htmlspecialchars((string) ($row['category_name'] ?? '')) . '</td>';
        echo '<td>' . htmlspecialchars((string) ($row['unit_label'] ?? '')) . '</td>';
        echo '<td>' . number_format($stock, 2) . '</td>';
        echo '<td>' . number_format((float) ($row['min_stock'] ?? 0), 2) . '</td>';
        echo '<td>' . number_format($cost, 2) . '</td>';
        echo '<td>' . number_format($value, 2) . '</td>';
        echo '<td>' . ((((int) ($row['active_status'] ?? 1)) === 1) ? 'Active' : 'Inactive') . '</td>';
        echo '</tr>';
    }

    echo '<tr><td colspan="7" style="text-align:right;font-weight:bold">Grand Stock Value</td><td style="font-weight:bold">' . number_format($grand, 2) . '</td><td></td></tr>';
    echo '</tbody></table>';
    echo '<script>window.addEventListener("load", function(){ window.print(); });</script>';
    echo '</body></html>';
    exit;
} catch (Throwable $e) {
    send_json([
        'ok' => false,
        'message' => 'Failed to build printable inventory report',
        'error' => $e->getMessage(),
    ], 500);
}
