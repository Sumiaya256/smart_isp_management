<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/database.php';
require_once dirname(__DIR__, 2) . '/config/auth.php';

function ensure_payheads_schema(PDO $pdo): void
{
    $sql = <<<'SQL'
CREATE TABLE IF NOT EXISTS hr_payheads (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    payhead_code VARCHAR(32) NOT NULL,
    payhead_name VARCHAR(140) NOT NULL,
    payhead_type VARCHAR(40) NOT NULL,
    payhead_category VARCHAR(80) DEFAULT NULL,
    calculation_type VARCHAR(40) NOT NULL,
    default_value DECIMAL(12,2) NOT NULL DEFAULT 0,
    percentage_base VARCHAR(30) DEFAULT NULL,
    percentage_rate DECIMAL(8,4) NOT NULL DEFAULT 0,
    formula_expression TEXT DEFAULT NULL,
    slab_definition LONGTEXT DEFAULT NULL,
    taxable TINYINT(1) NOT NULL DEFAULT 0,
    pf_applicable TINYINT(1) NOT NULL DEFAULT 0,
    esi_applicable TINYINT(1) NOT NULL DEFAULT 0,
    affect_attendance TINYINT(1) NOT NULL DEFAULT 0,
    pro_rata TINYINT(1) NOT NULL DEFAULT 0,
    is_recurring TINYINT(1) NOT NULL DEFAULT 1,
    visible_on_payslip TINYINT(1) NOT NULL DEFAULT 1,
    status_label VARCHAR(20) NOT NULL DEFAULT 'Active',
    priority_order INT NOT NULL DEFAULT 100,
    max_limit DECIMAL(12,2) DEFAULT NULL,
    gl_code VARCHAR(80) DEFAULT NULL,
    effective_from DATE DEFAULT NULL,
    effective_to DATE DEFAULT NULL,
    description_text TEXT DEFAULT NULL,
    created_by INT DEFAULT NULL,
    updated_by INT DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_hr_payheads_code (payhead_code),
    UNIQUE KEY uq_hr_payheads_name (payhead_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

    $pdo->exec($sql);
}

function clean_str(array $input, string $key, int $maxLen = 255): string
{
    $value = trim((string) ($input[$key] ?? ''));
    if ($maxLen > 0 && mb_strlen($value) > $maxLen) {
        return mb_substr($value, 0, $maxLen);
    }
    return $value;
}

function clean_bool(array $input, string $key): int
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

function clean_float(array $input, string $key): float
{
    return (float) ($input[$key] ?? 0);
}

function clean_int(array $input, string $key): int
{
    return (int) ($input[$key] ?? 0);
}

function null_if_empty(string $value): ?string
{
    return $value === '' ? null : $value;
}

function make_payhead_code(string $name): string
{
    $base = strtoupper(preg_replace('/[^A-Za-z0-9]+/', '_', trim($name)) ?? '');
    $base = trim($base, '_');
    if ($base === '') {
        $base = 'PAYHEAD';
    }
    if (strlen($base) > 18) {
        $base = substr($base, 0, 18);
    }

    return sprintf('PH_%s_%04d', $base, random_int(1000, 9999));
}

function normalize_slab_json(string $value): string
{
    if ($value === '') {
        return '';
    }

    $decoded = json_decode($value, true);
    if (!is_array($decoded)) {
        send_json([
            'ok' => false,
            'message' => 'Slab definition must be a valid JSON array',
        ], 422);
    }

    return json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
