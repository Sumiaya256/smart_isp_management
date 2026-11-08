<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/database.php';
require_once dirname(__DIR__, 2) . '/config/auth.php';
require_once __DIR__ . '/scope_helpers.php';
require_once __DIR__ . '/request_helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    send_json(['ok' => false, 'message' => 'Method not allowed'], 405);
}

$sessionUser = require_auth();
$roleName = strtolower(trim((string) ($sessionUser['role_name'] ?? '')));

if ($roleName === 'client') {
    $user = $sessionUser;
} else {
    $user = require_page_permission('Client List', 'edit');
    $roleName = strtolower(trim((string) ($user['role_name'] ?? '')));
}

$clientId = $roleName === 'client'
    ? (int) ($user['id'] ?? 0)
    : (int) ($_GET['id'] ?? 0);

if ($clientId <= 0) {
    send_json(['ok' => false, 'message' => 'Invalid client ID'], 400);
}

try {
    $pdo = db();
    ensure_client_scope_columns($pdo);

    if ($roleName !== 'client') {
        enforce_client_scope_for_client_id($pdo, $user, $clientId);
    }

    $stmt = $pdo->prepare(
        'SELECT c.id, c.client_code, c.full_name, c.address_line, c.road_no, c.ward, c.zone_name,
                c.phone, c.email, c.package_id, c.connection_start_date, c.payment_cycle, c.status,
                c.connection_username, c.connection_email, c.onu_mac, c.router_ip, c.nid, c.birth_date,
                c.connection_type, c.left_date, c.left_reason, c.payment_cycle_date, c.referral_name,
                  c.emergency_contact, c.notes,
                  COALESCE(p.package_name, "") AS package_name,
                COALESCE(p.speed_mbps, 0) AS speed_mbps,
                COALESCE(p.monthly_price, 0) AS monthly_price
         FROM clients c
         LEFT JOIN internet_packages p ON p.id = c.package_id
         WHERE c.id = :id
         LIMIT 1'
    );

    $stmt->execute(['id' => $clientId]);
    $client = $stmt->fetch();

    if (!$client) {
        send_json(['ok' => false, 'message' => 'Client not found'], 404);
    }

    $packageSlug = package_slugify(
        trim((string) ($client['package_name'] ?? '')) . '-' .
        (int) ($client['speed_mbps'] ?? 0) . '-mbps'
    );

    $name = (string) ($client['full_name'] ?? '');
    $nameParts = explode(' ', $name, 2);

    send_json([
        'ok' => true,
        'client' => [
            'id' => (int) $client['id'],
            'client_code' => (string) $client['client_code'],
            'first_name' => (string) ($nameParts[0] ?? ''),
            'last_name' => (string) ($nameParts[1] ?? ''),
            'phone' => (string) $client['phone'],
            'email' => (string) ($client['email'] ?: ''),
            'address' => (string) $client['address_line'],
            'area' => (string) $client['ward'],
            'city' => (string) $client['zone_name'],
            'postal_code' => (string) ($client['road_no'] ?: ''),
            'nid' => (string) ($client['nid'] ?: ''),
            'birth_date' => (string) ($client['birth_date'] ?: ''),
            'package_slug' => $packageSlug,
            'package_name' => (string) (($client['package_name'] ?? '') !== '' ? $client['package_name'] : 'N/A'),
            'speed_mbps' => (int) ($client['speed_mbps'] ?: 0),
            'monthly_price' => (float) ($client['monthly_price'] ?: 0),
            'installation_date' => (string) $client['connection_start_date'],
            'payment_cycle_date' => (int) ($client['payment_cycle_date'] ?: 0),
            'connection_type' => (string) ($client['connection_type'] ?: ''),
            'connection_username' => (string) $client['connection_username'],
            'connection_email' => (string) $client['connection_email'],
            'onu_mac' => (string) ($client['onu_mac'] ?: ''),
            'router_ip' => (string) ($client['router_ip'] ?: ''),
            'referral_name' => (string) ($client['referral_name'] ?: ''),
            'emergency_contact' => (string) ($client['emergency_contact'] ?: ''),
            'notes' => (string) ($client['notes'] ?: ''),
            'status' => (string) $client['status'],
        ],
    ]);
} catch (Throwable $e) {
    send_json(['ok' => false, 'message' => 'Failed to fetch client', 'error' => $e->getMessage()], 500);
}
