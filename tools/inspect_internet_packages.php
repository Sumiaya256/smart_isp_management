<?php

declare(strict_types=1);

require __DIR__ . '/../backend/config/database.php';

$pdo = db();

$total = (int) $pdo->query('SELECT COUNT(*) FROM internet_packages')->fetchColumn();
$active = (int) $pdo->query('SELECT COUNT(*) FROM internet_packages WHERE is_active = 1')->fetchColumn();

echo 'total_packages=' . $total . PHP_EOL;
echo 'active_packages=' . $active . PHP_EOL;

$stmt = $pdo->query(
    'SELECT id, package_name, speed_mbps, monthly_price, is_active
     FROM internet_packages
     ORDER BY speed_mbps ASC, monthly_price ASC
     LIMIT 20'
);

foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    echo sprintf(
        'id=%d name=%s speed=%d price=%s active=%d',
        (int) ($row['id'] ?? 0),
        (string) ($row['package_name'] ?? ''),
        (int) ($row['speed_mbps'] ?? 0),
        (string) ($row['monthly_price'] ?? '0'),
        (int) ($row['is_active'] ?? 0)
    ) . PHP_EOL;
}
