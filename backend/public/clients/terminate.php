<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/database.php';
require_once dirname(__DIR__, 2) . '/config/auth.php';
require_once __DIR__ . '/scope_helpers.php';
require_once __DIR__ . '/left_helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json(['ok' => false, 'message' => 'Method not allowed'], 405);
}

$user = require_page_permission('Left Client', 'edit');

$input = read_json_input();

$clientId = (int) ($input['client_id'] ?? 0);
$terminationDate = trim((string) ($input['termination_date'] ?? ''));
$terminationReason = normalize_termination_reason((string) ($input['termination_reason'] ?? ''));
$pendingDues = (float) ($input['pending_dues'] ?? 0);
$equipmentStatus = normalize_equipment_status((string) ($input['equipment_status'] ?? ''));
$finalReading = trim((string) ($input['final_reading'] ?? ''));
$notes = trim((string) ($input['notes'] ?? ''));

if ($clientId <= 0 || $terminationDate === '') {
    send_json(['ok' => false, 'message' => 'Required fields are missing'], 422);
}

try {
    $pdo = db();
    ensure_client_scope_columns($pdo);
    ensure_left_clients_table($pdo);
    enforce_client_scope_for_client_id($pdo, $user, $clientId);

    // Verify client exists
    $clientCheck = $pdo->prepare('SELECT id, client_code FROM clients WHERE id = :id LIMIT 1');
    $clientCheck->execute(['id' => $clientId]);
    $client = $clientCheck->fetch();

    if (!$client) {
        send_json(['ok' => false, 'message' => 'Client not found'], 404);
    }

    // Check if client already terminated
    $leftCheck = $pdo->prepare('SELECT id FROM left_clients WHERE client_id = :client_id LIMIT 1');
    $leftCheck->execute(['client_id' => $clientId]);
    if ($leftCheck->fetch()) {
        send_json(['ok' => false, 'message' => 'Client is already marked as left'], 409);
    }

    $pdo->beginTransaction();

    // Store in left_clients table
    $insertLeft = $pdo->prepare(
        'INSERT INTO left_clients (client_id, original_client_code, termination_date, termination_reason, pending_dues, equipment_status, final_reading, notes)
         VALUES (:client_id, :original_client_code, :termination_date, :termination_reason, :pending_dues, :equipment_status, :final_reading, :notes)'
    );

    $insertLeft->execute([
        'client_id' => $clientId,
        'original_client_code' => $client['client_code'],
        'termination_date' => $terminationDate,
        'termination_reason' => $terminationReason,
        'pending_dues' => $pendingDues,
        'equipment_status' => $equipmentStatus,
        'final_reading' => $finalReading !== '' ? $finalReading : null,
        'notes' => $notes !== '' ? $notes : null,
    ]);

    // Update clients table to mark as left
    $updateClient = $pdo->prepare(
        'UPDATE clients SET left_date = :left_date, left_reason = :left_reason, status = :status WHERE id = :id'
    );

    $updateClient->execute([
        'left_date' => $terminationDate,
        'left_reason' => $terminationReason,
        'status' => 'disconnected',
        'id' => $clientId,
    ]);

    $pdo->commit();

    send_json([
        'ok' => true,
        'message' => 'Client termination processed successfully',
        'left_client_id' => $pdo->lastInsertId(),
    ], 201);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    send_json(['ok' => false, 'message' => 'Failed to process termination', 'error' => $e->getMessage()], 500);
}
