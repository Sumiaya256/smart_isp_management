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
                po.requested_by_name, po.delivery_date, po.status_label, po.total_amount, po.notes
         FROM purchase_orders po' .
        $where .
        ' ORDER BY po.id DESC LIMIT ' . (int) $limit;

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    $filename = 'purchase_report_' . date('Ymd_His') . '.csv';
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $out = fopen('php://output', 'wb');
    if ($out === false) {
        throw new RuntimeException('Unable to open output stream');
    }

    fputcsv($out, ['PO Number', 'Order Date', 'Vendor', 'Category', 'Requested By', 'Delivery Date', 'Status', 'Total Amount', 'Notes']);
    foreach ($rows as $row) {
        fputcsv($out, [
            $row['po_number'] ?? '',
            $row['order_date'] ?? '',
            $row['vendor_name'] ?? '',
            $row['category_name'] ?? '',
            $row['requested_by_name'] ?? '',
            $row['delivery_date'] ?? '',
            $row['status_label'] ?? '',
            $row['total_amount'] ?? 0,
            $row['notes'] ?? '',
        ]);
    }
    fclose($out);
    exit;
} catch (Throwable $e) {
    send_json([
        'ok' => false,
        'message' => 'Failed to export purchase report',
        'error' => $e->getMessage(),
    ], 500);
}
