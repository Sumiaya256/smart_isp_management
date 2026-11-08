<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/database.php';
require_once dirname(__DIR__, 2) . '/config/auth.php';
require_once __DIR__ . '/request_helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    send_json(['ok' => false, 'message' => 'Method not allowed'], 405);
}

require_auth();

try {
    $pdo = db();
    $stmt = $pdo->query(
        'SELECT id, package_name, speed_mbps, monthly_price
         FROM internet_packages
         WHERE is_active = 1
         ORDER BY speed_mbps ASC, monthly_price ASC'
    );

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $packages = [];
    foreach ($rows as $row) {
        $name = trim((string) ($row['package_name'] ?? ''));
        $speed = (int) ($row['speed_mbps'] ?? 0);
        if ($name === '' || $speed <= 0) {
            continue;
        }

        $packages[] = [
            'id' => (int) ($row['id'] ?? 0),
            'package_name' => $name,
            'speed_mbps' => $speed,
            'monthly_price' => (float) ($row['monthly_price'] ?? 0),
            'package_slug' => package_slugify($name . '-' . $speed . '-mbps'),
            'package_label' => sprintf('%s - %d Mbps', $name, $speed),
        ];
    }

    send_json([
        'ok' => true,
        'packages' => $packages,
    ]);
} catch (Throwable $e) {
    send_json(['ok' => false, 'message' => 'Failed to fetch packages', 'error' => $e->getMessage()], 500);
}
