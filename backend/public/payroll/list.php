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

$month = trim((string) ($_GET['month'] ?? ''));

try {
    $pdo = db();
    ensure_payroll_schema($pdo);

    $run = null;
    if ($month !== '') {
        $month = payroll_month_or_fail($month);
        $runSql =
            'SELECT id, payroll_month, working_days, tax_percent, pf_percent, status_label, processed_at
             FROM hr_payroll_runs
             WHERE payroll_month = :month';
        $runParams = ['month' => $month];
        if (is_limited_module_permission($user, 'HR & Payroll')) {
            $runSql .= ' AND generated_by = :generated_by';
            $runParams['generated_by'] = (int) ($user['id'] ?? 0);
        }
        $runSql .= ' LIMIT 1';
        $runStmt = $pdo->prepare($runSql);
        $runStmt->execute($runParams);
        $run = $runStmt->fetch() ?: null;
    } else {
        $runSql =
            'SELECT id, payroll_month, working_days, tax_percent, pf_percent, status_label, processed_at
             FROM hr_payroll_runs';
        $runParams = [];
        if (is_limited_module_permission($user, 'HR & Payroll')) {
            $runSql .= ' WHERE generated_by = :generated_by';
            $runParams['generated_by'] = (int) ($user['id'] ?? 0);
        }
        $runSql .= ' ORDER BY payroll_month DESC LIMIT 1';
        $runStmt = $pdo->prepare($runSql);
        $runStmt->execute($runParams);
        $run = $runStmt->fetch() ?: null;
    }

    $items = [];
    $summary = [
        'totalEmployees' => 0,
        'grossPayroll' => 0.0,
        'totalDeduction' => 0.0,
        'netPayroll' => 0.0,
        'paidCount' => 0,
        'pendingCount' => 0,
    ];

    if ($run) {
        $itemStmt = $pdo->prepare(
            'SELECT
                i.id,
                i.employee_id,
                i.payable_days,
                i.basic_salary,
                i.house_allowance,
                i.medical_allowance,
                i.transport_allowance,
                i.bonus_amount,
                i.overtime_amount,
                i.other_earning,
                i.tax_amount,
                i.pf_amount,
                i.loan_amount,
                i.other_deduction,
                i.gross_pay,
                i.total_deduction,
                i.net_pay,
                i.payment_status,
                i.remarks,
                i.paid_at,
                e.employee_code,
                e.full_name,
                d.department_name,
                p.position_name
             FROM hr_payroll_items i
             INNER JOIN employees e ON e.id = i.employee_id
             LEFT JOIN departments d ON d.id = e.department_id
             LEFT JOIN positions p ON p.id = e.position_id
             WHERE i.payroll_run_id = :run_id
             ORDER BY e.full_name ASC'
        );
        $itemStmt->execute(['run_id' => (int) $run['id']]);

        $rows = $itemStmt->fetchAll();
        foreach ($rows as $row) {
            $paymentStatus = (string) ($row['payment_status'] ?? 'Pending');

            $item = [
                'id' => (int) $row['id'],
                'employeeId' => (int) $row['employee_id'],
                'employeeCode' => (string) ($row['employee_code'] ?? ''),
                'employeeName' => (string) ($row['full_name'] ?? ''),
                'department' => (string) ($row['department_name'] ?? ''),
                'designation' => (string) ($row['position_name'] ?? ''),
                'payableDays' => (int) ($row['payable_days'] ?? 0),
                'basicSalary' => (float) ($row['basic_salary'] ?? 0),
                'houseAllowance' => (float) ($row['house_allowance'] ?? 0),
                'medicalAllowance' => (float) ($row['medical_allowance'] ?? 0),
                'transportAllowance' => (float) ($row['transport_allowance'] ?? 0),
                'bonusAmount' => (float) ($row['bonus_amount'] ?? 0),
                'overtimeAmount' => (float) ($row['overtime_amount'] ?? 0),
                'otherEarning' => (float) ($row['other_earning'] ?? 0),
                'taxAmount' => (float) ($row['tax_amount'] ?? 0),
                'pfAmount' => (float) ($row['pf_amount'] ?? 0),
                'loanAmount' => (float) ($row['loan_amount'] ?? 0),
                'otherDeduction' => (float) ($row['other_deduction'] ?? 0),
                'grossPay' => (float) ($row['gross_pay'] ?? 0),
                'totalDeduction' => (float) ($row['total_deduction'] ?? 0),
                'netPay' => (float) ($row['net_pay'] ?? 0),
                'paymentStatus' => $paymentStatus,
                'remarks' => (string) ($row['remarks'] ?? ''),
                'paidAt' => (string) ($row['paid_at'] ?? ''),
            ];

            $items[] = $item;
            $summary['totalEmployees']++;
            $summary['grossPayroll'] += $item['grossPay'];
            $summary['totalDeduction'] += $item['totalDeduction'];
            $summary['netPayroll'] += $item['netPay'];

            if ($paymentStatus === 'Paid') {
                $summary['paidCount']++;
            } else {
                $summary['pendingCount']++;
            }
        }
    }

    send_json([
        'ok' => true,
        'run' => $run ? [
            'id' => (int) $run['id'],
            'payrollMonth' => (string) $run['payroll_month'],
            'workingDays' => (int) $run['working_days'],
            'taxPercent' => (float) $run['tax_percent'],
            'pfPercent' => (float) $run['pf_percent'],
            'statusLabel' => (string) $run['status_label'],
            'processedAt' => (string) ($run['processed_at'] ?? ''),
        ] : null,
        'items' => $items,
        'summary' => $summary,
    ]);
} catch (Throwable $e) {
    send_json([
        'ok' => false,
        'message' => 'Failed to fetch payroll data',
        'error' => $e->getMessage(),
    ], 500);
}
