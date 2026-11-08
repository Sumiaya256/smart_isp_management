<?php

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';
require_once dirname(__DIR__) . '/purchase/helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    send_json(['ok' => false, 'message' => 'Method not allowed'], 405);
}

$user = require_auth();

$type = trim((string) ($_GET['type'] ?? ''));
$status = trim((string) ($_GET['status'] ?? ''));
$search = trim((string) ($_GET['search'] ?? ''));
$dateFrom = trim((string) ($_GET['date_from'] ?? ''));
$dateTo = trim((string) ($_GET['date_to'] ?? ''));
$limit = max(20, min(5000, (int) ($_GET['limit'] ?? 200)));
$offset = max(0, (int) ($_GET['offset'] ?? 0));

try {
    $pdo = db();
    ensure_income_schema($pdo);

    $where = ' WHERE 1=1';
    $params = [];

    if ($type !== '') {
        $where .= ' AND i.income_type = :income_type';
        $params['income_type'] = $type;
    }
    if ($status !== '') {
        $where .= ' AND i.status_label = :status_label';
        $params['status_label'] = $status;
    }
    if ($search !== '') {
        $where .= ' AND (i.invoice_no LIKE :search_invoice OR i.client_name LIKE :search_client)';
        $params['search_invoice'] = '%' . $search . '%';
        $params['search_client'] = '%' . $search . '%';
    }
    if ($dateFrom !== '') {
        $where .= ' AND COALESCE(i.due_date, DATE(i.created_at)) >= :date_from';
        $params['date_from'] = $dateFrom;
    }
    if ($dateTo !== '') {
        $where .= ' AND COALESCE(i.due_date, DATE(i.created_at)) <= :date_to';
        $params['date_to'] = $dateTo;
    }

    $scope = income_scope_sql($user, 'i', 'scope_employee_id');
    $where .= $scope['sql'];
    $params = array_merge($params, $scope['params']);

    $sql = 'SELECT i.* FROM income_entries i' . $where . ' ORDER BY i.id DESC LIMIT ' . (int) $limit . ' OFFSET ' . (int) $offset;
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $items = $stmt->fetchAll();

    $countStmt = $pdo->prepare('SELECT COUNT(*) FROM income_entries i' . $where);
    $countStmt->execute($params);

    $sumStmt = $pdo->prepare('SELECT COALESCE(SUM(i.amount),0) AS total_amount, COALESCE(SUM(i.paid_amount),0) AS total_paid FROM income_entries i' . $where);
    $sumStmt->execute($params);
    $summary = $sumStmt->fetch() ?: ['total_amount' => 0, 'total_paid' => 0];

    $purchaseWhere = ' WHERE po.status_label IN ("Approved", "Received", "Partial")';
    $purchaseParams = [];

    if ($dateFrom !== '') {
        $purchaseWhere .= ' AND po.order_date >= :purchase_date_from';
        $purchaseParams['purchase_date_from'] = $dateFrom;
    }
    if ($dateTo !== '') {
        $purchaseWhere .= ' AND po.order_date <= :purchase_date_to';
        $purchaseParams['purchase_date_to'] = $dateTo;
    }

    $purchaseScope = purchase_scope_sql($user, 'po', 'purchase_scope_employee_id');
    $purchaseWhere .= $purchaseScope['sql'];
    $purchaseParams = array_merge($purchaseParams, $purchaseScope['params']);

    $purchaseSumStmt = $pdo->prepare(
        'SELECT COALESCE(SUM(po.total_amount),0) AS total_purchase_amount
         FROM purchase_orders po' . $purchaseWhere
    );
    $purchaseSumStmt->execute($purchaseParams);
    $purchaseSummary = $purchaseSumStmt->fetch() ?: ['total_purchase_amount' => 0];

    $totalAmount = (float) ($summary['total_amount'] ?? 0);
    $totalPaid = (float) ($summary['total_paid'] ?? 0);
    $totalPurchase = (float) ($purchaseSummary['total_purchase_amount'] ?? 0);

    send_json([
        'ok' => true,
        'items' => $items,
        'total_count' => (int) $countStmt->fetchColumn(),
        'summary' => [
            'total_amount' => $totalAmount,
            'total_paid' => $totalPaid,
            'total_due' => $totalAmount - $totalPaid,
        ],
        'financials' => [
            'purchase_total' => $totalPurchase,
            'profit_total' => $totalAmount - $totalPurchase,
        ],
    ]);
} catch (Throwable $e) {
    send_json(['ok' => false, 'message' => 'Failed to load income entries', 'error' => $e->getMessage()], 500);
}
