<?php

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json(['ok' => false, 'message' => 'Method not allowed'], 405);
}

$user = require_auth();
require_module_permission('Events & Holidays', 'edit', true);

$input = read_json_input();
$id = (int) ($input['id'] ?? 0);
$title = trim((string) ($input['title'] ?? $input['name'] ?? ''));
$eventType = trim((string) ($input['event_type'] ?? $input['type'] ?? 'event'));
$eventDate = trim((string) ($input['event_date'] ?? $input['date'] ?? ''));
$description = trim((string) ($input['description_text'] ?? $input['description'] ?? ''));

if ($title === '' || $eventDate === '') {
    send_json(['ok' => false, 'message' => 'title and event_date are required'], 422);
}

if (!in_array($eventType, ['event', 'holiday'], true)) {
    $eventType = 'event';
}

try {
    $pdo = db();
    ensure_events_schema($pdo);

    $employeeId = (int) ($user['id'] ?? 0);

    if ($id > 0) {
        enforce_events_scope($pdo, $user, $id);

        $stmt = $pdo->prepare(
            'UPDATE events_holidays
             SET title = :title,
                 event_type = :event_type,
                 event_date = :event_date,
                 description_text = :description_text,
                 assigned_to_employee_id = :assigned_to_employee_id
             WHERE id = :id'
        );
        $stmt->execute([
            'title' => events_str_cut($title, 180),
            'event_type' => $eventType,
            'event_date' => $eventDate,
            'description_text' => $description !== '' ? $description : null,
            'assigned_to_employee_id' => $employeeId > 0 ? $employeeId : null,
            'id' => $id,
        ]);
    } else {
        $stmt = $pdo->prepare(
            'INSERT INTO events_holidays (
                title, event_type, event_date, description_text,
                created_by_employee_id, assigned_to_employee_id
             ) VALUES (
                :title, :event_type, :event_date, :description_text,
                :created_by_employee_id, :assigned_to_employee_id
             )'
        );
        $stmt->execute([
            'title' => events_str_cut($title, 180),
            'event_type' => $eventType,
            'event_date' => $eventDate,
            'description_text' => $description !== '' ? $description : null,
            'created_by_employee_id' => $employeeId > 0 ? $employeeId : null,
            'assigned_to_employee_id' => $employeeId > 0 ? $employeeId : null,
        ]);
        $id = (int) $pdo->lastInsertId();
    }

    send_json(['ok' => true, 'id' => $id, 'message' => 'Event saved']);
} catch (Throwable $e) {
    send_json(['ok' => false, 'message' => 'Failed to save event', 'error' => $e->getMessage()], 500);
}
