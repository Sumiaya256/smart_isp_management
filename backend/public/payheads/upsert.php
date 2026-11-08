<?php

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json(['ok' => false, 'message' => 'Method not allowed'], 405);
}

$user = require_auth();
$positionName = (string) ($user['position_name'] ?? '');
$isAccountsPayrollRole = in_array($positionName, ['Accounts Manager', 'Accounts Staff', 'Admin / Director'], true);

if (!$isAccountsPayrollRole && !user_has_page_action_access($user, 'Payhead', 'edit')) {
    send_json(['ok' => false, 'message' => 'Forbidden: insufficient module permission'], 403);
}
$input = read_json_input();

$payheadId = clean_int($input, 'payheadId');
$payheadName = clean_str($input, 'payheadName', 140);
$payheadCode = clean_str($input, 'payheadCode', 32);
$payheadType = clean_str($input, 'payheadType', 40);
$payheadCategory = clean_str($input, 'payheadCategory', 80);
$calculationType = clean_str($input, 'calculationType', 40);
$defaultValue = clean_float($input, 'defaultValue');
$percentageBase = clean_str($input, 'percentageBase', 30);
$percentageRate = clean_float($input, 'percentageRate');
$formulaExpression = clean_str($input, 'formulaExpression', 1000);
$slabDefinitionRaw = clean_str($input, 'slabDefinition', 10000);
$statusLabel = clean_str($input, 'statusLabel', 20);
$priorityOrder = clean_int($input, 'priorityOrder');
$maxLimitRaw = clean_str($input, 'maxLimit', 40);
$glCode = clean_str($input, 'glCode', 80);
$effectiveFrom = clean_str($input, 'effectiveFrom', 20);
$effectiveTo = clean_str($input, 'effectiveTo', 20);
$descriptionText = clean_str($input, 'descriptionText', 4000);

if ($payheadName === '' || $payheadType === '' || $calculationType === '') {
    send_json([
        'ok' => false,
        'message' => 'payheadName, payheadType and calculationType are required',
    ], 422);
}

$allowedTypes = ['Earning', 'Deduction', 'Reimbursement', 'Employer Contribution'];
if (!in_array($payheadType, $allowedTypes, true)) {
    send_json(['ok' => false, 'message' => 'Invalid payheadType'], 422);
}

$allowedCalc = ['Fixed', 'Percentage', 'Formula', 'Slab'];
if (!in_array($calculationType, $allowedCalc, true)) {
    send_json(['ok' => false, 'message' => 'Invalid calculationType'], 422);
}

if ($statusLabel === '') {
    $statusLabel = 'Active';
}
if (!in_array($statusLabel, ['Active', 'Inactive'], true)) {
    send_json(['ok' => false, 'message' => 'Invalid statusLabel'], 422);
}

if ($payheadCode === '') {
    $payheadCode = make_payhead_code($payheadName);
}

$slabDefinition = normalize_slab_json($slabDefinitionRaw);
$maxLimit = $maxLimitRaw === '' ? null : (float) $maxLimitRaw;
$effectiveFrom = null_if_empty($effectiveFrom);
$effectiveTo = null_if_empty($effectiveTo);

if ($priorityOrder <= 0) {
    $priorityOrder = 100;
}

