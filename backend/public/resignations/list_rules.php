<?php

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    send_json(['ok' => false, 'message' => 'Method not allowed'], 405);
}

require_module_permission('HR & Payroll', 'view');

try {
    $pdo = db();
    ensure_resignation_schema($pdo);

    $rows = $pdo->query(
        'SELECT
            id,
            rule_name,
            department_name,
            employee_type,
            min_tenure_months,
            notice_period_days,
            buyout_allowed,
            buyout_multiplier,
            final_settlement_days,
            exit_interview_required,
            approvals_required,
            status_label,
            description_text,
            created_at,
            updated_at
         FROM hr_resignation_rules
         ORDER BY notice_period_days ASC, rule_name ASC'
    )->fetchAll();

    $rules = array_map(static function (array $row): array {
        return [
            'id' => (int) $row['id'],
            'ruleName' => (string) $row['rule_name'],
            'departmentName' => (string) ($row['department_name'] ?? ''),
            'employeeType' => (string) ($row['employee_type'] ?? ''),
            'minTenureMonths' => (int) ($row['min_tenure_months'] ?? 0),
            'noticePeriodDays' => (int) ($row['notice_period_days'] ?? 0),
            'buyoutAllowed' => (int) ($row['buyout_allowed'] ?? 0) === 1,
            'buyoutMultiplier' => (float) ($row['buyout_multiplier'] ?? 1),
            'finalSettlementDays' => (int) ($row['final_settlement_days'] ?? 15),
            'exitInterviewRequired' => (int) ($row['exit_interview_required'] ?? 0) === 1,
            'approvalsRequired' => (string) ($row['approvals_required'] ?? ''),
            'statusLabel' => (string) ($row['status_label'] ?? 'Active'),
            'descriptionText' => (string) ($row['description_text'] ?? ''),
            'createdAt' => (string) ($row['created_at'] ?? ''),
            'updatedAt' => (string) ($row['updated_at'] ?? ''),
        ];
    }, $rows);

    $summary = [
        'total' => count($rules),
        'active' => 0,
        'buyout' => 0,
        'exitInterview' => 0,
        'avgNotice' => 0,
    ];

    $noticeTotal = 0;
    foreach ($rules as $rule) {
        if ($rule['statusLabel'] === 'Active') {
            $summary['active']++;
        }
        if ($rule['buyoutAllowed']) {
            $summary['buyout']++;
        }
        if ($rule['exitInterviewRequired']) {
            $summary['exitInterview']++;
        }
        $noticeTotal += $rule['noticePeriodDays'];
    }

    if ($summary['total'] > 0) {
        $summary['avgNotice'] = round($noticeTotal / $summary['total'], 2);
    }

    send_json([
        'ok' => true,
        'rules' => $rules,
        'summary' => $summary,
    ]);
} catch (Throwable $e) {
    send_json([
        'ok' => false,
        'message' => 'Failed to fetch resign rules',
        'error' => $e->getMessage(),
    ], 500);
}
