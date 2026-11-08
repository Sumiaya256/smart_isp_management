<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/database.php';
require_once dirname(__DIR__, 2) . '/config/auth.php';
require_once __DIR__ . '/scope_helpers.php';
require_once __DIR__ . '/request_helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json(['ok' => false, 'message' => 'Method not allowed'], 405);
}

$user = require_page_permission('Add New Client', 'view');

$input = read_json_input();

$firstName = trim((string) ($input['first_name'] ?? ''));
$lastName = trim((string) ($input['last_name'] ?? ''));
$phone = trim((string) ($input['phone'] ?? ''));
$email = trim((string) ($input['email'] ?? ''));
$nid = trim((string) ($input['nid'] ?? ''));
$birthDate = trim((string) ($input['birth_date'] ?? ''));
$connectionUsername = trim((string) ($input['connection_username'] ?? ''));
$connectionEmailInput = trim((string) ($input['connection_email'] ?? ''));
$connectionPassword = (string) ($input['connection_password'] ?? '');
$address = trim((string) ($input['address'] ?? ''));
$area = trim((string) ($input['area'] ?? ''));
$city = trim((string) ($input['city'] ?? ''));
$postalCode = trim((string) ($input['postal_code'] ?? ''));
$packageSlug = trim((string) ($input['package_slug'] ?? ''));
$installationDate = trim((string) ($input['installation_date'] ?? ''));
$connectionType = trim((string) ($input['connection_type'] ?? ''));
$onuMac = trim((string) ($input['onu_mac'] ?? ''));
$routerIp = trim((string) ($input['router_ip'] ?? ''));
$paymentCycleDate = trim((string) ($input['payment_cycle_date'] ?? ''));
$referralName = trim((string) ($input['referral_name'] ?? ''));
$emergencyContact = trim((string) ($input['emergency_contact'] ?? ''));
$notes = trim((string) ($input['notes'] ?? ''));

if (
    $firstName === '' ||
    $lastName === '' ||
    $phone === '' ||
    $connectionUsername === '' ||
    $connectionPassword === '' ||
    $address === '' ||
    $area === '' ||
    $city === '' ||
    $packageSlug === '' ||
    $installationDate === ''
) {
    send_json(['ok' => false, 'message' => 'Required fields are missing'], 422);
}

if (!preg_match('/^[a-zA-Z0-9._-]+$/', $connectionUsername)) {
    send_json(['ok' => false, 'message' => 'Connection username can only contain letters, numbers, dot, dash, and underscore'], 422);
}

$connectionUsername = strtolower($connectionUsername);
$connectionEmail = $connectionUsername . '@client.promee.internet';

if ($connectionEmailInput !== '' && strcasecmp($connectionEmailInput, $connectionEmail) !== 0) {
    send_json(['ok' => false, 'message' => 'Connection mail must follow the fixed domain format'], 422);
}

