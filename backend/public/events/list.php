<?php

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    send_json(['ok' => false, 'message' => 'Method not allowed'], 405);
}

$user = require_auth();

$type = trim((string) ($_GET['type'] ?? ''));
$search = trim((string) ($_GET['search'] ?? ''));
$dateFrom = trim((string) ($_GET['date_from'] ?? ''));
$dateTo = trim((string) ($_GET['date_to'] ?? ''));
$limit = max(20, min(500, (int) ($_GET['limit'] ?? 250)));
$offset = max(0, (int) ($_GET['offset'] ?? 0));

try {
    $pdo = db();
    ensure_events_schema($pdo);

    $where = ' WHERE 1=1';
    $params = [];

    if ($type !== '' && $type !== 'all') {
        $where .= ' AND e.event_type = :event_type';
        $params['event_type'] = $type;
    }
    if ($search !== '') {
        $where .= ' AND e.title LIKE :title';
        $params['title'] = '%' . $search . '%';
    }
    if ($dateFrom !== '') {
        $where .= ' AND e.event_date >= :date_from';
        $params['date_from'] = $dateFrom;
    }
    if ($dateTo !== '') {
        $where .= ' AND e.event_date <= :date_to';
        $params['date_to'] = $dateTo;
    }

    $scope = events_scope_sql($user, 'e', 'scope_employee_id');
    $where .= $scope['sql'];
    $params = array_merge($params, $scope['params']);

    $sql = 'SELECT e.* FROM events_holidays e' . $where . ' ORDER BY e.event_date DESC, e.id DESC LIMIT ' . (int) $limit . ' OFFSET ' . (int) $offset;
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $items = $stmt->fetchAll();

    $countStmt = $pdo->prepare('SELECT COUNT(*) FROM events_holidays e' . $where);
    $countStmt->execute($params);

    send_json([
        'ok' => true,
        'items' => $items,
        'total_count' => (int) $countStmt->fetchColumn(),
    ]);
} catch (Throwable $e) {
    send_json(['ok' => false, 'message' => 'Failed to load events and holidays', 'error' => $e->getMessage()], 500);
}
