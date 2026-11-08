<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/database.php';
require_once dirname(__DIR__, 2) . '/config/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json([
        'ok' => false,
        'message' => 'Method not allowed',
    ], 405);
}

$user = require_auth();
require_module_permission('Support & Ticketing', 'edit', true);
$input = read_json_input();

$ticketId = (int) ($input['ticket_id'] ?? 0);
$action = strtolower(trim((string) ($input['action'] ?? '')));
$remarks = trim((string) ($input['admin_remarks'] ?? ''));
$statusOverride = strtolower(trim((string) ($input['status'] ?? '')));

if ($ticketId <= 0) {
    send_json(['ok' => false, 'message' => 'Ticket id is required'], 422);
}

$allowedActions = ['assign', 'in_progress', 'resolve', 'close', 'remark'];
if ($action === '' || !in_array($action, $allowedActions, true)) {
    send_json(['ok' => false, 'message' => 'Invalid action'], 422);
}

$allowedStatuses = ['pending', 'in_progress', 'resolved', 'closed'];

try {
    $pdo = db();
    ensure_client_portal_tickets_table($pdo);
    ensure_attachment_columns($pdo);
    ensure_assignment_columns($pdo);

    $employeeId = (int) ($user['id'] ?? 0);

    $getStmt = $pdo->prepare(
        'SELECT id, ticket_no, client_id, category, description, priority, status, admin_remarks,
            created_by_employee_id, assigned_to_employee_id,
                attachment_name, attachment_path, attachment_mime,
                created_at, updated_at
         FROM client_portal_tickets
         WHERE id = :id
         LIMIT 1'
    );
    $getStmt->execute(['id' => $ticketId]);
    $ticket = $getStmt->fetch();

    if (!$ticket) {
        send_json(['ok' => false, 'message' => 'Ticket not found'], 404);
    }

    if (is_limited_module_permission($user, 'Support & Ticketing')) {
        $createdBy = (int) ($ticket['created_by_employee_id'] ?? 0);
        $assignedTo = (int) ($ticket['assigned_to_employee_id'] ?? 0);
        if ($employeeId <= 0 || ($createdBy !== $employeeId && $assignedTo !== $employeeId)) {
            send_json(['ok' => false, 'message' => 'Forbidden: limited access allows only own/assigned tickets'], 403);
        }
    }

    $currentStatus = strtolower((string) ($ticket['status'] ?? 'pending'));
    $nextStatus = $currentStatus;

    if ($statusOverride !== '' && in_array($statusOverride, $allowedStatuses, true)) {
        $nextStatus = $statusOverride;
    } else {
        if ($action === 'assign' || $action === 'in_progress') {
            $nextStatus = 'in_progress';
        } elseif ($action === 'resolve') {
            $nextStatus = 'resolved';
        } elseif ($action === 'close') {
            $nextStatus = 'closed';
        }
    }

    $actorName = trim((string) ($user['full_name'] ?? 'Support Agent'));
    $finalRemarks = $remarks;

    if ($action === 'assign' && $finalRemarks === '') {
        $finalRemarks = 'Assigned to support team by ' . $actorName;
    }

    if ($action === 'remark' && $finalRemarks === '') {
        send_json(['ok' => false, 'message' => 'Please enter a remark'], 422);
    }

    $update = $pdo->prepare(
        'UPDATE client_portal_tickets
         SET status = :status,
             admin_remarks = :admin_remarks,
             assigned_to_employee_id = :assigned_to_employee_id
         WHERE id = :id'
    );

    $assignedToEmployeeId = (int) ($ticket['assigned_to_employee_id'] ?? 0);
    if ($action === 'assign' || $action === 'in_progress') {
        $assignedToEmployeeId = $employeeId;
    }

    $update->execute([
        'status' => $nextStatus,
        'admin_remarks' => $finalRemarks !== '' ? mb_substr($finalRemarks, 0, 255) : (string) ($ticket['admin_remarks'] ?? ''),
        'assigned_to_employee_id' => $assignedToEmployeeId > 0 ? $assignedToEmployeeId : null,
        'id' => $ticketId,
    ]);

    $fetch = $pdo->prepare(
        'SELECT id, ticket_no, client_id, category, description, priority, status, admin_remarks,
            created_by_employee_id, assigned_to_employee_id,
            attachment_name, attachment_path, attachment_mime,
            created_at, updated_at
         FROM client_portal_tickets
         WHERE id = :id
         LIMIT 1'
    );
    $fetch->execute(['id' => $ticketId]);
    $updatedTicket = $fetch->fetch();

    send_json([
        'ok' => true,
        'message' => 'Ticket updated successfully',
        'ticket' => $updatedTicket,
    ]);
} catch (Throwable $e) {
    send_json([
        'ok' => false,
        'message' => 'Failed to update ticket',
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