try {
    $pdo = db();
    ensure_client_connection_columns($pdo);
    ensure_client_scope_columns($pdo);

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

    $clientCode = generate_client_code($pdo);

    $usernameCheck = $pdo->prepare('SELECT COUNT(*) FROM clients WHERE connection_username = :connection_username');
    $usernameCheck->execute(['connection_username' => $connectionUsername]);
    if ((int) $usernameCheck->fetchColumn() > 0) {
        send_json(['ok' => false, 'message' => 'Connection username already exists'], 409);
    }

    $mailCheck = $pdo->prepare('SELECT COUNT(*) FROM clients WHERE connection_email = :connection_email');
    $mailCheck->execute(['connection_email' => $connectionEmail]);
    if ((int) $mailCheck->fetchColumn() > 0) {
        send_json(['ok' => false, 'message' => 'Connection mail already exists'], 409);
    }

    $fullName = trim($firstName . ' ' . $lastName);
    $roadNo = $postalCode !== '' ? $postalCode : null;
    $passwordHash = password_hash($connectionPassword, PASSWORD_DEFAULT);

    $insertClient = $pdo->prepare(
        'INSERT INTO clients
        (client_code, full_name, address_line, road_no, ward, zone_name, phone, email, package_id, created_by_employee_id, assigned_to_employee_id, connection_start_date, payment_cycle, status, connection_username, connection_email, connection_password_hash, nid, birth_date, connection_type, onu_mac, router_ip, payment_cycle_date, referral_name, emergency_contact, notes)
        VALUES
        (:client_code, :full_name, :address_line, :road_no, :ward, :zone_name, :phone, :email, :package_id, :created_by_employee_id, :assigned_to_employee_id, :connection_start_date, :payment_cycle, :status, :connection_username, :connection_email, :connection_password_hash, :nid, :birth_date, :connection_type, :onu_mac, :router_ip, :payment_cycle_date, :referral_name, :emergency_contact, :notes)'
    );

    $insertClient->execute([
        'client_code' => $clientCode,
        'full_name' => $fullName,
        'address_line' => $address,
        'road_no' => $roadNo,
        'ward' => $area,
        'zone_name' => $city,
        'phone' => $phone,
        'email' => $email !== '' ? $email : null,
        'package_id' => $packageId,
        'created_by_employee_id' => (int) ($user['id'] ?? 0) ?: null,
        'assigned_to_employee_id' => (int) ($user['id'] ?? 0) ?: null,
        'connection_start_date' => $installationDate,
        'payment_cycle' => 'monthly',
        'status' => 'active',
        'connection_username' => $connectionUsername,
        'connection_email' => $connectionEmail,
        'connection_password_hash' => $passwordHash,
        'nid' => $nid !== '' ? $nid : null,
        'birth_date' => $birthDate !== '' ? $birthDate : null,
        'connection_type' => $connectionType !== '' ? $connectionType : null,
        'onu_mac' => $onuMac !== '' ? $onuMac : null,
        'router_ip' => $routerIp !== '' ? $routerIp : null,
        'payment_cycle_date' => $paymentCycleDate !== '' ? (int)$paymentCycleDate : null,
        'referral_name' => $referralName !== '' ? $referralName : null,
        'emergency_contact' => $emergencyContact !== '' ? $emergencyContact : null,
        'notes' => $notes !== '' ? $notes : null,
    ]);

    $clientId = (int) $pdo->lastInsertId();

    $pdo->commit();

    send_json([
        'ok' => true,
        'message' => 'Client created successfully',
        'client' => [
            'id' => $clientId,
            'client_code' => $clientCode,
            'full_name' => $fullName,
        ],
    ], 201);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    send_json(['ok' => false, 'message' => 'Failed to create client', 'error' => $e->getMessage()], 500);
}

function ensure_client_connection_columns(PDO $pdo): void
{
    $columnStmt = $pdo->prepare(
        'SELECT COLUMN_NAME
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name'
    );
    $columnStmt->execute(['table_name' => 'clients']);
    $columns = $columnStmt->fetchAll(PDO::FETCH_COLUMN);
    $columnLookup = array_fill_keys(array_map('strval', $columns), true);

    if (!isset($columnLookup['connection_username'])) {
        $pdo->exec('ALTER TABLE clients ADD COLUMN connection_username VARCHAR(120) NULL AFTER email');
    }

    if (!isset($columnLookup['connection_email'])) {
        $pdo->exec('ALTER TABLE clients ADD COLUMN connection_email VARCHAR(180) NULL AFTER connection_username');
    }

    if (!isset($columnLookup['connection_password_hash'])) {
        $pdo->exec('ALTER TABLE clients ADD COLUMN connection_password_hash VARCHAR(255) NULL AFTER connection_email');
    }

    if (isset($columnLookup['client_password_hash'])) {
        $pdo->exec('ALTER TABLE clients DROP COLUMN client_password_hash');
    }
}

function generate_client_code(PDO $pdo): string
{
    $prefix = 'pic2026';

    $stmt = $pdo->prepare(
        "SELECT client_code
         FROM clients
         WHERE client_code REGEXP '^pic2026[0-9]{3}$'
         ORDER BY CAST(RIGHT(client_code, 3) AS UNSIGNED) DESC
         LIMIT 1"
    );
    $stmt->execute();
    $lastCode = (string) ($stmt->fetchColumn() ?: '');

    $nextNumber = 1;
    if ($lastCode !== '') {
        $suffix = (int) substr($lastCode, -3);
        $nextNumber = $suffix + 1;
    }

    do {
        $candidate = $prefix . str_pad((string) $nextNumber, 3, '0', STR_PAD_LEFT);
        $check = $pdo->prepare('SELECT COUNT(*) FROM clients WHERE client_code = :client_code');
        $check->execute(['client_code' => $candidate]);
        $exists = (int) $check->fetchColumn() > 0;
        $nextNumber++;
    } while ($exists);

    return $candidate;
}
