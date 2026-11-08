<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/database.php';
require_once dirname(__DIR__, 2) . '/config/auth.php';
require_once __DIR__ . '/scope_helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'DELETE') {
    send_json(['ok' => false, 'message' => 'Method not allowed'], 405);
}

$user = require_page_permission('Client List', 'edit');

$input = json_decode(file_get_contents('php://input'), true) ?? [];
$clientId = (int) ($input['id'] ?? 0);

if ($clientId <= 0) {
    send_json(['ok' => false, 'message' => 'Invalid client ID'], 400);
}

try {
    $pdo = db();
    ensure_client_scope_columns($pdo);
    enforce_client_scope_for_client_id($pdo, $user, $clientId);

    $pdo->beginTransaction();

    // Delete client-related records in order of dependency
    $tables = [
        'payments' => 'invoice_id',
        'invoices' => 'client_id',
        'support_tickets' => 'client_id',
    ];

    foreach ($tables as $table => $column) {
        if ($column === 'invoice_id') {
            // Special case for payments: join through invoices
            $pdo->exec("DELETE p FROM $table p INNER JOIN invoices i ON i.id = p.$column INNER JOIN clients c ON c.id = i.client_id WHERE c.id = $clientId");
        } else {
            // Direct deletion
            $pdo->exec("DELETE FROM $table WHERE $column = $clientId");
        }
    }

    // Finally delete the client
    $stmt = $pdo->prepare('DELETE FROM clients WHERE id = :id');
    $stmt->execute(['id' => $clientId]);

    $pdo->commit();

    send_json([
        'ok' => true,
        'message' => 'Client deleted successfully',
    ]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    send_json(['ok' => false, 'message' => 'Failed to delete client', 'error' => $e->getMessage()], 500);
}
