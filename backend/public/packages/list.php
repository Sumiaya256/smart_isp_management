<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/database.php';
require_once dirname(__DIR__, 2) . '/config/auth.php';
require_once __DIR__ . '/helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    send_json(['ok' => false, 'message' => 'Method not allowed'], 405);
}

require_page_permission('Internet Packages', 'view');

try {
    $pdo = db();
    ensure_internet_package_columns($pdo);

    $stmt = $pdo->query(
        'SELECT id, package_name, tagline, speed_mbps, upload_mbps, monthly_price, data_limit_gb,
                support_level, router_included, installation_fee, is_popular, is_active, created_at
         FROM internet_packages
         ORDER BY is_popular DESC, speed_mbps ASC, monthly_price ASC, package_name ASC'
    );

    send_json([
        'ok' => true,
        'packages' => $stmt->fetchAll(),
    ]);
} catch (Throwable $e) {
    send_json([
        'ok' => false,
        'message' => 'Failed to fetch internet packages',
        'error' => $e->getMessage(),
    ], 500);
}
