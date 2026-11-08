<?php

declare(strict_types=1);

require_once __DIR__ . '/../backend/config/database.php';

$pdo = db();

$stmt = $pdo->query(
    "SELECT p.id, p.position_name,
            MAX(CASE WHEN pam.module_name = 'Purchase' THEN pam.permission_level END) AS purchase_level,
            MAX(CASE WHEN pam.module_name = 'Assets' THEN pam.permission_level END) AS assets_level
     FROM positions p
     LEFT JOIN position_access_modules pam ON pam.position_id = p.id
     GROUP BY p.id, p.position_name
     ORDER BY p.id ASC"
);

$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$summary = [
    'purchase' => ['full' => 0, 'view' => 0, 'limited' => 0, 'none_or_missing' => 0],
    'assets' => ['full' => 0, 'view' => 0, 'limited' => 0, 'none_or_missing' => 0],
];

foreach ($rows as $row) {
    $purchase = strtolower(trim((string) ($row['purchase_level'] ?? '')));
    $assets = strtolower(trim((string) ($row['assets_level'] ?? '')));

    if (in_array($purchase, ['full', 'view', 'limited'], true)) {
        $summary['purchase'][$purchase]++;
    } else {
        $summary['purchase']['none_or_missing']++;
    }

    if (in_array($assets, ['full', 'view', 'limited'], true)) {
        $summary['assets'][$assets]++;
    } else {
        $summary['assets']['none_or_missing']++;
    }
}

echo "=== Purchase/Assets permission by position ===\n";
foreach ($rows as $row) {
    $purchase = (string) ($row['purchase_level'] ?? 'none/missing');
    $assets = (string) ($row['assets_level'] ?? 'none/missing');
    if ($purchase === '') {
        $purchase = 'none/missing';
    }
    if ($assets === '') {
        $assets = 'none/missing';
    }
    echo sprintf("[%d] %s | Purchase=%s | Assets=%s\n", (int) $row['id'], (string) $row['position_name'], $purchase, $assets);
}

echo "\n=== Summary ===\n";
echo 'Purchase: ' . json_encode($summary['purchase'], JSON_UNESCAPED_SLASHES) . "\n";
echo 'Assets: ' . json_encode($summary['assets'], JSON_UNESCAPED_SLASHES) . "\n";