try {
    $pdo = db();
    ensure_payheads_schema($pdo);

    if ($payheadId > 0) {
        if (is_limited_module_permission($user, 'HR & Payroll')) {
            $scopeStmt = $pdo->prepare(
                'SELECT id FROM hr_payheads
                 WHERE id = :id AND (created_by = :employee_id OR updated_by = :employee_id)
                 LIMIT 1'
            );
            $scopeStmt->execute([
                'id' => $payheadId,
                'employee_id' => (int) ($user['id'] ?? 0),
            ]);
            if (!$scopeStmt->fetch()) {
                send_json([
                    'ok' => false,
                    'message' => 'Forbidden: limited access allows editing only own payheads',
                ], 403);
            }
        }

        $dup = $pdo->prepare(
            'SELECT id FROM hr_payheads
             WHERE (payhead_name = :name OR payhead_code = :code)
             AND id <> :id
             LIMIT 1'
        );
        $dup->execute([
            'name' => $payheadName,
            'code' => $payheadCode,
            'id' => $payheadId,
        ]);

        if ($dup->fetch()) {
            send_json(['ok' => false, 'message' => 'Payhead name or code already exists'], 409);
        }

        $update = $pdo->prepare(
            'UPDATE hr_payheads SET
                payhead_code = :payhead_code,
                payhead_name = :payhead_name,
                payhead_type = :payhead_type,
                payhead_category = :payhead_category,
                calculation_type = :calculation_type,
                default_value = :default_value,
                percentage_base = :percentage_base,
                percentage_rate = :percentage_rate,
                formula_expression = :formula_expression,
                slab_definition = :slab_definition,
                taxable = :taxable,
                pf_applicable = :pf_applicable,
                esi_applicable = :esi_applicable,
                affect_attendance = :affect_attendance,
                pro_rata = :pro_rata,
                is_recurring = :is_recurring,
                visible_on_payslip = :visible_on_payslip,
                status_label = :status_label,
                priority_order = :priority_order,
                max_limit = :max_limit,
                gl_code = :gl_code,
                effective_from = :effective_from,
                effective_to = :effective_to,
                description_text = :description_text,
                updated_by = :updated_by
             WHERE id = :id'
        );

        $update->execute([
            'payhead_code' => $payheadCode,
            'payhead_name' => $payheadName,
            'payhead_type' => $payheadType,
            'payhead_category' => null_if_empty($payheadCategory),
            'calculation_type' => $calculationType,
            'default_value' => $defaultValue,
            'percentage_base' => null_if_empty($percentageBase),
            'percentage_rate' => $percentageRate,
            'formula_expression' => null_if_empty($formulaExpression),
            'slab_definition' => null_if_empty($slabDefinition),
            'taxable' => clean_bool($input, 'taxable'),
            'pf_applicable' => clean_bool($input, 'pfApplicable'),
            'esi_applicable' => clean_bool($input, 'esiApplicable'),
            'affect_attendance' => clean_bool($input, 'affectAttendance'),
            'pro_rata' => clean_bool($input, 'proRata'),
            'is_recurring' => clean_bool($input, 'isRecurring'),
            'visible_on_payslip' => clean_bool($input, 'visibleOnPayslip'),
            'status_label' => $statusLabel,
            'priority_order' => $priorityOrder,
            'max_limit' => $maxLimit,
            'gl_code' => null_if_empty($glCode),
            'effective_from' => $effectiveFrom,
            'effective_to' => $effectiveTo,
            'description_text' => null_if_empty($descriptionText),
            'updated_by' => (int) ($user['id'] ?? 0),
            'id' => $payheadId,
        ]);

        send_json([
            'ok' => true,
            'message' => 'Payhead updated successfully',
            'payheadId' => $payheadId,
        ]);
    }

    $dup = $pdo->prepare('SELECT id FROM hr_payheads WHERE payhead_name = :name OR payhead_code = :code LIMIT 1');
    $dup->execute([
        'name' => $payheadName,
        'code' => $payheadCode,
    ]);

    if ($dup->fetch()) {
        send_json(['ok' => false, 'message' => 'Payhead name or code already exists'], 409);
    }

    $insert = $pdo->prepare(
        'INSERT INTO hr_payheads (
            payhead_code,
            payhead_name,
            payhead_type,
            payhead_category,
            calculation_type,
            default_value,
            percentage_base,
            percentage_rate,
            formula_expression,
            slab_definition,
            taxable,
            pf_applicable,
            esi_applicable,
            affect_attendance,
            pro_rata,
            is_recurring,
            visible_on_payslip,
            status_label,
            priority_order,
            max_limit,
            gl_code,
            effective_from,
            effective_to,
            description_text,
            created_by,
            updated_by
         ) VALUES (
            :payhead_code,
            :payhead_name,
            :payhead_type,
            :payhead_category,
            :calculation_type,
            :default_value,
            :percentage_base,
            :percentage_rate,
            :formula_expression,
            :slab_definition,
            :taxable,
            :pf_applicable,
            :esi_applicable,
            :affect_attendance,
            :pro_rata,
            :is_recurring,
            :visible_on_payslip,
            :status_label,
            :priority_order,
            :max_limit,
            :gl_code,
            :effective_from,
            :effective_to,
            :description_text,
            :created_by,
            :updated_by
         )'
    );

    $insert->execute([
        'payhead_code' => $payheadCode,
        'payhead_name' => $payheadName,
        'payhead_type' => $payheadType,
        'payhead_category' => null_if_empty($payheadCategory),
        'calculation_type' => $calculationType,
        'default_value' => $defaultValue,
        'percentage_base' => null_if_empty($percentageBase),
        'percentage_rate' => $percentageRate,
        'formula_expression' => null_if_empty($formulaExpression),
        'slab_definition' => null_if_empty($slabDefinition),
        'taxable' => clean_bool($input, 'taxable'),
        'pf_applicable' => clean_bool($input, 'pfApplicable'),
        'esi_applicable' => clean_bool($input, 'esiApplicable'),
        'affect_attendance' => clean_bool($input, 'affectAttendance'),
        'pro_rata' => clean_bool($input, 'proRata'),
        'is_recurring' => clean_bool($input, 'isRecurring'),
        'visible_on_payslip' => clean_bool($input, 'visibleOnPayslip'),
        'status_label' => $statusLabel,
        'priority_order' => $priorityOrder,
        'max_limit' => $maxLimit,
        'gl_code' => null_if_empty($glCode),
        'effective_from' => $effectiveFrom,
        'effective_to' => $effectiveTo,
        'description_text' => null_if_empty($descriptionText),
        'created_by' => (int) ($user['id'] ?? 0),
        'updated_by' => (int) ($user['id'] ?? 0),
    ]);

    send_json([
        'ok' => true,
        'message' => 'Payhead created successfully',
        'payheadId' => (int) $pdo->lastInsertId(),
    ], 201);
} catch (Throwable $e) {
    send_json([
        'ok' => false,
        'message' => 'Failed to save payhead',
        'error' => $e->getMessage(),
    ], 500);
}
