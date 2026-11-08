<?php

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    send_json(['ok' => false, 'message' => 'Method not allowed'], 405);
}

$user = require_auth();

$type = trim((string) ($_GET['type'] ?? ''));
$status = trim((string) ($_GET['status'] ?? ''));
$search = trim((string) ($_GET['search'] ?? ''));
$limit = max(20, min(500, (int) ($_GET['limit'] ?? 200)));
$offset = max(0, (int) ($_GET['offset'] ?? 0));

try {
    $pdo = db();
    ensure_assets_schema($pdo);

    $where = ' WHERE 1=1';
    $params = [];

    if ($type !== '') {
        $where .= ' AND a.type_name = :type_name';
        $params['type_name'] = $type;
    }
    if ($status !== '') {
        $where .= ' AND a.status_label = :status_label';
        $params['status_label'] = $status;
    }
    if ($search !== '') {
        $where .= ' AND (a.asset_tag LIKE :search_tag OR a.asset_name LIKE :search_name OR a.assigned_to_name LIKE :search_assigned)';
        $params['search_tag'] = '%' . $search . '%';
        $params['search_name'] = '%' . $search . '%';
        $params['search_assigned'] = '%' . $search . '%';
    }

    $scope = assets_scope_sql($user, 'a', 'scope_employee_id');
    $where .= $scope['sql'];
    $params = array_merge($params, $scope['params']);

    $sql = 'SELECT a.* FROM assets_items a' . $where . ' ORDER BY a.id DESC LIMIT ' . (int) $limit . ' OFFSET ' . (int) $offset;
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $items = $stmt->fetchAll();

    $countStmt = $pdo->prepare('SELECT COUNT(*) FROM assets_items a' . $where);
    $countStmt->execute($params);

    send_json([
        'ok' => true,
        'items' => $items,
        'total_count' => (int) $countStmt->fetchColumn(),
    ]);
} catch (Throwable $e) {
    send_json(['ok' => false, 'message' => 'Failed to load assets', 'error' => $e->getMessage()], 500);
}
