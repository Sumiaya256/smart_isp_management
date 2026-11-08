<?php

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json(['ok' => false, 'message' => 'Method not allowed'], 405);
}

$user = require_auth();
require_module_permission('Task Management', 'edit', true);

$input = read_json_input();
$id = (int) ($input['id'] ?? 0);
$title = trim((string) ($input['title'] ?? ''));
$category = trim((string) ($input['category_name'] ?? $input['category'] ?? ''));
$assignee = trim((string) ($input['assignee_name'] ?? $input['assignee'] ?? ''));
$priority = trim((string) ($input['priority_label'] ?? $input['priority'] ?? ''));
$status = trim((string) ($input['status_label'] ?? $input['status'] ?? 'Pending'));
$progress = (int) ($input['progress_percent'] ?? $input['progress'] ?? 0);
$dueDate = trim((string) ($input['due_date'] ?? ''));
$reference = trim((string) ($input['reference_code'] ?? $input['reference'] ?? ''));
$description = trim((string) ($input['description_text'] ?? $input['description'] ?? ''));

if ($title === '' || $category === '' || $assignee === '' || $priority === '' || $dueDate === '') {
    send_json(['ok' => false, 'message' => 'title, category, assignee, priority and due_date are required'], 422);
}

$allowedPriorities = ['Critical', 'High', 'Medium', 'Low'];
if (!in_array($priority, $allowedPriorities, true)) {
    $priority = 'Medium';
}

$allowedStatuses = ['Pending', 'In Progress', 'On Hold', 'Completed', 'Overdue'];
if (!in_array($status, $allowedStatuses, true)) {
    $status = 'Pending';
}

$progress = max(0, min(100, $progress));
if ($status === 'Completed' && $progress < 100) {
    $progress = 100;
}

try {
    $pdo = db();
    ensure_tasks_schema($pdo);

    $employeeId = (int) ($user['id'] ?? 0);
    $byName = trim((string) ($user['full_name'] ?? 'System'));

    if ($id > 0) {
        enforce_task_scope($pdo, $user, $id);

        $stmt = $pdo->prepare(
            'UPDATE task_items
             SET title = :title,
                 category_name = :category_name,
                 assignee_name = :assignee_name,
                 priority_label = :priority_label,
                 status_label = :status_label,
                 progress_percent = :progress_percent,
                 due_date = :due_date,
                 reference_code = :reference_code,
                 description_text = :description_text,
                 assigned_to_employee_id = :assigned_to_employee_id
             WHERE id = :id'
        );

        $stmt->execute([
            'title' => tasks_str_cut($title, 220),
            'category_name' => tasks_str_cut($category, 80),
            'assignee_name' => tasks_str_cut($assignee, 120),
            'priority_label' => $priority,
            'status_label' => $status,
            'progress_percent' => $progress,
            'due_date' => $dueDate,
            'reference_code' => $reference !== '' ? tasks_str_cut($reference, 80) : null,
            'description_text' => $description !== '' ? $description : null,
            'assigned_to_employee_id' => $employeeId > 0 ? $employeeId : null,
            'id' => $id,
        ]);
    } else {
        $taskCode = next_task_code($pdo);

        $stmt = $pdo->prepare(
            'INSERT INTO task_items (
                task_code, title, category_name, assignee_name,
                priority_label, status_label, progress_percent, due_date,
                reference_code, description_text, created_by_name,
                created_by_employee_id, assigned_to_employee_id
             ) VALUES (
                :task_code, :title, :category_name, :assignee_name,
                :priority_label, :status_label, :progress_percent, :due_date,
                :reference_code, :description_text, :created_by_name,
                :created_by_employee_id, :assigned_to_employee_id
             )'
        );

        $stmt->execute([
            'task_code' => $taskCode,
            'title' => tasks_str_cut($title, 220),
            'category_name' => tasks_str_cut($category, 80),
            'assignee_name' => tasks_str_cut($assignee, 120),
            'priority_label' => $priority,
            'status_label' => $status,
            'progress_percent' => $progress,
            'due_date' => $dueDate,
            'reference_code' => $reference !== '' ? tasks_str_cut($reference, 80) : null,
            'description_text' => $description !== '' ? $description : null,
            'created_by_name' => tasks_str_cut($byName, 120),
            'created_by_employee_id' => $employeeId > 0 ? $employeeId : null,
            'assigned_to_employee_id' => $employeeId > 0 ? $employeeId : null,
        ]);

        $id = (int) $pdo->lastInsertId();
    }

    send_json(['ok' => true, 'id' => $id, 'message' => 'Task saved']);
} catch (Throwable $e) {
    send_json(['ok' => false, 'message' => 'Failed to save task', 'error' => $e->getMessage()], 500);
}
