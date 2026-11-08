<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/database.php';
require_once dirname(__DIR__, 2) . '/config/auth.php';

function ensure_payroll_schema(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS hr_payroll_runs (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            payroll_month CHAR(7) NOT NULL,
            working_days INT NOT NULL DEFAULT 30,
            tax_percent DECIMAL(8,4) NOT NULL DEFAULT 0,
            pf_percent DECIMAL(8,4) NOT NULL DEFAULT 0,
            status_label VARCHAR(20) NOT NULL DEFAULT "Processed",
            processed_at DATETIME DEFAULT NULL,
            generated_by BIGINT UNSIGNED DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_hr_payroll_runs_month (payroll_month)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS hr_payroll_items (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            payroll_run_id BIGINT UNSIGNED NOT NULL,
            employee_id BIGINT UNSIGNED NOT NULL,
            payable_days INT NOT NULL DEFAULT 30,
            basic_salary DECIMAL(12,2) NOT NULL DEFAULT 0,
            house_allowance DECIMAL(12,2) NOT NULL DEFAULT 0,
            medical_allowance DECIMAL(12,2) NOT NULL DEFAULT 0,
            transport_allowance DECIMAL(12,2) NOT NULL DEFAULT 0,
            bonus_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
            overtime_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
            other_earning DECIMAL(12,2) NOT NULL DEFAULT 0,
            tax_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
            pf_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
            loan_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
            other_deduction DECIMAL(12,2) NOT NULL DEFAULT 0,
            gross_pay DECIMAL(12,2) NOT NULL DEFAULT 0,
            total_deduction DECIMAL(12,2) NOT NULL DEFAULT 0,
            net_pay DECIMAL(12,2) NOT NULL DEFAULT 0,
            payment_status VARCHAR(20) NOT NULL DEFAULT "Pending",
            remarks TEXT DEFAULT NULL,
            paid_at DATETIME DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_hr_payroll_run_employee (payroll_run_id, employee_id),
            KEY idx_hr_payroll_items_employee (employee_id),
            CONSTRAINT fk_hr_payroll_item_run FOREIGN KEY (payroll_run_id) REFERENCES hr_payroll_runs(id) ON DELETE CASCADE,
            CONSTRAINT fk_hr_payroll_item_employee FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
}

function payroll_month_or_fail(string $month): string
{
    $normalized = trim($month);
    if (!preg_match('/^\d{4}-\d{2}$/', $normalized)) {
        send_json([
            'ok' => false,
            'message' => 'payrollMonth must be in YYYY-MM format',
        ], 422);
    }

    return $normalized;
}

function to_float(array $input, string $key): float
{
    return (float) ($input[$key] ?? 0);
}

function to_int(array $input, string $key): int
{
    return (int) ($input[$key] ?? 0);
}

function find_or_create_run(PDO $pdo, string $payrollMonth, int $workingDays, float $taxPercent, float $pfPercent, int $userId, bool $limitedOwnScope = false): int
{
    $runStmt = $pdo->prepare('SELECT id FROM hr_payroll_runs WHERE payroll_month = :month LIMIT 1');
    $runStmt->execute(['month' => $payrollMonth]);
    $existing = $runStmt->fetch();

    if ($existing) {
        $runId = (int) $existing['id'];

        if ($limitedOwnScope) {
            $ownerStmt = $pdo->prepare('SELECT generated_by FROM hr_payroll_runs WHERE id = :id LIMIT 1');
            $ownerStmt->execute(['id' => $runId]);
            $owner = $ownerStmt->fetch();
            $generatedBy = (int) ($owner['generated_by'] ?? 0);
            if ($generatedBy > 0 && $generatedBy !== $userId) {
                send_json([
                    'ok' => false,
                    'message' => 'Forbidden: limited access allows updating only own payroll runs',
                ], 403);
            }
        }

        $updateRun = $pdo->prepare(
            'UPDATE hr_payroll_runs
             SET working_days = :working_days,
                 tax_percent = :tax_percent,
                 pf_percent = :pf_percent,
                 status_label = :status_label,
                 processed_at = :processed_at,
                 generated_by = :generated_by
             WHERE id = :id'
        );
        $updateRun->execute([
            'working_days' => $workingDays,
            'tax_percent' => $taxPercent,
            'pf_percent' => $pfPercent,
            'status_label' => 'Processed',
            'processed_at' => date('Y-m-d H:i:s'),
            'generated_by' => $userId,
            'id' => $runId,
        ]);

        return $runId;
    }

    $insertRun = $pdo->prepare(
        'INSERT INTO hr_payroll_runs (
            payroll_month,
            working_days,
            tax_percent,
            pf_percent,
            status_label,
            processed_at,
            generated_by
         ) VALUES (
            :payroll_month,
            :working_days,
            :tax_percent,
            :pf_percent,
            :status_label,
            :processed_at,
            :generated_by
         )'
    );

    $insertRun->execute([
        'payroll_month' => $payrollMonth,
        'working_days' => $workingDays,
        'tax_percent' => $taxPercent,
        'pf_percent' => $pfPercent,
        'status_label' => 'Processed',
        'processed_at' => date('Y-m-d H:i:s'),
        'generated_by' => $userId,
    ]);

    return (int) $pdo->lastInsertId();
}

function fetch_employees_for_payroll(PDO $pdo, array $employeeIds): array
{
    if (empty($employeeIds)) {
        return [];
    }

    $cleanIds = array_values(array_unique(array_filter(array_map('intval', $employeeIds), static fn ($v) => $v > 0)));
    if (empty($cleanIds)) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($cleanIds), '?'));
    $sql =
        'SELECT
            e.id,
            e.employee_code,
            e.full_name,
            e.basic_salary,
            e.employment_status,
            d.department_name,
            p.position_name,
            ep.house_allowance,
            ep.medical_allowance,
            ep.transport_allowance
         FROM employees e
         LEFT JOIN departments d ON d.id = e.department_id
         LEFT JOIN positions p ON p.id = e.position_id
         LEFT JOIN employee_profiles ep ON ep.employee_id = e.id
         WHERE e.id IN (' . $placeholders . ')';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($cleanIds);

    $rows = $stmt->fetchAll();
    $map = [];
    foreach ($rows as $row) {
        $map[(int) $row['id']] = $row;
    }

    return $map;
}
