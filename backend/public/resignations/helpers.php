<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/database.php';
require_once dirname(__DIR__, 2) . '/config/auth.php';

function ensure_resignation_schema(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS hr_resignation_rules (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            rule_name VARCHAR(140) NOT NULL,
            department_name VARCHAR(120) DEFAULT NULL,
            employee_type VARCHAR(80) DEFAULT NULL,
            min_tenure_months INT NOT NULL DEFAULT 0,
            notice_period_days INT NOT NULL DEFAULT 30,
            buyout_allowed TINYINT(1) NOT NULL DEFAULT 0,
            buyout_multiplier DECIMAL(8,2) NOT NULL DEFAULT 1.00,
            final_settlement_days INT NOT NULL DEFAULT 15,
            exit_interview_required TINYINT(1) NOT NULL DEFAULT 1,
            approvals_required TEXT DEFAULT NULL,
            status_label VARCHAR(20) NOT NULL DEFAULT "Active",
            description_text TEXT DEFAULT NULL,
            created_by BIGINT UNSIGNED DEFAULT NULL,
            updated_by BIGINT UNSIGNED DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_hr_resignation_rules_name (rule_name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS hr_resignations (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            employee_id BIGINT UNSIGNED DEFAULT NULL,
            employee_code VARCHAR(64) DEFAULT NULL,
            employee_name VARCHAR(190) NOT NULL,
            department_name VARCHAR(120) DEFAULT NULL,
            designation_title VARCHAR(120) DEFAULT NULL,
            resignation_type VARCHAR(40) NOT NULL DEFAULT "Voluntary",
            submitted_on DATE NOT NULL,
            notice_days INT NOT NULL DEFAULT 30,
            last_working_date DATE DEFAULT NULL,
            reason_text TEXT DEFAULT NULL,
            handover_status VARCHAR(30) NOT NULL DEFAULT "Pending",
            asset_clearance_status VARCHAR(30) NOT NULL DEFAULT "Pending",
            finance_clearance_status VARCHAR(30) NOT NULL DEFAULT "Pending",
            hr_clearance_status VARCHAR(30) NOT NULL DEFAULT "Pending",
            exit_interview_date DATE DEFAULT NULL,
            knowledge_transfer_done TINYINT(1) NOT NULL DEFAULT 0,
            final_settlement_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
            final_settlement_status VARCHAR(30) NOT NULL DEFAULT "Pending",
            status_label VARCHAR(30) NOT NULL DEFAULT "Submitted",
            remarks_text TEXT DEFAULT NULL,
            approved_on DATE DEFAULT NULL,
            relieved_on DATE DEFAULT NULL,
            created_by BIGINT UNSIGNED DEFAULT NULL,
            updated_by BIGINT UNSIGNED DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_hr_resignations_status (status_label),
            KEY idx_hr_resignations_submitted (submitted_on),
            KEY idx_hr_resignations_employee (employee_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
}

function clean_s(array $input, string $key, int $maxLen = 255): string
{
    $value = trim((string) ($input[$key] ?? ''));
    if ($maxLen > 0 && mb_strlen($value) > $maxLen) {
        return mb_substr($value, 0, $maxLen);
    }
    return $value;
}

function clean_i(array $input, string $key): int
{
    return (int) ($input[$key] ?? 0);
}

function clean_f(array $input, string $key): float
{
    return (float) ($input[$key] ?? 0);
}

function clean_b(array $input, string $key): int
{
    $value = $input[$key] ?? false;
    if (is_bool($value)) {
        return $value ? 1 : 0;
    }

    if (is_numeric($value)) {
        return ((int) $value) !== 0 ? 1 : 0;
    }

    $normalized = strtolower(trim((string) $value));
    return in_array($normalized, ['1', 'true', 'yes', 'on'], true) ? 1 : 0;
}

function null_if_empty(string $value): ?string
{
    return $value === '' ? null : $value;
}
