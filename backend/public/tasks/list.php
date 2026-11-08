<?php

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    send_json(['ok' => false, 'message' => 'Method not allowed'], 405);
}

$user = require_auth();
require_module_permission('Task Management', 'view');

$status = trim((string) ($_GET['status'] ?? ''));
$priority = trim((string) ($_GET['priority'] ?? ''));
$category = trim((string) ($_GET['category'] ?? ''));
$assignee = trim((string) ($_GET['assignee'] ?? ''));
$search = trim((string) ($_GET['search'] ?? ''));
$limit = max(20, min(500, (int) ($_GET['limit'] ?? 250)));
$offset = max(0, (int) ($_GET['offset'] ?? 0));

try {
    $pdo = db();
    ensure_tasks_schema($pdo);

    $where = ' WHERE 1=1';
    $params = [];

    if ($status !== '' && $status !== 'all') {
        $where .= ' AND t.status_label = :status_label';
        $params['status_label'] = $status;
    }
    if ($priority !== '') {
        $where .= ' AND t.priority_label = :priority_label';
        $params['priority_label'] = $priority;
    }
    if ($category !== '') {
        $where .= ' AND t.category_name = :category_name';
        $params['category_name'] = $category;
    }
    if ($assignee !== '') {
        $where .= ' AND t.assignee_name = :assignee_name';
        $params['assignee_name'] = $assignee;
    }
    if ($search !== '') {
        $where .= ' AND (t.task_code LIKE :search OR t.title LIKE :search OR t.reference_code LIKE :search OR t.assignee_name LIKE :search)';
        $params['search'] = '%' . $search . '%';
    }

    $scope = tasks_scope_sql($user, 't', 'scope_employee_id');
    $where .= $scope['sql'];
    $params = array_merge($params, $scope['params']);

    $sql = 'SELECT t.* FROM task_items t' . $where . ' ORDER BY t.due_date ASC, t.id DESC LIMIT ' . (int) $limit . ' OFFSET ' . (int) $offset;
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $items = $stmt->fetchAll();

    $countStmt = $pdo->prepare('SELECT COUNT(*) FROM task_items t' . $where);
    $countStmt->execute($params);

    send_json([
        'ok' => true,
        'items' => $items,
        'total_count' => (int) $countStmt->fetchColumn(),
    ]);
} catch (Throwable $e) {
    send_json(['ok' => false, 'message' => 'Failed to load tasks', 'error' => $e->getMessage()], 500);
}
