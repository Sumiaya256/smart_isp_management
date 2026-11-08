<?php

declare(strict_types=1);

require __DIR__ . '/../backend/config/database.php';

$pdo = db();

function pick_assignee_ids(PDO $pdo): array
{
    $rows = $pdo->query('SELECT id FROM employees ORDER BY id ASC LIMIT 3')->fetchAll(PDO::FETCH_ASSOC);
    $ids = array_map(static fn(array $r): int => (int) $r['id'], $rows);
    if (empty($ids)) {
        return [null, null, null];
    }
    while (count($ids) < 3) {
        $ids[] = $ids[count($ids) - 1];
    }
    return $ids;
}

function ensure_packages(PDO $pdo): array
{
    $existing = $pdo->query('SELECT id, package_name FROM internet_packages ORDER BY id ASC')->fetchAll(PDO::FETCH_ASSOC);
    if (!empty($existing)) {
        return $existing;
    }

    $seed = [
        ['Starter 10 Mbps', 10, 1000],
        ['Standard 20 Mbps', 20, 1500],
        ['Premium 40 Mbps', 40, 2200],
    ];

    $stmt = $pdo->prepare(
        'INSERT INTO internet_packages (package_name, speed_mbps, monthly_price, is_active)
         VALUES (:package_name, :speed_mbps, :monthly_price, 1)'
    );

    foreach ($seed as $row) {
        $stmt->execute([
            'package_name' => $row[0],
            'speed_mbps' => $row[1],
            'monthly_price' => $row[2],
        ]);
    }

    return $pdo->query('SELECT id, package_name FROM internet_packages ORDER BY id ASC')->fetchAll(PDO::FETCH_ASSOC);
}

$clients = [
    ['Rahim Uddin', '01710000001', 'uttara', 'road-01', 'ward-1', 'zone-a'],
    ['Karim Hossain', '01710000002', 'mirpur', 'road-02', 'ward-2', 'zone-b'],
    ['Nadia Akter', '01710000003', 'banani', 'road-03', 'ward-3', 'zone-c'],
    ['Sadia Sultana', '01710000004', 'mohakhali', 'road-04', 'ward-4', 'zone-a'],
    ['Jahid Hasan', '01710000005', 'rampura', 'road-05', 'ward-5', 'zone-b'],
    ['Mim Islam', '01710000006', 'bashundhara', 'road-06', 'ward-6', 'zone-c'],
    ['Tanvir Ahmed', '01710000007', 'dhanmondi', 'road-07', 'ward-7', 'zone-a'],
    ['Ritu Akter', '01710000008', 'jatrabari', 'road-08', 'ward-8', 'zone-b'],
    ['Sabbir Khan', '01710000009', 'wari', 'road-09', 'ward-9', 'zone-c'],
    ['Sharmin Nahar', '01710000010', 'shyamoli', 'road-10', 'ward-10', 'zone-a'],
];

$passwordPlain = '123456';
$passwordHash = password_hash($passwordPlain, PASSWORD_DEFAULT);

