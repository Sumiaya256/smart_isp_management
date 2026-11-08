<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/database.php';
require_once dirname(__DIR__, 2) . '/config/auth.php';
require_once __DIR__ . '/scope_helpers.php';

function ensure_client_service_requests_table(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS client_service_requests (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            request_code VARCHAR(40) NOT NULL UNIQUE,
            client_id BIGINT UNSIGNED NOT NULL,
            request_kind VARCHAR(30) NOT NULL,
            request_type VARCHAR(60) NOT NULL,
            current_value VARCHAR(255) NULL,
            new_value VARCHAR(255) NULL,
            effective_date DATE NULL,
            priority VARCHAR(20) NOT NULL DEFAULT 'normal',
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            termination_reason VARCHAR(40) NULL,
            reason TEXT NOT NULL,
            notes TEXT NULL,
            requested_by_client TINYINT(1) NOT NULL DEFAULT 1,
            created_by_employee_id BIGINT UNSIGNED NULL,
            updated_by_employee_id BIGINT UNSIGNED NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_csr_client (client_id),
            INDEX idx_csr_kind (request_kind),
            INDEX idx_csr_status (status),
            INDEX idx_csr_effective_date (effective_date),
            INDEX idx_csr_created_by (created_by_employee_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

function normalize_service_request_kind(string $kind): string
{
    $value = strtolower(trim($kind));
    if (in_array($value, ['change', 'close_connection'], true)) {
        return $value;
    }
    return 'change';
}

function normalize_service_request_type(string $type, string $kind): string
{
    $value = strtolower(trim($type));

    if ($kind === 'close_connection') {
        return 'close_connection';
    }

    $allowed = ['package_change', 'address_change', 'contact_update', 'equipment_upgrade', 'billing_change', 'temporary_suspension', 'other'];
    if (in_array($value, $allowed, true)) {
        return $value;
    }

    return 'other';
}

function normalize_service_request_priority(string $priority): string
{
    $value = strtolower(trim($priority));
    if (in_array($value, ['normal', 'urgent', 'emergency'], true)) {
        return $value;
    }
    return 'normal';
}

function normalize_service_request_status(string $status): string
{
    $value = strtolower(trim($status));
    if (in_array($value, ['pending', 'in_progress', 'scheduled', 'completed', 'cancelled', 'rejected'], true)) {
        return $value;
    }
    return 'pending';
}

function normalize_close_reason(string $reason): string
{
    $value = strtolower(trim($reason));
    if (in_array($value, ['relocation', 'service', 'price', 'competition', 'other'], true)) {
        return $value;
    }
    return 'other';
}

function generate_service_request_code(PDO $pdo): string
{
    $ym = date('Ym');
    $stmt = $pdo->prepare(
        'SELECT request_code
         FROM client_service_requests
         WHERE request_code LIKE :prefix
         ORDER BY id DESC
         LIMIT 1'
    );
    $stmt->execute(['prefix' => 'CSR-' . $ym . '-%']);
    $last = (string) ($stmt->fetchColumn() ?: '');

    $next = 1;
    if ($last !== '') {
        $parts = explode('-', $last);
        if (count($parts) === 3) {
            $next = max(1, ((int) $parts[2]) + 1);
        }
    }

    return sprintf('CSR-%s-%04d', $ym, $next);
}

function enforce_service_request_scope_for_id(PDO $pdo, array $user, int $serviceRequestId): void
{
    if ($serviceRequestId <= 0) {
        send_json(['ok' => false, 'message' => 'Invalid service request ID'], 400);
    }

    if (!is_limited_module_permission($user, 'Client')) {
        return;
    }

    $employeeId = (int) ($user['id'] ?? 0);
    if ($employeeId <= 0) {
        send_json(['ok' => false, 'message' => 'Forbidden: invalid employee scope'], 403);
    }

    ensure_client_scope_columns($pdo);

    $stmt = $pdo->prepare(
        'SELECT csr.id
         FROM client_service_requests csr
         INNER JOIN clients c ON c.id = csr.client_id
         WHERE csr.id = :id
           AND (c.created_by_employee_id = :employee_id OR c.assigned_to_employee_id = :employee_id)
         LIMIT 1'
    );
    $stmt->execute([
        'id' => $serviceRequestId,
        'employee_id' => $employeeId,
    ]);

    if (!$stmt->fetch()) {
        send_json(['ok' => false, 'message' => 'Forbidden: limited access allows only own/assigned clients'], 403);
    }
}
