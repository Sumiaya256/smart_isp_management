<?php

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    send_json(['ok' => false, 'message' => 'Method not allowed'], 405);
}

$user = require_auth();
$positionName = (string) ($user['position_name'] ?? '');
$isAccountsPayrollRole = in_array($positionName, ['Accounts Manager', 'Accounts Staff', 'Admin / Director'], true);

if (!$isAccountsPayrollRole && !user_has_module_action_access($user, 'HR & Payroll', 'view')) {
    send_json(['ok' => false, 'message' => 'Forbidden: insufficient module permission'], 403);
}

try {
    $pdo = db();
    ensure_payheads_schema($pdo);

    $sql =
        'SELECT
            id,
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
            created_at,
            updated_at
         FROM hr_payheads';

    $params = [];
    if (is_limited_module_permission($user, 'HR & Payroll')) {
        $sql .= ' WHERE created_by = :employee_id OR updated_by = :employee_id';
        $params['employee_id'] = (int) ($user['id'] ?? 0);
    }

    $sql .= ' ORDER BY priority_order ASC, payhead_name ASC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    $payheads = array_map(static function (array $row): array {
        return [
            'id' => (int) $row['id'],
            'payheadCode' => (string) $row['payhead_code'],
            'payheadName' => (string) $row['payhead_name'],
            'payheadType' => (string) $row['payhead_type'],
            'payheadCategory' => (string) ($row['payhead_category'] ?? ''),
            'calculationType' => (string) $row['calculation_type'],
            'defaultValue' => (float) $row['default_value'],
            'percentageBase' => (string) ($row['percentage_base'] ?? ''),
            'percentageRate' => (float) ($row['percentage_rate'] ?? 0),
            'formulaExpression' => (string) ($row['formula_expression'] ?? ''),
            'slabDefinition' => (string) ($row['slab_definition'] ?? ''),
            'taxable' => (int) $row['taxable'] === 1,
            'pfApplicable' => (int) $row['pf_applicable'] === 1,
            'esiApplicable' => (int) $row['esi_applicable'] === 1,
            'affectAttendance' => (int) $row['affect_attendance'] === 1,
            'proRata' => (int) $row['pro_rata'] === 1,
            'isRecurring' => (int) $row['is_recurring'] === 1,
            'visibleOnPayslip' => (int) $row['visible_on_payslip'] === 1,
            'statusLabel' => (string) ($row['status_label'] ?: 'Active'),
            'priorityOrder' => (int) ($row['priority_order'] ?? 100),
            'maxLimit' => $row['max_limit'] !== null ? (float) $row['max_limit'] : null,
            'glCode' => (string) ($row['gl_code'] ?? ''),
            'effectiveFrom' => (string) ($row['effective_from'] ?? ''),
            'effectiveTo' => (string) ($row['effective_to'] ?? ''),
            'descriptionText' => (string) ($row['description_text'] ?? ''),
            'createdAt' => (string) ($row['created_at'] ?? ''),
            'updatedAt' => (string) ($row['updated_at'] ?? ''),
        ];
    }, $rows);

    $summary = [
        'total' => count($payheads),
        'active' => 0,
        'earning' => 0,
        'deduction' => 0,
        'formula' => 0,
    ];

    foreach ($payheads as $item) {
        if ($item['statusLabel'] === 'Active') {
            $summary['active']++;
        }

        if (strcasecmp($item['payheadType'], 'Earning') === 0) {
            $summary['earning']++;
        }

        if (strcasecmp($item['payheadType'], 'Deduction') === 0) {
            $summary['deduction']++;
        }

        if (strcasecmp($item['calculationType'], 'Formula') === 0) {
            $summary['formula']++;
        }
    }

    send_json([
        'ok' => true,
        'payheads' => $payheads,
        'summary' => $summary,
    ]);
} catch (Throwable $e) {
    send_json([
        'ok' => false,
        'message' => 'Failed to fetch payheads',
        'error' => $e->getMessage(),
    ], 500);
}
