<?php

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    send_json(['ok' => false, 'message' => 'Method not allowed'], 405);
}

$user = require_auth();

$status = trim((string) ($_GET['status'] ?? ''));
$type = trim((string) ($_GET['type'] ?? ''));
$search = trim((string) ($_GET['search'] ?? ''));
$dateFrom = trim((string) ($_GET['date_from'] ?? ''));
$limit = max(20, min(500, (int) ($_GET['limit'] ?? 250)));
$offset = max(0, (int) ($_GET['offset'] ?? 0));

try {
    $pdo = db();
    ensure_leave_schema($pdo);

    $where = ' WHERE 1=1';
    $params = [];

    if ($status !== '' && $status !== 'all') {
        $where .= ' AND l.status_label = :status_label';
        $params['status_label'] = $status;
    }
    if ($type !== '' && $type !== 'all') {
        $normalizedType = strtolower(trim(str_replace(' ', '_', $type)));
        $where .= ' AND LOWER(REPLACE(TRIM(COALESCE(l.leave_type, "")), " ", "_")) = :leave_type';
        $params['leave_type'] = $normalizedType;
    }
    if ($search !== '') {
        $where .= ' AND (l.employee_name LIKE :search_name OR l.employee_code LIKE :search_code)';
        $params['search_name'] = '%' . $search . '%';
        $params['search_code'] = '%' . $search . '%';
    }
    if ($dateFrom !== '') {
        $where .= ' AND l.start_date >= :date_from';
        $params['date_from'] = $dateFrom;
    }

    $scope = leave_scope_sql($user, 'l', 'scope_employee_id');
    $where .= $scope['sql'];
    $params = array_merge($params, $scope['params']);

    $sql = 'SELECT l.* FROM leave_requests l' . $where . ' ORDER BY l.id DESC LIMIT ' . (int) $limit . ' OFFSET ' . (int) $offset;
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $items = $stmt->fetchAll();

    $idList = array_values(array_filter(array_map(static fn ($item) => (int) ($item['id'] ?? 0), $items), static fn ($v) => $v > 0));
    $attachmentsByRequest = [];
    if (!empty($idList)) {
        $placeholders = implode(',', array_fill(0, count($idList), '?'));
        $attStmt = $pdo->prepare(
            'SELECT id, leave_request_id, original_name, file_path, mime_type, file_size, created_at
             FROM leave_attachments
             WHERE leave_request_id IN (' . $placeholders . ')
             ORDER BY id ASC'
        );
        $attStmt->execute($idList);
        foreach ($attStmt->fetchAll() as $att) {
            $reqId = (int) ($att['leave_request_id'] ?? 0);
            if ($reqId <= 0) {
                continue;
            }
            if (!isset($attachmentsByRequest[$reqId])) {
                $attachmentsByRequest[$reqId] = [];
            }
            $attachmentsByRequest[$reqId][] = [
                'id' => (int) ($att['id'] ?? 0),
                'name' => (string) ($att['original_name'] ?? ''),
                'path' => (string) ($att['file_path'] ?? ''),
                'mime_type' => (string) ($att['mime_type'] ?? ''),
                'size' => (int) ($att['file_size'] ?? 0),
                'created_at' => (string) ($att['created_at'] ?? ''),
            ];
        }
    }

    foreach ($items as &$item) {
        $rid = (int) ($item['id'] ?? 0);
        $item['attachments'] = $attachmentsByRequest[$rid] ?? [];
    }
    unset($item);

    $countStmt = $pdo->prepare('SELECT COUNT(*) FROM leave_requests l' . $where);
    $countStmt->execute($params);

    send_json([
        'ok' => true,
        'items' => $items,
        'total_count' => (int) $countStmt->fetchColumn(),
    ]);
} catch (Throwable $e) {
    send_json(['ok' => false, 'message' => 'Failed to load leave requests', 'error' => $e->getMessage()], 500);
}
