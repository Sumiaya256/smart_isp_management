<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/database.php';
require_once dirname(__DIR__, 2) . '/config/auth.php';
require_once dirname(__DIR__) . '/clients/scope_helpers.php';
require_once dirname(__DIR__) . '/client_portal/payment_helpers.php';

function ensure_billing_tables(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS invoices (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            invoice_no VARCHAR(80) NOT NULL UNIQUE,
            client_id BIGINT UNSIGNED NOT NULL,
            billing_month DATE NOT NULL,
            due_date DATE NOT NULL,
            amount DECIMAL(12,2) NOT NULL,
            status VARCHAR(30) NOT NULL DEFAULT "unpaid",
            generated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            paid_at DATETIME NULL,
            INDEX idx_inv_client_id (client_id),
            INDEX idx_inv_status (status),
            INDEX idx_inv_month (billing_month),
            CONSTRAINT fk_inv_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
        ) ENGINE=InnoDB'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS payments (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            invoice_id BIGINT UNSIGNED NOT NULL,
            client_id BIGINT UNSIGNED NOT NULL,
            amount DECIMAL(12,2) NOT NULL,
            method VARCHAR(30) NOT NULL,
            transaction_ref VARCHAR(120) NULL,
            collected_by BIGINT UNSIGNED NULL,
            source_portal_payment_id BIGINT UNSIGNED NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_pay_invoice (invoice_id),
            INDEX idx_pay_client (client_id),
            INDEX idx_pay_source_portal (source_portal_payment_id),
            CONSTRAINT fk_pay_invoice FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE,
            CONSTRAINT fk_pay_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
        ) ENGINE=InnoDB'
    );

    ensure_client_portal_payments_table($pdo);
}

function billing_client_scope_guard(PDO $pdo, array $user, int $clientId): void
{
    if (is_limited_module_permission($user, 'Billing')) {
        enforce_client_scope_for_client_id($pdo, $user, $clientId);
    }
}
