<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/database.php';
require_once dirname(__DIR__, 2) . '/config/auth.php';
require_once __DIR__ . '/request_helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json(['ok' => false, 'message' => 'Method not allowed'], 405);
}

$user = require_page_permission('New Request', 'edit');
$input = read_json_input();

$clientName = trim((string) ($input['client_name'] ?? ''));
$phone = trim((string) ($input['phone'] ?? ''));
$email = trim((string) ($input['email'] ?? ''));
$address = trim((string) ($input['address'] ?? ''));
$packageSlug = strtolower(trim((string) ($input['package_slug'] ?? '')));
$connectionType = strtolower(trim((string) ($input['connection_type'] ?? '')));
$preferredDate = trim((string) ($input['preferred_date'] ?? ''));
$preferredTime = strtolower(trim((string) ($input['preferred_time'] ?? '')));
$notes = trim((string) ($input['notes'] ?? ''));

if ($clientName === '' || $phone === '' || $address === '' || $packageSlug === '' || $connectionType === '' || $preferredDate === '' || $preferredTime === '') {
    send_json(['ok' => false, 'message' => 'Required fields are missing'], 422);
}

$packageMap = package_catalog_map();
if (!isset($packageMap[$packageSlug])) {
    send_json(['ok' => false, 'message' => 'Invalid package selected'], 422);
}

if (!in_array($connectionType, ['fiber', 'wireless', 'copper'], true)) {
    send_json(['ok' => false, 'message' => 'Invalid connection type'], 422);
}

if (!in_array($preferredTime, ['morning', 'afternoon', 'evening'], true)) {
    send_json(['ok' => false, 'message' => 'Invalid preferred time'], 422);
}

if (!preg_match('/^\+?[0-9\-\s]{7,20}$/', $phone)) {
    send_json(['ok' => false, 'message' => 'Invalid phone number format'], 422);
}

if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    send_json(['ok' => false, 'message' => 'Invalid email address'], 422);
}

$dateObj = DateTime::createFromFormat('Y-m-d', $preferredDate);
if (!$dateObj || $dateObj->format('Y-m-d') !== $preferredDate) {
    send_json(['ok' => false, 'message' => 'Invalid preferred date format'], 422);
}

$today = new DateTime('today');
if ($dateObj < $today) {
    send_json(['ok' => false, 'message' => 'Preferred date cannot be in the past'], 422);
}

try {
    $pdo = db();
    ensure_connection_requests_table($pdo);

    $pdo->beginTransaction();

    $requestCode = generate_request_code($pdo);

    $stmt = $pdo->prepare(
        'INSERT INTO client_connection_requests
        (request_code, client_name, phone, email, address_line, package_slug, package_name, connection_type, preferred_date, preferred_time, notes, status, created_by_employee_id, assigned_to_employee_id)
        VALUES
        (:request_code, :client_name, :phone, :email, :address_line, :package_slug, :package_name, :connection_type, :preferred_date, :preferred_time, :notes, :status, :created_by_employee_id, :assigned_to_employee_id)'
    );

    $employeeId = (int) ($user['id'] ?? 0);

    $stmt->execute([
        'request_code' => $requestCode,
        'client_name' => mb_substr($clientName, 0, 150),
        'phone' => mb_substr($phone, 0, 40),
        'email' => $email !== '' ? mb_substr($email, 0, 180) : null,
        'address_line' => mb_substr($address, 0, 255),
        'package_slug' => $packageSlug,
        'package_name' => $packageMap[$packageSlug],
        'connection_type' => $connectionType,
        'preferred_date' => $preferredDate,
        'preferred_time' => $preferredTime,
        'notes' => $notes !== '' ? mb_substr($notes, 0, 5000) : null,
        'status' => 'pending',
        'created_by_employee_id' => $employeeId > 0 ? $employeeId : null,
        'assigned_to_employee_id' => $employeeId > 0 ? $employeeId : null,
    ]);

    $newId = (int) $pdo->lastInsertId();

    $pdo->commit();

    send_json([
        'ok' => true,
        'message' => 'Request submitted successfully',
        'request' => [
            'id' => $newId,
            'request_code' => $requestCode,
            'client_name' => $clientName,
            'package_name' => $packageMap[$packageSlug],
            'connection_type' => connection_type_label($connectionType),
            'preferred_time' => preferred_time_label($preferredTime),
            'status' => 'pending',
        ],
    ], 201);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    send_json(['ok' => false, 'message' => 'Failed to create request', 'error' => $e->getMessage()], 500);
}
