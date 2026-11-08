<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/database.php';
require_once dirname(__DIR__, 2) . '/config/auth.php';
require_once __DIR__ . '/helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json(['ok' => false, 'message' => 'Method not allowed'], 405);
}

require_page_permission('Internet Packages', 'edit');

$input = read_json_input();
$id = (int) ($input['id'] ?? 0);
$packageName = trim((string) ($input['package_name'] ?? ''));
$tagline = trim((string) ($input['tagline'] ?? ''));
$speedMbps = normalize_positive_int($input['speed_mbps'] ?? null, 0);
$uploadMbps = normalize_positive_int($input['upload_mbps'] ?? null, null);
$monthlyPrice = normalize_money($input['monthly_price'] ?? 0, 0);
$dataLimitGb = normalize_positive_int($input['data_limit_gb'] ?? null, null);
$supportLevel = trim((string) ($input['support_level'] ?? 'Standard Support'));
$routerIncluded = !empty($input['router_included']) ? 1 : 0;
$installationFee = normalize_money($input['installation_fee'] ?? 0, 0);
$isPopular = !empty($input['is_popular']) ? 1 : 0;
$isActive = array_key_exists('is_active', $input) ? (!empty($input['is_active']) ? 1 : 0) : 1;

if ($packageName === '' || $speedMbps <= 0 || $monthlyPrice <= 0) {
    send_json(['ok' => false, 'message' => 'package_name, speed_mbps, and monthly_price are required'], 422);
}

if ($supportLevel === '') {
    $supportLevel = 'Standard Support';
}

try {
    $pdo = db();
    ensure_internet_package_columns($pdo);

    if ($id > 0) {
        $dup = $pdo->prepare(
            'SELECT id FROM internet_packages
             WHERE package_name = :package_name AND id <> :id
             LIMIT 1'
        );
        $dup->execute([
            'package_name' => $packageName,
            'id' => $id,
        ]);

        if ($dup->fetch()) {
            send_json(['ok' => false, 'message' => 'Package name already exists'], 409);
        }

        $stmt = $pdo->prepare(
            'UPDATE internet_packages
             SET package_name = :package_name,
                 tagline = :tagline,
                 speed_mbps = :speed_mbps,
                 upload_mbps = :upload_mbps,
                 monthly_price = :monthly_price,
                 data_limit_gb = :data_limit_gb,
                 support_level = :support_level,
                 router_included = :router_included,
                 installation_fee = :installation_fee,
                 is_popular = :is_popular,
                 is_active = :is_active
             WHERE id = :id'
        );
        $stmt->execute([
            'package_name' => $packageName,
            'tagline' => $tagline !== '' ? $tagline : null,
            'speed_mbps' => $speedMbps,
            'upload_mbps' => $uploadMbps,
            'monthly_price' => $monthlyPrice,
            'data_limit_gb' => $dataLimitGb,
            'support_level' => $supportLevel,
            'router_included' => $routerIncluded,
            'installation_fee' => $installationFee,
            'is_popular' => $isPopular,
            'is_active' => $isActive,
            'id' => $id,
        ]);

        send_json(['ok' => true, 'message' => 'Package updated successfully']);
    }

    $dup = $pdo->prepare(
        'SELECT id FROM internet_packages
         WHERE package_name = :package_name
         LIMIT 1'
    );
    $dup->execute(['package_name' => $packageName]);
    if ($dup->fetch()) {
        send_json(['ok' => false, 'message' => 'Package name already exists'], 409);
    }

    $stmt = $pdo->prepare(
        'INSERT INTO internet_packages
        (package_name, tagline, speed_mbps, upload_mbps, monthly_price, data_limit_gb,
         support_level, router_included, installation_fee, is_popular, is_active)
         VALUES
        (:package_name, :tagline, :speed_mbps, :upload_mbps, :monthly_price, :data_limit_gb,
         :support_level, :router_included, :installation_fee, :is_popular, :is_active)'
    );
    $stmt->execute([
        'package_name' => $packageName,
        'tagline' => $tagline !== '' ? $tagline : null,
        'speed_mbps' => $speedMbps,
        'upload_mbps' => $uploadMbps,
        'monthly_price' => $monthlyPrice,
        'data_limit_gb' => $dataLimitGb,
        'support_level' => $supportLevel,
        'router_included' => $routerIncluded,
        'installation_fee' => $installationFee,
        'is_popular' => $isPopular,
        'is_active' => $isActive,
    ]);

    send_json([
        'ok' => true,
        'message' => 'Package created successfully',
        'id' => (int) $pdo->lastInsertId(),
    ], 201);
} catch (Throwable $e) {
    send_json([
        'ok' => false,
        'message' => 'Failed to save package',
        'error' => $e->getMessage(),
    ], 500);
}
