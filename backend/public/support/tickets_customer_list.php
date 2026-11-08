<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/database.php';
require_once dirname(__DIR__, 2) . '/config/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    send_json([
        'ok' => false,
        'message' => 'Method not allowed',
    ], 405);
}

$user = require_auth();

$status = strtolower(trim((string) ($_GET['status'] ?? 'all')));
$priority = strtolower(trim((string) ($_GET['priority'] ?? 'all')));
$search = trim((string) ($_GET['search'] ?? ''));
$limit = isset($_GET['limit']) ? (int) $_GET['limit'] : 200;
$limit = max(20, min(500, $limit));

$allowedStatus = ['pending', 'in_progress', 'resolved', 'closed'];
$allowedPriority = ['low', 'medium', 'high', 'critical'];

try {
    $pdo = db();
    ensure_client_portal_tickets_table($pdo);
    ensure_attachment_columns($pdo);
    ensure_assignment_columns($pdo);

    $whereParts = ['1=1'];
    $params = [];

    if (in_array($status, $allowedStatus, true)) {
        $whereParts[] = 't.status = :status';
        $params['status'] = $status;
    }

    if (in_array($priority, $allowedPriority, true)) {
        $whereParts[] = 't.priority = :priority';
        $params['priority'] = $priority;
    }

    if ($search !== '') {
        $whereParts[] = '(
            t.ticket_no LIKE :search_ticket
            OR COALESCE(c.full_name, "") LIKE :search_name
            OR COALESCE(c.client_code, "") LIKE :search_code
            OR COALESCE(c.phone, "") LIKE :search_phone
        )';
        $wild = '%' . $search . '%';
        $params['search_ticket'] = $wild;
        $params['search_name'] = $wild;
        $params['search_code'] = $wild;
        $params['search_phone'] = $wild;
    }

    if (is_limited_module_permission($user, 'Support & Ticketing')) {
        $whereParts[] = '(t.assigned_to_employee_id = :employee_id OR t.created_by_employee_id = :employee_id)';
        $params['employee_id'] = (int) ($user['id'] ?? 0);
    }

    $whereClause = implode(' AND ', $whereParts);

    $sql =
        'SELECT t.id, t.ticket_no, t.client_id, t.category, t.description, t.priority, t.status, t.admin_remarks,
            t.attachment_name, t.attachment_path, t.attachment_mime,
            t.created_by_employee_id, t.assigned_to_employee_id,
                t.created_at, t.updated_at,
                COALESCE(c.client_code, "N/A") AS client_code,
                COALESCE(c.full_name, "Unknown Customer") AS client_name,
                COALESCE(c.phone, "N/A") AS client_phone
         FROM client_portal_tickets t
         LEFT JOIN clients c ON c.id = t.client_id
         WHERE ' . $whereClause . '
         ORDER BY t.id DESC
         LIMIT ' . (int) $limit;

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $tickets = $stmt->fetchAll();

    $statsWhere = '1=1';
    if (is_limited_module_permission($user, 'Support & Ticketing')) {
        $statsWhere = '(assigned_to_employee_id = :employee_id OR created_by_employee_id = :employee_id)';
    }

    $statsSql =
        'SELECT
            COUNT(*) AS total,
            SUM(CASE WHEN status = "pending" THEN 1 ELSE 0 END) AS pending_count,
            SUM(CASE WHEN status = "in_progress" THEN 1 ELSE 0 END) AS in_progress_count,
            SUM(CASE WHEN status IN ("resolved", "closed") THEN 1 ELSE 0 END) AS resolved_count,
            SUM(CASE WHEN priority IN ("high", "critical") THEN 1 ELSE 0 END) AS urgent_count
         FROM client_portal_tickets
         WHERE ' . $statsWhere;
    $statsStmt = $pdo->prepare($statsSql);
    if (is_limited_module_permission($user, 'Support & Ticketing')) {
        $statsStmt->execute(['employee_id' => (int) ($user['id'] ?? 0)]);
    } else {
        $statsStmt->execute();
    }
    $stats = $statsStmt->fetch() ?: [];

    send_json([
        'ok' => true,
        'filters' => [
            'status' => $status,
            'priority' => $priority,
            'search' => $search,
            'limit' => $limit,
        ],
        'stats' => [
            'total' => (int) ($stats['total'] ?? 0),
            'pending_count' => (int) ($stats['pending_count'] ?? 0),
            'in_progress_count' => (int) ($stats['in_progress_count'] ?? 0),
            'resolved_count' => (int) ($stats['resolved_count'] ?? 0),
            'urgent_count' => (int) ($stats['urgent_count'] ?? 0),
        ],
        'tickets' => $tickets,
    ]);
} catch (Throwable $e) {
    send_json([
        'ok' => false,
        'message' => 'Failed to load customer tickets',
        'error' => $e->getMessage(),
    ], 500);
}

function ensure_client_portal_tickets_table(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS client_portal_tickets (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            ticket_no VARCHAR(40) NOT NULL UNIQUE,
            client_id BIGINT UNSIGNED NOT NULL,
            category VARCHAR(120) NOT NULL,
            description TEXT NOT NULL,
            priority ENUM("low", "medium", "high", "critical") NOT NULL DEFAULT "medium",
            status ENUM("pending", "in_progress", "resolved", "closed") NOT NULL DEFAULT "pending",
            admin_remarks VARCHAR(255) NULL,
            attachment_name VARCHAR(255) NULL,
            attachment_path VARCHAR(255) NULL,
            attachment_mime VARCHAR(120) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_client_tickets_client_id (client_id),
            INDEX idx_client_tickets_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
}

function ensure_attachment_columns(PDO $pdo): void
{
    if (!column_exists($pdo, 'client_portal_tickets', 'attachment_name')) {
        $pdo->exec('ALTER TABLE client_portal_tickets ADD COLUMN attachment_name VARCHAR(255) NULL AFTER admin_remarks');
    }
    if (!column_exists($pdo, 'client_portal_tickets', 'attachment_path')) {
        $pdo->exec('ALTER TABLE client_portal_tickets ADD COLUMN attachment_path VARCHAR(255) NULL AFTER attachment_name');
    }
    if (!column_exists($pdo, 'client_portal_tickets', 'attachment_mime')) {
        $pdo->exec('ALTER TABLE client_portal_tickets ADD COLUMN attachment_mime VARCHAR(120) NULL AFTER attachment_path');
    }
}

function ensure_assignment_columns(PDO $pdo): void
{
    if (!column_exists($pdo, 'client_portal_tickets', 'created_by_employee_id')) {
        $pdo->exec('ALTER TABLE client_portal_tickets ADD COLUMN created_by_employee_id BIGINT UNSIGNED NULL AFTER client_id');
    }
    if (!column_exists($pdo, 'client_portal_tickets', 'assigned_to_employee_id')) {
        $pdo->exec('ALTER TABLE client_portal_tickets ADD COLUMN assigned_to_employee_id BIGINT UNSIGNED NULL AFTER created_by_employee_id');
    }
}

function column_exists(PDO $pdo, string $tableName, string $columnName): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*)
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name AND COLUMN_NAME = :column_name'
    );
    $stmt->execute([
        'table_name' => $tableName,
        'column_name' => $columnName,
    ]);
    return (int) $stmt->fetchColumn() > 0;
}
