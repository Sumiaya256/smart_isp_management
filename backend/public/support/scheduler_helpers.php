<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/database.php';
require_once dirname(__DIR__, 2) . '/config/auth.php';
require_once dirname(__DIR__, 1) . '/clients/request_helpers.php';

function ensure_support_appointments_table(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS support_appointments (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            appointment_code VARCHAR(40) NOT NULL UNIQUE,
            request_id BIGINT UNSIGNED NULL,
            request_code VARCHAR(40) NULL,
            client_name VARCHAR(150) NOT NULL,
            client_phone VARCHAR(40) NOT NULL,
            client_address VARCHAR(255) NOT NULL,
            appointment_type VARCHAR(50) NOT NULL,
            appointment_date DATE NOT NULL,
            appointment_time TIME NOT NULL,
            technician_employee_id BIGINT UNSIGNED NOT NULL,
            technician_name VARCHAR(150) NOT NULL,
            priority VARCHAR(20) NOT NULL DEFAULT 'normal',
            status VARCHAR(20) NOT NULL DEFAULT 'scheduled',
            notes TEXT NULL,
            created_by_employee_id BIGINT UNSIGNED NULL,
            assigned_to_employee_id BIGINT UNSIGNED NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_sa_date (appointment_date),
            INDEX idx_sa_status (status),
            INDEX idx_sa_technician (technician_employee_id),
            INDEX idx_sa_request (request_id),
            INDEX idx_sa_scope_created (created_by_employee_id),
            INDEX idx_sa_scope_assigned (assigned_to_employee_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

function normalize_appointment_type(string $type): string
{
    $value = strtolower(trim($type));
    $allowed = ['installation', 'maintenance', 'troubleshooting', 'upgrade', 'disconnection'];
    if (in_array($value, $allowed, true)) {
        return $value;
    }

    return 'installation';
}

function normalize_priority_level(string $priority): string
{
    $value = strtolower(trim($priority));
    $allowed = ['normal', 'urgent', 'emergency'];
    if (in_array($value, $allowed, true)) {
        return $value;
    }

    return 'normal';
}

function normalize_appointment_status(string $status): string
{
    $value = strtolower(trim($status));
    $allowed = ['pending', 'scheduled', 'in_progress', 'completed', 'cancelled'];
    if (in_array($value, $allowed, true)) {
        return $value;
    }

    return 'scheduled';
}

function to_time_string(string $value): ?string
{
    $value = trim($value);
    if ($value === '') {
        return null;
    }

    $dt = DateTime::createFromFormat('H:i', $value);
    if ($dt instanceof DateTime) {
        return $dt->format('H:i:s');
    }

    $dt2 = DateTime::createFromFormat('H:i:s', $value);
    if ($dt2 instanceof DateTime) {
        return $dt2->format('H:i:s');
    }

    return null;
}

function apply_scheduler_scope_where(array $user, string $alias = 'sa', string $paramName = 'scope_employee_id'): array
{
    if (!is_limited_module_permission($user, 'Support & Ticketing')) {
        return ['sql' => '', 'params' => []];
    }

    $employeeId = (int) ($user['id'] ?? 0);
    if ($employeeId <= 0) {
        send_json(['ok' => false, 'message' => 'Forbidden: invalid employee scope'], 403);
    }

    return [
        'sql' => " AND ({$alias}.created_by_employee_id = :{$paramName} OR {$alias}.assigned_to_employee_id = :{$paramName})",
        'params' => [$paramName => $employeeId],
    ];
}

function scheduler_month_range(string $month): array
{
    $month = trim($month);
    if (!preg_match('/^\\d{4}-\\d{2}$/', $month)) {
        $month = date('Y-m');
    }

    $start = DateTime::createFromFormat('Y-m-d', $month . '-01');
    if (!$start) {
        $start = new DateTime(date('Y-m-01'));
    }

    $end = clone $start;
    $end->modify('last day of this month');

    return [
        'month' => $start->format('Y-m'),
        'start' => $start->format('Y-m-d'),
        'end' => $end->format('Y-m-d'),
    ];
}

function generate_appointment_code(PDO $pdo): string
{
    $ym = date('Ym');
    $stmt = $pdo->prepare(
        'SELECT appointment_code
         FROM support_appointments
         WHERE appointment_code LIKE :prefix
         ORDER BY id DESC
         LIMIT 1'
    );
    $stmt->execute(['prefix' => 'APT-' . $ym . '-%']);
    $last = (string) ($stmt->fetchColumn() ?: '');

    $next = 1;
    if ($last !== '') {
        $parts = explode('-', $last);
        if (count($parts) === 3) {
            $next = max(1, ((int) $parts[2]) + 1);
        }
    }

    return sprintf('APT-%s-%04d', $ym, $next);
}

function fetch_pending_requests_for_scheduler(PDO $pdo, array $user, int $limit = 120): array
{
    ensure_connection_requests_table($pdo);

    $sql =
        'SELECT r.id, r.request_code, r.client_name, r.phone, r.address_line, r.package_name,
                r.connection_type, r.preferred_date, r.preferred_time, r.status, r.created_at
         FROM client_connection_requests r
         WHERE 1 = 1';

    $params = [];

    if (is_limited_module_permission($user, 'Support & Ticketing')) {
        $employeeId = (int) ($user['id'] ?? 0);
        $sql .= ' AND (r.created_by_employee_id = :req_scope_employee_id OR r.assigned_to_employee_id = :req_scope_employee_id)';
        $params['req_scope_employee_id'] = $employeeId;
    }

    $sql .= ' ORDER BY r.id DESC LIMIT ' . (int) max(20, min(300, $limit));

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}
