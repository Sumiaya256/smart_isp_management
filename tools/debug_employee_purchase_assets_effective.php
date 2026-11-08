<?php

declare(strict_types=1);

require_once __DIR__ . '/../backend/config/database.php';

$pdo = db();

$sql = "SELECT e.id, e.employee_code, e.full_name, p.position_name, ep.access_modules
        FROM employees e
        LEFT JOIN positions p ON p.id = e.position_id
        LEFT JOIN employee_profiles ep ON ep.employee_id = e.id
        ORDER BY e.id ASC";

$rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

$summary = [
    'purchase' => ['full' => 0, 'view' => 0, 'limited' => 0, 'none_or_missing' => 0],
    'assets' => ['full' => 0, 'view' => 0, 'limited' => 0, 'none_or_missing' => 0],
];

function norm(?string $v): string {
    $s = strtolower(trim((string) $v));
    return in_array($s, ['full','view','limited','none'], true) ? $s : 'none';
}

echo "=== Employee profile access map (Purchase/Assets) ===\n";
foreach ($rows as $row) {
    $raw = (string) ($row['access_modules'] ?? '');
    $decoded = json_decode($raw, true);
    $purchase = 'none';
    $assets = 'none';

    if (is_array($decoded) && array_values($decoded) !== $decoded) {
        $purchase = norm(isset($decoded['Purchase']) ? (string) $decoded['Purchase'] : 'none');
        $assets = norm(isset($decoded['Assets']) ? (string) $decoded['Assets'] : 'none');
    }

    if ($purchase === 'none') {
        $summary['purchase']['none_or_missing']++;
    } else {
        $summary['purchase'][$purchase]++;
    }

    if ($assets === 'none') {
        $summary['assets']['none_or_missing']++;
    } else {
        $summary['assets'][$assets]++;
    }

    echo sprintf(
        "[%d] %s | %s | Purchase=%s | Assets=%s\n",
        (int) $row['id'],
        (string) $row['employee_code'],
        (string) $row['full_name'],
        $purchase,
        $assets
    );
}

echo "\n=== Summary ===\n";
echo 'Purchase: ' . json_encode($summary['purchase'], JSON_UNESCAPED_SLASHES) . "\n";
echo 'Assets: ' . json_encode($summary['assets'], JSON_UNESCAPED_SLASHES) . "\n";
