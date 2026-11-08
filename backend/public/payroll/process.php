<?php

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json(['ok' => false, 'message' => 'Method not allowed'], 405);
}

$user = require_auth();
$positionName = (string) ($user['position_name'] ?? '');
$isAccountsPayrollRole = in_array($positionName, ['Accounts Manager', 'Accounts Staff', 'Admin / Director'], true);

if (!$isAccountsPayrollRole && !user_has_page_action_access($user, 'Payroll', 'edit')) {
    send_json(['ok' => false, 'message' => 'Forbidden: insufficient module permission'], 403);
}
$input = read_json_input();

$payrollMonth = payroll_month_or_fail((string) ($input['payrollMonth'] ?? ''));
$workingDays = max(1, to_int($input, 'workingDays'));
$taxPercent = max(0, to_float($input, 'taxPercent'));
$pfPercent = max(0, to_float($input, 'pfPercent'));

$items = isset($input['items']) && is_array($input['items']) ? $input['items'] : [];
if (empty($items)) {
    send_json([
        'ok' => false,
        'message' => 'At least one payroll item is required',
    ], 422);
}

$employeeIds = [];
foreach ($items as $row) {
    $employeeId = (int) ($row['employeeId'] ?? 0);
    if ($employeeId > 0) {
        $employeeIds[] = $employeeId;
    }
}

$employeeIds = array_values(array_unique($employeeIds));
if (empty($employeeIds)) {
    send_json([
        'ok' => false,
        'message' => 'No valid employeeId found in payroll items',
    ], 422);
}

try {
    $pdo = db();
    ensure_payroll_schema($pdo);
    $pdo->beginTransaction();

    $runId = find_or_create_run(
        $pdo,
        $payrollMonth,
        $workingDays,
        $taxPercent,
        $pfPercent,
        (int) ($user['id'] ?? 0),
        is_limited_module_permission($user, 'HR & Payroll')
    );

    $employeeMap = fetch_employees_for_payroll($pdo, $employeeIds);

    $upsert = $pdo->prepare(
        'INSERT INTO hr_payroll_items (
            payroll_run_id,
            employee_id,
            payable_days,
            basic_salary,
            house_allowance,
            medical_allowance,
            transport_allowance,
            bonus_amount,
            overtime_amount,
            other_earning,
            tax_amount,
            pf_amount,
            loan_amount,
            other_deduction,
            gross_pay,
            total_deduction,
            net_pay,
            payment_status,
            remarks,
            paid_at
         ) VALUES (
            :payroll_run_id,
            :employee_id,
            :payable_days,
            :basic_salary,
            :house_allowance,
            :medical_allowance,
            :transport_allowance,
            :bonus_amount,
            :overtime_amount,
            :other_earning,
            :tax_amount,
            :pf_amount,
            :loan_amount,
            :other_deduction,
            :gross_pay,
            :total_deduction,
            :net_pay,
            :payment_status,
            :remarks,
            :paid_at
         )
         ON DUPLICATE KEY UPDATE
            payable_days = VALUES(payable_days),
            basic_salary = VALUES(basic_salary),
            house_allowance = VALUES(house_allowance),
            medical_allowance = VALUES(medical_allowance),
            transport_allowance = VALUES(transport_allowance),
            bonus_amount = VALUES(bonus_amount),
            overtime_amount = VALUES(overtime_amount),
            other_earning = VALUES(other_earning),
            tax_amount = VALUES(tax_amount),
            pf_amount = VALUES(pf_amount),
            loan_amount = VALUES(loan_amount),
            other_deduction = VALUES(other_deduction),
            gross_pay = VALUES(gross_pay),
            total_deduction = VALUES(total_deduction),
            net_pay = VALUES(net_pay),
            payment_status = VALUES(payment_status),
            remarks = VALUES(remarks),
            paid_at = VALUES(paid_at)'
    );

    $savedCount = 0;
    foreach ($items as $row) {
        $employeeId = (int) ($row['employeeId'] ?? 0);
        if ($employeeId <= 0 || !isset($employeeMap[$employeeId])) {
            continue;
        }

        $employee = $employeeMap[$employeeId];
        $basicSalary = (float) ($employee['basic_salary'] ?? 0);
        $houseAllowance = (float) ($employee['house_allowance'] ?? 0);
        $medicalAllowance = (float) ($employee['medical_allowance'] ?? 0);
        $transportAllowance = (float) ($employee['transport_allowance'] ?? 0);

        $payableDays = (int) ($row['payableDays'] ?? $workingDays);
        $payableDays = max(0, min($workingDays, $payableDays));
        $factor = $workingDays > 0 ? ($payableDays / $workingDays) : 1.0;

        $proratedBasic = $basicSalary * $factor;
        $proratedHouse = $houseAllowance * $factor;
        $proratedMedical = $medicalAllowance * $factor;
        $proratedTransport = $transportAllowance * $factor;

        $bonusAmount = (float) ($row['bonusAmount'] ?? 0);
        $overtimeAmount = (float) ($row['overtimeAmount'] ?? 0);
        $otherEarning = (float) ($row['otherEarning'] ?? 0);
        $loanAmount = max(0, (float) ($row['loanAmount'] ?? 0));
        $otherDeduction = max(0, (float) ($row['otherDeduction'] ?? 0));
        $remarks = trim((string) ($row['remarks'] ?? ''));

        $taxAmount = ($proratedBasic * $taxPercent) / 100;
        $pfAmount = ($proratedBasic * $pfPercent) / 100;

        $grossPay = $proratedBasic + $proratedHouse + $proratedMedical + $proratedTransport + $bonusAmount + $overtimeAmount + $otherEarning;
        $totalDeduction = $taxAmount + $pfAmount + $loanAmount + $otherDeduction;
        $netPay = max(0, $grossPay - $totalDeduction);

        $paymentStatus = trim((string) ($row['paymentStatus'] ?? 'Pending'));
        if (!in_array($paymentStatus, ['Pending', 'Processed', 'Paid', 'Hold'], true)) {
            $paymentStatus = 'Pending';
        }

        $paidAt = null;
        if ($paymentStatus === 'Paid') {
            $paidAt = date('Y-m-d H:i:s');
        }

        $upsert->execute([
            'payroll_run_id' => $runId,
            'employee_id' => $employeeId,
            'payable_days' => $payableDays,
            'basic_salary' => $proratedBasic,
            'house_allowance' => $proratedHouse,
            'medical_allowance' => $proratedMedical,
            'transport_allowance' => $proratedTransport,
            'bonus_amount' => $bonusAmount,
            'overtime_amount' => $overtimeAmount,
            'other_earning' => $otherEarning,
            'tax_amount' => $taxAmount,
            'pf_amount' => $pfAmount,
            'loan_amount' => $loanAmount,
            'other_deduction' => $otherDeduction,
            'gross_pay' => $grossPay,
            'total_deduction' => $totalDeduction,
            'net_pay' => $netPay,
            'payment_status' => $paymentStatus,
            'remarks' => $remarks === '' ? null : $remarks,
            'paid_at' => $paidAt,
        ]);

        $savedCount++;
    }

    $pdo->commit();

    send_json([
        'ok' => true,
        'message' => 'Payroll processed successfully',
        'runId' => $runId,
        'savedCount' => $savedCount,
    ]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    send_json([
        'ok' => false,
        'message' => 'Failed to process payroll',
        'error' => $e->getMessage(),
    ], 500);
}