try {
    $pdo->beginTransaction();

    $packageRows = ensure_packages($pdo);
    $packageIds = array_map(static fn(array $r): int => (int) $r['id'], $packageRows);

    $assignees = pick_assignee_ids($pdo);
    $creatorId = $assignees[0];

    $insert = $pdo->prepare(
        'INSERT INTO clients
         (client_code, full_name, address_line, road_no, ward, zone_name, phone, email,
          connection_username, connection_email, connection_password_hash, package_id,
          created_by_employee_id, assigned_to_employee_id, connection_start_date, payment_cycle,
          status, onu_mac, router_ip, nid, birth_date, connection_type, referral_name,
          emergency_contact, notes, payment_cycle_date)
         VALUES
         (:client_code, :full_name, :address_line, :road_no, :ward, :zone_name, :phone, :email,
          :connection_username, :connection_email, :connection_password_hash, :package_id,
          :created_by_employee_id, :assigned_to_employee_id, :connection_start_date, :payment_cycle,
          :status, :onu_mac, :router_ip, :nid, :birth_date, :connection_type, :referral_name,
          :emergency_contact, :notes, :payment_cycle_date)'
    );

    $reportRows = [];
    foreach ($clients as $i => $c) {
        $n = $i + 1;
        $clientCode = 'CL-' . str_pad((string) $n, 4, '0', STR_PAD_LEFT);
        $emailLocal = 'client' . str_pad((string) $n, 2, '0', STR_PAD_LEFT);
        $clientMail = $emailLocal . '@example.test';
        $connectionMail = $emailLocal . '@client.test';
        $connectionUser = 'user' . str_pad((string) $n, 2, '0', STR_PAD_LEFT);
        $startDate = date('Y-m-d', strtotime('-' . (10 - $n) . ' days'));
        $packageId = $packageIds[$i % count($packageIds)];
        $assigned = $assignees[$i % count($assignees)];

        $insert->execute([
            'client_code' => $clientCode,
            'full_name' => $c[0],
            'address_line' => ucfirst($c[1]) . ', ' . ucfirst($c[2]) . ', Dhaka',
            'road_no' => strtoupper($c[3]),
            'ward' => strtoupper($c[4]),
            'zone_name' => strtoupper($c[5]),
            'phone' => $c[1],
            'email' => $clientMail,
            'connection_username' => $connectionUser,
            'connection_email' => $connectionMail,
            'connection_password_hash' => $passwordHash,
            'package_id' => $packageId,
            'created_by_employee_id' => $creatorId,
            'assigned_to_employee_id' => $assigned,
            'connection_start_date' => $startDate,
            'payment_cycle' => 'monthly',
            'status' => 'active',
            'onu_mac' => 'AA:BB:CC:DD:EE:' . str_pad(dechex($n), 2, '0', STR_PAD_LEFT),
            'router_ip' => '192.168.1.' . (100 + $n),
            'nid' => '19999' . str_pad((string) $n, 8, '0', STR_PAD_LEFT),
            'birth_date' => '1996-01-' . str_pad((string) $n, 2, '0', STR_PAD_LEFT),
            'connection_type' => 'Home',
            'referral_name' => 'Referral ' . $n,
            'emergency_contact' => '0189000' . str_pad((string) $n, 4, '0', STR_PAD_LEFT),
            'notes' => 'Dummy seeded client for testing',
            'payment_cycle_date' => (($n - 1) % 28) + 1,
        ]);

        $pkgName = '';
        foreach ($packageRows as $pr) {
            if ((int) $pr['id'] === $packageId) {
                $pkgName = (string) $pr['package_name'];
                break;
            }
        }

        $reportRows[] = [
            'Client Code' => $clientCode,
            'Client Name' => $c[0],
            'Phone' => $c[1],
            'Client Email' => $clientMail,
            'Connection Email' => $connectionMail,
            'Connection Username' => $connectionUser,
            'Connection Password' => $passwordPlain,
            'Package' => $pkgName,
            'Status' => 'active',
            'Payment Cycle' => 'monthly',
            'Connection Start Date' => $startDate,
        ];
    }

    $pdo->commit();

    $csvPath = dirname(__DIR__) . '/client_test_credentials.csv';
    $fp = fopen($csvPath, 'wb');
    if ($fp === false) {
        throw new RuntimeException('Unable to create CSV file.');
    }

    if (!empty($reportRows)) {
        fputcsv($fp, array_keys($reportRows[0]));
        foreach ($reportRows as $row) {
            fputcsv($fp, array_values($row));
        }
    }
    fclose($fp);

    echo 'Inserted clients: ' . count($reportRows) . PHP_EOL;
    echo 'CSV export: ' . $csvPath . PHP_EOL;
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, 'Error: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
