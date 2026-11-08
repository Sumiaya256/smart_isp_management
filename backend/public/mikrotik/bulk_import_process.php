<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/database.php';
require_once dirname(__DIR__, 2) . '/config/auth.php';
require_once __DIR__ . '/helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json(['ok' => false, 'message' => 'Method not allowed'], 405);
}

$user = require_page_permission('Bulk Client Import', 'edit');
$input = read_json_input();
$ids = isset($input['row_ids']) && is_array($input['row_ids']) ? $input['row_ids'] : [];

try {
    $pdo = db();
    ensure_mikrotik_bulk_table($pdo);
    ensure_client_bulk_columns($pdo);
    cleanup_expired_mikrotik_rows($pdo);

    $sql =
        'SELECT r.*
         FROM mikrotik_bulk_import_rows r
         WHERE r.row_status = "pending"';
    $params = [];

    if (!empty($ids)) {
        $placeholders = [];
        foreach ($ids as $idx => $id) {
            $key = 'id_' . $idx;
            $placeholders[] = ':' . $key;
            $params[$key] = (int) $id;
        }
        $sql .= ' AND r.id IN (' . implode(', ', $placeholders) . ')';
    }

    $scope = mikrotik_scope_sql($user, 'r', 'scope_employee_id');
    $sql .= $scope['sql'];
    $params = array_merge($params, $scope['params']);
    $sql .= ' ORDER BY r.id ASC LIMIT 500';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    if (empty($rows)) {
        send_json(['ok' => true, 'message' => 'No pending rows to process', 'processed' => 0, 'failed' => 0]);
    }

    $processed = 0;
    $failed = 0;

    $updateResult = $pdo->prepare(
        'UPDATE mikrotik_bulk_import_rows
         SET row_status = :row_status,
             status_note = :status_note,
             linked_client_id = :linked_client_id
         WHERE id = :id'
    );

    foreach ($rows as $row) {
        $rowId = (int) $row['id'];

        try {
            $fullName = trim((string) ($row['full_name'] ?? ''));
            $mobile = trim((string) ($row['mobile'] ?? ''));
            if ($fullName === '' || $mobile === '') {
                throw new RuntimeException('Missing required name/mobile');
            }

            $username = strtolower(trim((string) ($row['username'] ?? '')));
            $password = trim((string) ($row['password_plain'] ?? ''));
            if ($username === '' || $password === '') {
                throw new RuntimeException('Missing username/password');
            }

            $uCheck = $pdo->prepare('SELECT COUNT(*) FROM clients WHERE connection_username = :username');
            $uCheck->execute(['username' => $username]);
            if ((int) $uCheck->fetchColumn() > 0) {
                throw new RuntimeException('Username already exists');
            }

            $connectionEmail = $username . '@client.promee.internet';
            $eCheck = $pdo->prepare('SELECT COUNT(*) FROM clients WHERE connection_email = :email');
            $eCheck->execute(['email' => $connectionEmail]);
            if ((int) $eCheck->fetchColumn() > 0) {
                throw new RuntimeException('Connection email already exists');
            }

            $packageId = resolve_or_create_package($pdo, (string) ($row['package_name'] ?? ''), (float) ($row['monthly_bill'] ?? 0));
            $clientCode = generate_bulk_client_code($pdo);

            $joinDate = trim((string) ($row['join_date'] ?? ''));
            if ($joinDate === '') {
                $joinDate = date('Y-m-d');
            }

            $insert = $pdo->prepare(
                'INSERT INTO clients
                (client_code, full_name, address_line, road_no, ward, zone_name, phone, email, package_id,
                 created_by_employee_id, assigned_to_employee_id, connection_start_date, payment_cycle, status,
                 connection_username, connection_email, connection_password_hash, nid, connection_type, notes)
                 VALUES
                (:client_code, :full_name, :address_line, :road_no, :ward, :zone_name, :phone, :email, :package_id,
                 :created_by_employee_id, :assigned_to_employee_id, :connection_start_date, :payment_cycle, :status,
                 :connection_username, :connection_email, :connection_password_hash, :nid, :connection_type, :notes)'
            );

            $notePieces = [];
            if (!empty($row['server_name'])) {
                $notePieces[] = 'Mikrotik Server: ' . (string) $row['server_name'];
            }
            if (!empty($row['protocol_type'])) {
                $notePieces[] = 'Protocol: ' . (string) $row['protocol_type'];
            }
            if (!empty($row['profile_name'])) {
                $notePieces[] = 'Profile: ' . (string) $row['profile_name'];
            }

            $insert->execute([
                'client_code' => $clientCode,
                'full_name' => $fullName,
                'address_line' => trim((string) ($row['address_line'] ?? '')) !== '' ? (string) $row['address_line'] : 'N/A',
                'road_no' => null,
                'ward' => null,
                'zone_name' => trim((string) ($row['zone_name'] ?? '')),
                'phone' => $mobile,
                'email' => trim((string) ($row['email'] ?? '')) !== '' ? (string) $row['email'] : null,
                'package_id' => $packageId,
                'created_by_employee_id' => (int) ($user['id'] ?? 0) ?: null,
                'assigned_to_employee_id' => (int) ($user['id'] ?? 0) ?: null,
                'connection_start_date' => $joinDate,
                'payment_cycle' => 'monthly',
                'status' => 'active',
                'connection_username' => $username,
                'connection_email' => $connectionEmail,
                'connection_password_hash' => password_hash($password, PASSWORD_DEFAULT),
                'nid' => trim((string) ($row['national_id'] ?? '')) !== '' ? (string) $row['national_id'] : null,
                'connection_type' => trim((string) ($row['connection_type'] ?? '')) !== '' ? (string) $row['connection_type'] : null,
                'notes' => !empty($notePieces) ? implode(' | ', $notePieces) : null,
            ]);

            $clientId = (int) $pdo->lastInsertId();

            $updateResult->execute([
                'row_status' => 'imported',
                'status_note' => 'Imported to clients table',
                'linked_client_id' => $clientId,
                'id' => $rowId,
            ]);

            $processed++;
        } catch (Throwable $rowError) {
            $updateResult->execute([
                'row_status' => 'error',
                'status_note' => mb_substr($rowError->getMessage(), 0, 255),
                'linked_client_id' => null,
                'id' => $rowId,
            ]);
            $failed++;
        }
    }

    send_json([
        'ok' => true,
        'message' => 'Bulk processing completed',
        'processed' => $processed,
        'failed' => $failed,
    ]);
} catch (Throwable $e) {
    send_json(['ok' => false, 'message' => 'Failed to process bulk import', 'error' => $e->getMessage()], 500);
}
