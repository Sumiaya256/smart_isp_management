<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/database.php';
require_once dirname(__DIR__, 2) . '/config/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json(['ok' => false, 'message' => 'Method not allowed'], 405);
}

$user = require_auth();
$roleName = strtolower(trim((string) ($user['role_name'] ?? '')));
if (!is_access_matrix_disabled() && $roleName !== 'client') {
    send_json(['ok' => false, 'message' => 'Forbidden: client access only'], 403);
}

$input = $_POST;
if (!is_array($input) || empty($input)) {
    $input = read_json_input();
}
$category = trim((string) ($input['category'] ?? ''));
$description = trim((string) ($input['description'] ?? ''));
$priority = strtolower(trim((string) ($input['priority'] ?? 'medium')));

if ($category === '' || $description === '') {
    send_json(['ok' => false, 'message' => 'Category and description are required'], 422);
}

if (strlen($description) < 8) {
    send_json(['ok' => false, 'message' => 'Description should be at least 8 characters'], 422);
}

$allowedPriorities = ['low', 'medium', 'high', 'critical'];
if (!in_array($priority, $allowedPriorities, true)) {
    $priority = 'medium';
}

try {
    $pdo = db();
    ensure_client_portal_tickets_table($pdo);
    ensure_attachment_columns($pdo);

    $ticketNo = generate_ticket_no($pdo);

    $attachment = store_ticket_attachment($_FILES['attachment'] ?? null);

    $stmt = $pdo->prepare(
        'INSERT INTO client_portal_tickets (
            ticket_no, client_id, category, description, priority, status, admin_remarks,
            attachment_name, attachment_path, attachment_mime
         )
         VALUES (
            :ticket_no, :client_id, :category, :description, :priority, :status, :admin_remarks,
            :attachment_name, :attachment_path, :attachment_mime
         )'
    );

    $stmt->execute([
        'ticket_no' => $ticketNo,
        'client_id' => (int) $user['id'],
        'category' => mb_substr($category, 0, 120),
        'description' => mb_substr($description, 0, 5000),
        'priority' => $priority,
        'status' => 'pending',
        'admin_remarks' => 'Awaiting assignment',
        'attachment_name' => $attachment['name'],
        'attachment_path' => $attachment['path'],
        'attachment_mime' => $attachment['mime'],
    ]);

    $id = (int) $pdo->lastInsertId();

    $fetch = $pdo->prepare(
        'SELECT id, ticket_no, category, description, priority, status, admin_remarks,
            attachment_name, attachment_path, attachment_mime,
            created_at, updated_at
         FROM client_portal_tickets
         WHERE id = :id
         LIMIT 1'
    );
    $fetch->execute(['id' => $id]);
    $ticket = $fetch->fetch();

    send_json([
        'ok' => true,
        'message' => 'Support ticket created successfully',
        'ticket' => $ticket,
    ]);
} catch (Throwable $e) {
    send_json([
        'ok' => false,
        'message' => 'Failed to create support ticket',
        'error' => $e->getMessage(),
    ], 500);
}

function generate_ticket_no(PDO $pdo): string
{
    $datePart = date('Ymd');

    for ($i = 0; $i < 5; $i += 1) {
        $suffix = (string) random_int(1000, 9999);
        $ticketNo = 'TK-' . $datePart . '-' . $suffix;

        $stmt = $pdo->prepare('SELECT COUNT(*) FROM client_portal_tickets WHERE ticket_no = :ticket_no');
        $stmt->execute(['ticket_no' => $ticketNo]);
        if ((int) $stmt->fetchColumn() === 0) {
            return $ticketNo;
        }
    }

    return 'TK-' . $datePart . '-' . (string) time();
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

function store_ticket_attachment($file): array
{
    $empty = ['name' => null, 'path' => null, 'mime' => null];
    if (!is_array($file) || !isset($file['error'])) {
        return $empty;
    }

    $error = (int) $file['error'];
    if ($error === UPLOAD_ERR_NO_FILE) {
        return $empty;
    }
    if ($error !== UPLOAD_ERR_OK) {
        send_json(['ok' => false, 'message' => 'Attachment upload failed'], 422);
    }

    $tmpPath = (string) ($file['tmp_name'] ?? '');
    $originalName = trim((string) ($file['name'] ?? 'attachment'));
    $size = (int) ($file['size'] ?? 0);

    if ($size <= 0 || $size > 5 * 1024 * 1024) {
        send_json(['ok' => false, 'message' => 'Attachment must be up to 5MB'], 422);
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = strtolower((string) $finfo->file($tmpPath));
    $allowed = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
        'application/pdf' => 'pdf',
    ];

    if (!isset($allowed[$mime])) {
        send_json(['ok' => false, 'message' => 'Only image or PDF attachment is allowed'], 422);
    }

    $ext = $allowed[$mime];
    $safeBase = preg_replace('/[^A-Za-z0-9_-]/', '_', pathinfo($originalName, PATHINFO_FILENAME));
    $safeBase = trim((string) $safeBase, '_');
    if ($safeBase === '') {
        $safeBase = 'file';
    }

    $storedName = 'ticket_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $rootDir = dirname(__DIR__, 3);
    $uploadDir = $rootDir . '/backend/public/uploads/tickets';
    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0777, true) && !is_dir($uploadDir)) {
        send_json(['ok' => false, 'message' => 'Unable to prepare upload folder'], 500);
    }

    $destination = $uploadDir . '/' . $storedName;
    if (!move_uploaded_file($tmpPath, $destination)) {
        send_json(['ok' => false, 'message' => 'Failed to save attachment'], 500);
    }

    return [
        'name' => mb_substr($originalName, 0, 255),
        'path' => 'backend/public/uploads/tickets/' . $storedName,
        'mime' => $mime,
    ];
}
