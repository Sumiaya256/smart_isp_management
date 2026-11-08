<?php

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    send_json(['ok' => false, 'message' => 'Method not allowed'], 405);
}

require_auth();
require_module_permission('Purchase', 'view', false);

$status = trim((string) ($_GET['status'] ?? ''));
$vendor = trim((string) ($_GET['vendor'] ?? ''));
$category = trim((string) ($_GET['category'] ?? ''));
$search = trim((string) ($_GET['search'] ?? ''));
$dateFrom = trim((string) ($_GET['date_from'] ?? ''));
$dateTo = trim((string) ($_GET['date_to'] ?? ''));
$limit = max(50, min(5000, (int) ($_GET['limit'] ?? 1000)));

try {
    $pdo = db();
    ensure_purchase_schema($pdo);

    $user = current_user();
    $where = ' WHERE 1=1';
    $params = [];

    if ($status !== '') {
        $where .= ' AND po.status_label = :status';
        $params['status'] = $status;
    }
    if ($vendor !== '') {
        $where .= ' AND po.vendor_name = :vendor';
        $params['vendor'] = $vendor;
    }
    if ($category !== '') {
        $where .= ' AND po.category_name = :category';
        $params['category'] = $category;
    }
    if ($search !== '') {
        $where .= ' AND (po.po_number LIKE :search_po OR po.vendor_name LIKE :search_vendor OR po.requested_by_name LIKE :search_req)';
        $params['search_po'] = '%' . $search . '%';
        $params['search_vendor'] = '%' . $search . '%';
        $params['search_req'] = '%' . $search . '%';
    }
    if ($dateFrom !== '') {
        $where .= ' AND po.order_date >= :date_from';
        $params['date_from'] = $dateFrom;
    }
    if ($dateTo !== '') {
        $where .= ' AND po.order_date <= :date_to';
        $params['date_to'] = $dateTo;
    }

    $scope = purchase_scope_sql($user, 'po', 'scope_employee_id');
    $where .= $scope['sql'];
    $params = array_merge($params, $scope['params']);

    $sql =
        'SELECT po.po_number, po.order_date, po.vendor_name, po.category_name,
                po.requested_by_name, po.delivery_date, po.status_label, po.total_amount
         FROM purchase_orders po' .
        $where .
        ' ORDER BY po.id DESC LIMIT ' . (int) $limit;

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    header('Content-Type: text/html; charset=UTF-8');

    echo '<!doctype html><html><head><meta charset="utf-8"><title>Purchase Report</title>';
    echo '<style>body{font-family:Arial,sans-serif;padding:20px;color:#222}h2{margin:0 0 10px}table{border-collapse:collapse;width:100%}th,td{border:1px solid #ddd;padding:8px;font-size:12px}th{background:#f4f6fa;text-align:left}.meta{color:#666;font-size:12px;margin:0 0 15px}</style>';
    echo '</head><body>';
    echo '<h2>Purchase Report</h2>';
    echo '<p class="meta">Generated: ' . htmlspecialchars(date('Y-m-d H:i:s')) . '</p>';
    echo '<table><thead><tr><th>PO Number</th><th>Order Date</th><th>Vendor</th><th>Category</th><th>Requested By</th><th>Delivery Date</th><th>Status</th><th>Total</th></tr></thead><tbody>';

    $grand = 0.0;
    foreach ($rows as $row) {
        $grand += (float) ($row['total_amount'] ?? 0);
        echo '<tr>';
        echo '<td>' . htmlspecialchars((string) ($row['po_number'] ?? '')) . '</td>';
        echo '<td>' . htmlspecialchars((string) ($row['order_date'] ?? '')) . '</td>';
        echo '<td>' . htmlspecialchars((string) ($row['vendor_name'] ?? '')) . '</td>';
        echo '<td>' . htmlspecialchars((string) ($row['category_name'] ?? '')) . '</td>';
        echo '<td>' . htmlspecialchars((string) ($row['requested_by_name'] ?? '')) . '</td>';
        echo '<td>' . htmlspecialchars((string) ($row['delivery_date'] ?? '')) . '</td>';
        echo '<td>' . htmlspecialchars((string) ($row['status_label'] ?? '')) . '</td>';
        echo '<td>' . number_format((float) ($row['total_amount'] ?? 0), 2) . '</td>';
        echo '</tr>';
    }

    echo '<tr><td colspan="7" style="text-align:right;font-weight:bold">Grand Total</td><td style="font-weight:bold">' . number_format($grand, 2) . '</td></tr>';
    echo '</tbody></table>';
    echo '<script>window.addEventListener("load", function(){ window.print(); });</script>';
    echo '</body></html>';
    exit;
} catch (Throwable $e) {
    send_json([
        'ok' => false,
        'message' => 'Failed to build printable purchase report',
        'error' => $e->getMessage(),
    ], 500);
}
