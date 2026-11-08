<?php

declare(strict_types=1);

function ensure_client_portal_payments_table(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS client_portal_payments (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            client_id BIGINT UNSIGNED NOT NULL,
            payslip_no VARCHAR(40) NOT NULL UNIQUE,
            payment_method VARCHAR(20) NOT NULL,
            receiver_account VARCHAR(40) NOT NULL,
            payer_reference VARCHAR(120) NULL,
            billing_month CHAR(7) NOT NULL,
            amount DECIMAL(12,2) NOT NULL,
            status VARCHAR(40) NOT NULL DEFAULT "pending_confirmation",
            message VARCHAR(255) NOT NULL,
            invoice_id BIGINT UNSIGNED NULL,
            approved_by_employee_id BIGINT UNSIGNED NULL,
            approved_at DATETIME NULL,
            invoice_generated_at DATETIME NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_cpp_client_id (client_id),
            INDEX idx_cpp_status (status),
            INDEX idx_cpp_invoice_id (invoice_id),
            CONSTRAINT fk_cpp_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
        ) ENGINE=InnoDB'
    );

    ensure_cpp_column($pdo, 'invoice_id', 'BIGINT UNSIGNED NULL AFTER message');
    ensure_cpp_column($pdo, 'approved_by_employee_id', 'BIGINT UNSIGNED NULL AFTER invoice_id');
    ensure_cpp_column($pdo, 'approved_at', 'DATETIME NULL AFTER approved_by_employee_id');
    ensure_cpp_column($pdo, 'invoice_generated_at', 'DATETIME NULL AFTER approved_at');
}

function ensure_cpp_column(PDO $pdo, string $columnName, string $definition): void
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*)
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name AND COLUMN_NAME = :column_name'
    );
    $stmt->execute([
        'table_name' => 'client_portal_payments',
        'column_name' => $columnName,
    ]);

    if ((int) $stmt->fetchColumn() === 0) {
        $pdo->exec('ALTER TABLE client_portal_payments ADD COLUMN ' . $columnName . ' ' . $definition);
    }
}
