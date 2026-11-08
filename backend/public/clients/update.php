<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/database.php';
require_once dirname(__DIR__, 2) . '/config/auth.php';
require_once __DIR__ . '/scope_helpers.php';
require_once __DIR__ . '/request_helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json(['ok' => false, 'message' => 'Method not allowed'], 405);
}

$user = require_page_permission('Client List', 'edit');
$roleName = strtolower(trim((string) ($user['role_name'] ?? '')));

$input = read_json_input();

$clientId = $roleName === 'client'
    ? (int) ($user['id'] ?? 0)
    : (int) ($input['id'] ?? 0);
$firstName = trim((string) ($input['first_name'] ?? ''));
$lastName = trim((string) ($input['last_name'] ?? ''));
$phone = trim((string) ($input['phone'] ?? ''));
$email = trim((string) ($input['email'] ?? ''));
$address = trim((string) ($input['address'] ?? ''));
$area = trim((string) ($input['area'] ?? ''));
$city = trim((string) ($input['city'] ?? ''));
$postalCode = trim((string) ($input['postal_code'] ?? ''));
$nid = trim((string) ($input['nid'] ?? ''));
$birthDate = trim((string) ($input['birth_date'] ?? ''));
$packageSlug = trim((string) ($input['package_slug'] ?? ''));
$installationDate = trim((string) ($input['installation_date'] ?? ''));
$connectionType = trim((string) ($input['connection_type'] ?? ''));
$onuMac = trim((string) ($input['onu_mac'] ?? ''));
$routerIp = trim((string) ($input['router_ip'] ?? ''));
$paymentCycleDate = trim((string) ($input['payment_cycle_date'] ?? ''));
$referralName = trim((string) ($input['referral_name'] ?? ''));
$emergencyContact = trim((string) ($input['emergency_contact'] ?? ''));
$notes = trim((string) ($input['notes'] ?? ''));
$connectionPassword = (string) ($input['connection_password'] ?? '');

if ($clientId <= 0) {
    send_json(['ok' => false, 'message' => 'Invalid client ID'], 400);
}

if (
    $firstName === '' ||
    $lastName === '' ||
    $phone === '' ||
    $address === '' ||
    $area === '' ||
    $city === '' ||
    $packageSlug === '' ||
    $installationDate === ''
) {
    send_json(['ok' => false, 'message' => 'Required fields are missing'], 422);
}

try {
    $pdo = db();
    ensure_client_scope_columns($pdo);

    if ($roleName !== 'client') {
        enforce_client_scope_for_client_id($pdo, $user, $clientId);
    }

    $pdo->beginTransaction();

    $pkgRows = $pdo->query(
        'SELECT id, package_name, speed_mbps
         FROM internet_packages
         WHERE is_active = 1'
    )->fetchAll(PDO::FETCH_ASSOC);

    $packageId = 0;
    foreach ($pkgRows as $row) {
        $slug = package_slugify(
            trim((string) ($row['package_name'] ?? '')) . '-' .
            (int) ($row['speed_mbps'] ?? 0) . '-mbps'
        );
        if ($slug === $packageSlug) {
            $packageId = (int) ($row['id'] ?? 0);
            break;
        }
    }

    if ($packageId <= 0) {
        send_json(['ok' => false, 'message' => 'Invalid package'], 422);
    }

    $fullName = trim($firstName . ' ' . $lastName);
    $roadNo = $postalCode !== '' ? $postalCode : null;

    $passwordHashClause = '';
    $passwordParams = [];
    if ($connectionPassword !== '') {
        $passwordHashClause = ', connection_password_hash = :connection_password_hash';
        $passwordParams['connection_password_hash'] = password_hash($connectionPassword, PASSWORD_DEFAULT);
    }

    $updateClient = $pdo->prepare(
        'UPDATE clients SET
            full_name = :full_name,
            address_line = :address_line,
            road_no = :road_no,
            ward = :area,
            zone_name = :city,
            phone = :phone,
            email = :email,
            package_id = :package_id,
            connection_start_date = :connection_start_date,
            payment_cycle = :payment_cycle,
            nid = :nid,
            birth_date = :birth_date,
            connection_type = :connection_type,
            onu_mac = :onu_mac,
            router_ip = :router_ip,
            payment_cycle_date = :payment_cycle_date,
            referral_name = :referral_name,
            emergency_contact = :emergency_contact,
            notes = :notes
            ' . $passwordHashClause . '
         WHERE id = :id'
    );

    $params = [
        'full_name' => $fullName,
        'address_line' => $address,
        'road_no' => $roadNo,
        'area' => $area,
        'city' => $city,
        'phone' => $phone,
        'email' => $email !== '' ? $email : null,
        'package_id' => $packageId,
        'connection_start_date' => $installationDate,
        'payment_cycle' => 'monthly',
        'nid' => $nid !== '' ? $nid : null,
        'birth_date' => $birthDate !== '' ? $birthDate : null,
        'connection_type' => $connectionType !== '' ? $connectionType : null,
        'onu_mac' => $onuMac !== '' ? $onuMac : null,
        'router_ip' => $routerIp !== '' ? $routerIp : null,
        'payment_cycle_date' => $paymentCycleDate !== '' ? (int)$paymentCycleDate : null,
        'referral_name' => $referralName !== '' ? $referralName : null,
        'emergency_contact' => $emergencyContact !== '' ? $emergencyContact : null,
        'notes' => $notes !== '' ? $notes : null,
        'id' => $clientId,
    ];

    $params = array_merge($params, $passwordParams);

    $updateClient->execute($params);

    $pdo->commit();

    send_json([
        'ok' => true,
        'message' => 'Client updated successfully',
    ]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    send_json(['ok' => false, 'message' => 'Failed to update client', 'error' => $e->getMessage()], 500);
}
