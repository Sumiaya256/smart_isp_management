<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/database.php';
require_once dirname(__DIR__, 2) . '/config/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    send_json(['ok' => false, 'message' => 'Method not allowed'], 405);
}

$user = require_auth();
$roleName = strtolower(trim((string) ($user['role_name'] ?? '')));
if (!is_access_matrix_disabled() && $roleName !== 'client') {
    send_json(['ok' => false, 'message' => 'Forbidden: client access only'], 403);
}

try {
    $pdo = db();
    ensure_client_portal_tickets_table($pdo);
    ensure_attachment_columns($pdo);

    $stmt = $pdo->prepare(
        'SELECT id, ticket_no, category, description, priority, status, admin_remarks,
                attachment_name, attachment_path, attachment_mime,
                created_at, updated_at
         FROM client_portal_tickets
         WHERE client_id = :client_id
         ORDER BY id DESC
         LIMIT 100'
    );
    $stmt->execute(['client_id' => (int) $user['id']]);
    $tickets = $stmt->fetchAll();

    send_json([
        'ok' => true,
        'tickets' => $tickets,
    ]);
} catch (Throwable $e) {
    send_json([
        'ok' => false,
        'message' => 'Failed to load support tickets',
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
