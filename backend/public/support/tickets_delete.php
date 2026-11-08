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

if ($ticketId <= 0) {
    send_json(['ok' => false, 'message' => 'Ticket id is required'], 422);
}

try {
    $pdo = db();

    $find = $pdo->prepare('SELECT id, created_by_employee_id, assigned_to_employee_id FROM client_portal_tickets WHERE id = :id LIMIT 1');
    $find->execute(['id' => $ticketId]);
    $ticket = $find->fetch();

    if (!$ticket) {
        send_json(['ok' => false, 'message' => 'Ticket not found'], 404);
    }

    if (is_limited_module_permission($user, 'Support & Ticketing')) {
        $employeeId = (int) ($user['id'] ?? 0);
        $createdBy = (int) ($ticket['created_by_employee_id'] ?? 0);
        $assignedTo = (int) ($ticket['assigned_to_employee_id'] ?? 0);
        if ($employeeId <= 0 || ($createdBy !== $employeeId && $assignedTo !== $employeeId)) {
            send_json(['ok' => false, 'message' => 'Forbidden: limited access allows only own/assigned tickets'], 403);
        }
    }

    $del = $pdo->prepare('DELETE FROM client_portal_tickets WHERE id = :id LIMIT 1');
    $del->execute(['id' => $ticketId]);

    send_json([
        'ok' => true,
        'message' => 'Ticket deleted successfully',
    ]);
} catch (Throwable $e) {
    send_json([
        'ok' => false,
        'message' => 'Failed to delete ticket',
        'error' => $e->getMessage(),
    ], 500);
}
