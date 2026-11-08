<?php

declare(strict_types=1);

function ensure_internet_package_columns(PDO $pdo): void
{
    $columns = get_table_columns($pdo, 'internet_packages');

    if (!isset($columns['tagline'])) {
        $pdo->exec('ALTER TABLE internet_packages ADD COLUMN tagline VARCHAR(180) NULL AFTER package_name');
    }
    if (!isset($columns['upload_mbps'])) {
        $pdo->exec('ALTER TABLE internet_packages ADD COLUMN upload_mbps INT UNSIGNED NULL AFTER speed_mbps');
    }
    if (!isset($columns['data_limit_gb'])) {
        $pdo->exec('ALTER TABLE internet_packages ADD COLUMN data_limit_gb INT UNSIGNED NULL AFTER monthly_price');
    }
    if (!isset($columns['support_level'])) {
        $pdo->exec('ALTER TABLE internet_packages ADD COLUMN support_level VARCHAR(60) NULL AFTER data_limit_gb');
    }
    if (!isset($columns['router_included'])) {
        $pdo->exec('ALTER TABLE internet_packages ADD COLUMN router_included TINYINT(1) NOT NULL DEFAULT 0 AFTER support_level');
    }
    if (!isset($columns['installation_fee'])) {
        $pdo->exec('ALTER TABLE internet_packages ADD COLUMN installation_fee DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER router_included');
    }
    if (!isset($columns['is_popular'])) {
        $pdo->exec('ALTER TABLE internet_packages ADD COLUMN is_popular TINYINT(1) NOT NULL DEFAULT 0 AFTER installation_fee');
    }
}

function get_table_columns(PDO $pdo, string $tableName): array
{
    $stmt = $pdo->prepare(
        'SELECT COLUMN_NAME
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name'
    );
    $stmt->execute(['table_name' => $tableName]);
    $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $map = [];
    foreach ($rows as $name) {
        $map[(string) $name] = true;
    }

    return $map;
}

function normalize_positive_int($value, ?int $default = null): ?int
{
    if ($value === null || $value === '') {
        return $default;
    }

    $n = (int) $value;
    if ($n < 0) {
        return 0;
    }

    return $n;
}

function normalize_money($value, float $default = 0.0): float
{
    if ($value === null || $value === '') {
        return $default;
    }

    return max(0.0, round((float) $value, 2));
}
