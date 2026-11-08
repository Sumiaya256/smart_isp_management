<?php

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json(['ok' => false, 'message' => 'Method not allowed'], 405);
}

$user = require_explicit_page_permission('Resign Rule', 'edit');
$input = read_json_input();

$ruleId = clean_i($input, 'ruleId');
$ruleName = clean_s($input, 'ruleName', 140);
$departmentName = clean_s($input, 'departmentName', 120);
$employeeType = clean_s($input, 'employeeType', 80);
$minTenureMonths = max(0, clean_i($input, 'minTenureMonths'));
$noticePeriodDays = max(0, clean_i($input, 'noticePeriodDays'));
$buyoutMultiplier = clean_f($input, 'buyoutMultiplier');
$finalSettlementDays = max(0, clean_i($input, 'finalSettlementDays'));
$approvalsRequired = clean_s($input, 'approvalsRequired', 2000);
$statusLabel = clean_s($input, 'statusLabel', 20);
$descriptionText = clean_s($input, 'descriptionText', 4000);

if ($ruleName === '') {
    send_json([
        'ok' => false,
        'message' => 'ruleName is required',
    ], 422);
}

if ($statusLabel === '') {
    $statusLabel = 'Active';
}
if (!in_array($statusLabel, ['Active', 'Inactive'], true)) {
    send_json(['ok' => false, 'message' => 'Invalid statusLabel'], 422);
}

if ($buyoutMultiplier <= 0) {
    $buyoutMultiplier = 1.0;
}

try {
    $pdo = db();
    ensure_resignation_schema($pdo);

    if ($ruleId > 0) {
        $dup = $pdo->prepare('SELECT id FROM hr_resignation_rules WHERE rule_name = :rule_name AND id <> :id LIMIT 1');
        $dup->execute([
            'rule_name' => $ruleName,
            'id' => $ruleId,
        ]);
        if ($dup->fetch()) {
            send_json(['ok' => false, 'message' => 'Rule name already exists'], 409);
        }

        $update = $pdo->prepare(
            'UPDATE hr_resignation_rules SET
                rule_name = :rule_name,
                department_name = :department_name,
                employee_type = :employee_type,
                min_tenure_months = :min_tenure_months,
                notice_period_days = :notice_period_days,
                buyout_allowed = :buyout_allowed,
                buyout_multiplier = :buyout_multiplier,
                final_settlement_days = :final_settlement_days,
                exit_interview_required = :exit_interview_required,
                approvals_required = :approvals_required,
                status_label = :status_label,
                description_text = :description_text,
                updated_by = :updated_by
             WHERE id = :id'
        );

        $update->execute([
            'rule_name' => $ruleName,
            'department_name' => null_if_empty($departmentName),
            'employee_type' => null_if_empty($employeeType),
            'min_tenure_months' => $minTenureMonths,
            'notice_period_days' => $noticePeriodDays,
            'buyout_allowed' => clean_b($input, 'buyoutAllowed'),
            'buyout_multiplier' => $buyoutMultiplier,
            'final_settlement_days' => $finalSettlementDays,
            'exit_interview_required' => clean_b($input, 'exitInterviewRequired'),
            'approvals_required' => null_if_empty($approvalsRequired),
            'status_label' => $statusLabel,
            'description_text' => null_if_empty($descriptionText),
            'updated_by' => (int) ($user['id'] ?? 0),
            'id' => $ruleId,
        ]);

        send_json([
            'ok' => true,
            'message' => 'Resign rule updated successfully',
            'ruleId' => $ruleId,
        ]);
    }

    $dup = $pdo->prepare('SELECT id FROM hr_resignation_rules WHERE rule_name = :rule_name LIMIT 1');
    $dup->execute(['rule_name' => $ruleName]);
    if ($dup->fetch()) {
        send_json(['ok' => false, 'message' => 'Rule name already exists'], 409);
    }

    $insert = $pdo->prepare(
        'INSERT INTO hr_resignation_rules (
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
            created_by,
            updated_by
         ) VALUES (
            :rule_name,
            :department_name,
            :employee_type,
            :min_tenure_months,
            :notice_period_days,
            :buyout_allowed,
            :buyout_multiplier,
            :final_settlement_days,
            :exit_interview_required,
            :approvals_required,
            :status_label,
            :description_text,
            :created_by,
            :updated_by
         )'
    );

    $insert->execute([
        'rule_name' => $ruleName,
        'department_name' => null_if_empty($departmentName),
        'employee_type' => null_if_empty($employeeType),
        'min_tenure_months' => $minTenureMonths,
        'notice_period_days' => $noticePeriodDays,
        'buyout_allowed' => clean_b($input, 'buyoutAllowed'),
        'buyout_multiplier' => $buyoutMultiplier,
        'final_settlement_days' => $finalSettlementDays,
        'exit_interview_required' => clean_b($input, 'exitInterviewRequired'),
        'approvals_required' => null_if_empty($approvalsRequired),
        'status_label' => $statusLabel,
        'description_text' => null_if_empty($descriptionText),
        'created_by' => (int) ($user['id'] ?? 0),
        'updated_by' => (int) ($user['id'] ?? 0),
    ]);

    send_json([
        'ok' => true,
        'message' => 'Resign rule created successfully',
        'ruleId' => (int) $pdo->lastInsertId(),
    ], 201);
} catch (Throwable $e) {
    send_json([
        'ok' => false,
        'message' => 'Failed to save resign rule',
        'error' => $e->getMessage(),
    ], 500);
}
