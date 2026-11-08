<?php

declare(strict_types=1);

require_once __DIR__ . '/../backend/config/database.php';
require_once __DIR__ . '/../backend/public/tasks/helpers.php';

try {
    $pdo = db();
    ensure_tasks_schema($pdo);

    $rows = [
        ['task_code' => 'TSK-001', 'title' => 'Router firmware upgrade - Zone A', 'category_name' => 'Network', 'assignee_name' => 'Rakib Hasan', 'priority_label' => 'Critical', 'status_label' => 'In Progress', 'progress_percent' => 60, 'due_date' => '2026-04-22', 'reference_code' => '', 'description_text' => 'Upgrade MikroTik routers in Zone A to latest stable firmware and verify rollback plan.', 'created_by_name' => 'Admin'],
        ['task_code' => 'TSK-002', 'title' => 'New fiber installation - Client C-4512', 'category_name' => 'Field Work', 'assignee_name' => 'Jahidul Islam', 'priority_label' => 'High', 'status_label' => 'Pending', 'progress_percent' => 0, 'due_date' => '2026-04-25', 'reference_code' => 'C-4512', 'description_text' => 'Install drop cable and ONU at client premises and run final optical test.', 'created_by_name' => 'Admin'],
        ['task_code' => 'TSK-003', 'title' => 'Overdue invoice follow-up - April batch', 'category_name' => 'Billing', 'assignee_name' => 'Sumaiya Akter', 'priority_label' => 'High', 'status_label' => 'Pending', 'progress_percent' => 10, 'due_date' => '2026-04-20', 'reference_code' => '', 'description_text' => 'Call and notify clients with unpaid invoices over 30 days.', 'created_by_name' => 'Admin'],
        ['task_code' => 'TSK-004', 'title' => 'IP pool expansion - CGNAT block', 'category_name' => 'IT/Server', 'assignee_name' => 'Tanvir Ahmed', 'priority_label' => 'Medium', 'status_label' => 'In Progress', 'progress_percent' => 45, 'due_date' => '2026-04-28', 'reference_code' => '', 'description_text' => 'Allocate additional address block and update NAT rules.', 'created_by_name' => 'Admin'],
        ['task_code' => 'TSK-005', 'title' => 'Resolve TKT-1023 slow speed complaint', 'category_name' => 'Client Service', 'assignee_name' => 'Nusrat Jahan', 'priority_label' => 'High', 'status_label' => 'In Progress', 'progress_percent' => 80, 'due_date' => '2026-04-21', 'reference_code' => 'TKT-1023', 'description_text' => 'Audit QoS profile and line health for the affected client.', 'created_by_name' => 'Support'],
        ['task_code' => 'TSK-006', 'title' => 'Monthly bandwidth usage report', 'category_name' => 'IT/Server', 'assignee_name' => 'Rakib Hasan', 'priority_label' => 'Low', 'status_label' => 'Completed', 'progress_percent' => 100, 'due_date' => '2026-04-18', 'reference_code' => '', 'description_text' => 'Generate and mail monthly usage report to management.', 'created_by_name' => 'Admin'],
        ['task_code' => 'TSK-007', 'title' => 'Cable fault repair - Zone C trunk', 'category_name' => 'Field Work', 'assignee_name' => 'Jahidul Islam', 'priority_label' => 'Critical', 'status_label' => 'Overdue', 'progress_percent' => 30, 'due_date' => '2026-04-18', 'reference_code' => 'TKT-1018', 'description_text' => 'Repair trunk cable affecting multiple clients in Zone C.', 'created_by_name' => 'Support'],
        ['task_code' => 'TSK-008', 'title' => 'New connection request - Client C-4590', 'category_name' => 'Client Service', 'assignee_name' => 'Sumaiya Akter', 'priority_label' => 'Medium', 'status_label' => 'Pending', 'progress_percent' => 0, 'due_date' => '2026-05-01', 'reference_code' => 'C-4590', 'description_text' => 'Prepare onboarding, installation slot and billing profile.', 'created_by_name' => 'Admin'],
        ['task_code' => 'TSK-009', 'title' => 'Server room UPS battery replacement', 'category_name' => 'IT/Server', 'assignee_name' => 'Tanvir Ahmed', 'priority_label' => 'High', 'status_label' => 'On Hold', 'progress_percent' => 20, 'due_date' => '2026-04-27', 'reference_code' => '', 'description_text' => 'Replace faulty UPS batteries after procurement delivery.', 'created_by_name' => 'Admin'],
        ['task_code' => 'TSK-010', 'title' => 'Customer portal bulk password reset', 'category_name' => 'Client Service', 'assignee_name' => 'Nusrat Jahan', 'priority_label' => 'Low', 'status_label' => 'Completed', 'progress_percent' => 100, 'due_date' => '2026-04-16', 'reference_code' => '', 'description_text' => 'Assist clients with account recovery and reset actions.', 'created_by_name' => 'Support'],
    ];

    $stmt = $pdo->prepare(
        'INSERT INTO task_items (
            task_code, title, category_name, assignee_name,
            priority_label, status_label, progress_percent, due_date,
            reference_code, description_text, created_by_name,
            created_by_employee_id, assigned_to_employee_id
         )
         SELECT :task_code, :title, :category_name, :assignee_name,
                :priority_label, :status_label, :progress_percent, :due_date,
                :reference_code, :description_text, :created_by_name,
                NULL, NULL
         FROM DUAL
         WHERE NOT EXISTS (SELECT 1 FROM task_items WHERE task_code = :task_code_check)'
    );

    $inserted = 0;
    $skipped = 0;

    foreach ($rows as $row) {
        $stmt->execute([
            'task_code' => $row['task_code'],
            'title' => tasks_str_cut((string) $row['title'], 220),
            'category_name' => tasks_str_cut((string) $row['category_name'], 80),
            'assignee_name' => tasks_str_cut((string) $row['assignee_name'], 120),
            'priority_label' => $row['priority_label'],
            'status_label' => $row['status_label'],
            'progress_percent' => (int) $row['progress_percent'],
            'due_date' => $row['due_date'],
            'reference_code' => $row['reference_code'] !== '' ? tasks_str_cut((string) $row['reference_code'], 80) : null,
            'description_text' => $row['description_text'],
            'created_by_name' => tasks_str_cut((string) $row['created_by_name'], 120),
            'task_code_check' => $row['task_code'],
        ]);

        if ($stmt->rowCount() > 0) {
            $inserted++;
        } else {
            $skipped++;
        }
    }

    echo "Task seed completed. Inserted: {$inserted}, Skipped(existing): {$skipped}" . PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, 'Task seed failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
