<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/database.php';
require_once dirname(__DIR__, 2) . '/config/auth.php';

function ensure_left_clients_table(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS left_clients (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            client_id BIGINT UNSIGNED NOT NULL UNIQUE,
            original_client_code VARCHAR(60) NOT NULL,
            termination_date DATE NOT NULL,
            termination_reason VARCHAR(40) NOT NULL,
            pending_dues DECIMAL(12,2) NOT NULL DEFAULT 0,
            equipment_status VARCHAR(40) NOT NULL,
            final_reading VARCHAR(120) NULL,
            notes TEXT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_left_clients_term_date (termination_date),
            INDEX idx_left_clients_reason (termination_reason),
            INDEX idx_left_clients_equipment (equipment_status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

function normalize_termination_reason(string $reason): string
{
    $value = strtolower(trim($reason));
    if (in_array($value, ['relocation', 'service', 'price', 'competition', 'other'], true)) {
        return $value;
    }

    return 'other';
}

function normalize_equipment_status(string $status): string
{
    $value = strtolower(trim($status));
    if (in_array($value, ['returned', 'partial', 'not-returned'], true)) {
        return $value;
    }

    return 'not-returned';
}
