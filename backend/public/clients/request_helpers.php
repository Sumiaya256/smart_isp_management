<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/database.php';
require_once dirname(__DIR__, 2) . '/config/auth.php';

function ensure_connection_requests_table(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS client_connection_requests (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            request_code VARCHAR(40) NOT NULL UNIQUE,
            client_name VARCHAR(150) NOT NULL,
            phone VARCHAR(40) NOT NULL,
            email VARCHAR(180) NULL,
            address_line VARCHAR(255) NOT NULL,
            package_slug VARCHAR(40) NOT NULL,
            package_name VARCHAR(120) NOT NULL,
            connection_type VARCHAR(40) NOT NULL,
            preferred_date DATE NOT NULL,
            preferred_time VARCHAR(40) NOT NULL,
            notes TEXT NULL,
            status VARCHAR(30) NOT NULL DEFAULT 'pending',
            created_by_employee_id BIGINT UNSIGNED NULL,
            assigned_to_employee_id BIGINT UNSIGNED NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_ccr_status (status),
            INDEX idx_ccr_phone (phone),
            INDEX idx_ccr_created_at (created_at),
            INDEX idx_ccr_created_by (created_by_employee_id),
            INDEX idx_ccr_assigned_to (assigned_to_employee_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

function apply_connection_request_scope_where(array $user, string $alias = 'r', string $paramName = 'scope_employee_id'): array
{
    if (!is_limited_module_permission($user, 'Client')) {
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

function normalize_request_status(string $status): string
{
    $value = strtolower(trim($status));
    if (in_array($value, ['pending', 'scheduled', 'completed', 'cancelled'], true)) {
        return $value;
    }

    return 'pending';
}

function package_slugify(string $value): string
{
    $slug = strtolower(trim($value));
    if ($slug === '') {
        return '';
    }

    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';
    $slug = trim($slug, '-');

    return $slug;
}

function default_package_catalog_map(): array
{
    return [
        'basic' => 'Basic - 5 Mbps',
        'standard' => 'Standard - 10 Mbps',
        'premium' => 'Premium - 20 Mbps',
        'ultra' => 'Ultra - 50 Mbps',
    ];
}

function package_catalog_map(): array
{
    try {
        $pdo = db();
        $stmt = $pdo->query(
            'SELECT package_name, speed_mbps
             FROM internet_packages
             WHERE is_active = 1
             ORDER BY speed_mbps ASC, package_name ASC'
        );

        $packages = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!$packages) {
            return default_package_catalog_map();
        }

        $map = [];
        foreach ($packages as $row) {
            $name = trim((string) ($row['package_name'] ?? ''));
            $speed = (int) ($row['speed_mbps'] ?? 0);
            if ($name === '' || $speed <= 0) {
                continue;
            }

            $baseSlug = package_slugify($name . '-' . $speed . '-mbps');
            if ($baseSlug === '') {
                continue;
            }

            $slug = $baseSlug;
            $i = 2;
            while (isset($map[$slug])) {
                $slug = $baseSlug . '-' . $i;
                $i++;
            }

            $map[$slug] = sprintf('%s - %d Mbps', $name, $speed);
        }

        return $map ?: default_package_catalog_map();
    } catch (Throwable $e) {
        return default_package_catalog_map();
    }
}

function connection_type_label(string $connectionType): string
{
    $value = strtolower(trim($connectionType));
    $map = [
        'fiber' => 'Fiber Optic',
        'wireless' => 'Wireless',
        'copper' => 'Copper',
    ];

    return $map[$value] ?? 'Unknown';
}

function preferred_time_label(string $preferredTime): string
{
    $value = strtolower(trim($preferredTime));
    $map = [
        'morning' => 'Morning (9AM - 12PM)',
        'afternoon' => 'Afternoon (12PM - 4PM)',
        'evening' => 'Evening (4PM - 7PM)',
    ];

    return $map[$value] ?? 'N/A';
}

function generate_request_code(PDO $pdo): string
{
    $ym = date('Ym');

    $stmt = $pdo->prepare(
        'SELECT request_code
         FROM client_connection_requests
         WHERE request_code LIKE :prefix
         ORDER BY id DESC
         LIMIT 1'
    );
    $stmt->execute(['prefix' => 'NRQ-' . $ym . '-%']);
    $last = (string) ($stmt->fetchColumn() ?: '');

    $next = 1;
    if ($last !== '') {
        $parts = explode('-', $last);
        if (count($parts) === 3) {
            $next = max(1, ((int) $parts[2]) + 1);
        }
    }

    return sprintf('NRQ-%s-%04d', $ym, $next);
}
