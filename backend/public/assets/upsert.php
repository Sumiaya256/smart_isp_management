<?php

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json(['ok' => false, 'message' => 'Method not allowed'], 405);
}

$user = require_auth();
require_module_permission('Assets', 'edit', true);

$input = read_json_input();
$id = (int) ($input['id'] ?? 0);
$assetName = trim((string) ($input['asset_name'] ?? ''));
$assetTag = trim((string) ($input['asset_tag'] ?? ''));
$typeName = trim((string) ($input['type_name'] ?? ''));
$purchaseDate = trim((string) ($input['purchase_date'] ?? ''));
$purchaseValue = max(0, (float) ($input['purchase_value'] ?? 0));
$assignedToName = trim((string) ($input['assigned_to_name'] ?? ''));
$statusLabel = trim((string) ($input['status_label'] ?? 'active'));
$notes = trim((string) ($input['notes'] ?? ''));

if ($assetName === '' || $assetTag === '') {
    send_json(['ok' => false, 'message' => 'asset_name and asset_tag are required'], 422);
}

$allowed = ['active', 'assigned', 'repair', 'spare', 'retired'];
if (!in_array($statusLabel, $allowed, true)) {
    $statusLabel = 'active';
}

try {
    $pdo = db();
    ensure_assets_schema($pdo);

    $employeeId = (int) ($user['id'] ?? 0);

    if ($id > 0) {
        enforce_assets_scope($pdo, $user, $id);

        $stmt = $pdo->prepare(
            'UPDATE assets_items
             SET asset_tag = :asset_tag,
                 asset_name = :asset_name,
                 type_name = :type_name,
                 purchase_date = :purchase_date,
                 purchase_value = :purchase_value,
                 assigned_to_name = :assigned_to_name,
                 status_label = :status_label,
                 notes = :notes,
                 assigned_to_employee_id = :assigned_to_employee_id
             WHERE id = :id'
        );
        $stmt->execute([
            'asset_tag' => mb_substr($assetTag, 0, 60),
            'asset_name' => mb_substr($assetName, 0, 180),
            'type_name' => $typeName !== '' ? mb_substr($typeName, 0, 80) : null,
            'purchase_date' => $purchaseDate !== '' ? $purchaseDate : null,
            'purchase_value' => $purchaseValue,
            'assigned_to_name' => $assignedToName !== '' ? mb_substr($assignedToName, 0, 140) : null,
            'status_label' => $statusLabel,
            'notes' => $notes !== '' ? $notes : null,
            'assigned_to_employee_id' => $employeeId > 0 ? $employeeId : null,
            'id' => $id,
        ]);
    } else {
        $stmt = $pdo->prepare(
            'INSERT INTO assets_items (
                asset_tag, asset_name, type_name, purchase_date, purchase_value,
                assigned_to_name, status_label, notes, created_by_employee_id, assigned_to_employee_id
             ) VALUES (
                :asset_tag, :asset_name, :type_name, :purchase_date, :purchase_value,
                :assigned_to_name, :status_label, :notes, :created_by_employee_id, :assigned_to_employee_id
             )'
        );
        $stmt->execute([
            'asset_tag' => mb_substr($assetTag, 0, 60),
            'asset_name' => mb_substr($assetName, 0, 180),
            'type_name' => $typeName !== '' ? mb_substr($typeName, 0, 80) : null,
            'purchase_date' => $purchaseDate !== '' ? $purchaseDate : null,
            'purchase_value' => $purchaseValue,
            'assigned_to_name' => $assignedToName !== '' ? mb_substr($assignedToName, 0, 140) : null,
            'status_label' => $statusLabel,
            'notes' => $notes !== '' ? $notes : null,
            'created_by_employee_id' => $employeeId > 0 ? $employeeId : null,
            'assigned_to_employee_id' => $employeeId > 0 ? $employeeId : null,
        ]);
        $id = (int) $pdo->lastInsertId();
    }

    send_json(['ok' => true, 'id' => $id, 'message' => 'Asset saved']);
} catch (Throwable $e) {
    send_json(['ok' => false, 'message' => 'Failed to save asset', 'error' => $e->getMessage()], 500);
}
