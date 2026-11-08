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
$rows = isset($input['rows']) && is_array($input['rows']) ? $input['rows'] : [];
$replaceExisting = !empty($input['replace_existing']);

if (empty($rows)) {
    send_json(['ok' => false, 'message' => 'No rows received'], 422);
}

try {
    $pdo = db();
    ensure_mikrotik_bulk_table($pdo);
    cleanup_expired_mikrotik_rows($pdo);

    $employeeId = (int) ($user['id'] ?? 0);
    $scope = mikrotik_scope_sql($user, 'r', 'scope_employee_id');

    $pdo->beginTransaction();

    if ($replaceExisting) {
        if ($scope['sql'] !== '') {
            $deleteSql = 'DELETE FROM mikrotik_bulk_import_rows r WHERE 1 = 1' . $scope['sql'];
            $delete = $pdo->prepare($deleteSql);
            $delete->execute($scope['params']);
        } else {
            $pdo->exec('DELETE FROM mikrotik_bulk_import_rows');
        }
    }

    $batchId = 'BATCH-' . date('YmdHis') . '-' . strtoupper(bin2hex(random_bytes(3)));

    $insert = $pdo->prepare(
        'INSERT INTO mikrotik_bulk_import_rows
        (batch_id, uploaded_by_employee_id, full_name, mobile, email, national_id, address_line, zone_name,
         connection_type, server_name, protocol_type, profile_name, username, password_plain, customer_type,
         package_name, billing_status, monthly_bill, bill_month, join_date, expire_date, row_status, status_note, expires_at)
         VALUES
        (:batch_id, :uploaded_by_employee_id, :full_name, :mobile, :email, :national_id, :address_line, :zone_name,
         :connection_type, :server_name, :protocol_type, :profile_name, :username, :password_plain, :customer_type,
         :package_name, :billing_status, :monthly_bill, :bill_month, :join_date, :expire_date, "pending", NULL, DATE_ADD(NOW(), INTERVAL 1 DAY))'
    );

    $inserted = 0;
    foreach ($rows as $row) {
        $fullName = trim((string) ($row['name'] ?? $row['full_name'] ?? ''));
        $mobile = trim((string) ($row['mobile'] ?? ''));
        if ($fullName === '' || $mobile === '') {
            continue;
        }

        $insert->execute([
            'batch_id' => $batchId,
            'uploaded_by_employee_id' => $employeeId > 0 ? $employeeId : null,
            'full_name' => mb_substr($fullName, 0, 120),
            'mobile' => mb_substr($mobile, 0, 25),
            'email' => ($v = trim((string) ($row['email'] ?? ''))) !== '' ? mb_substr($v, 0, 180) : null,
            'national_id' => ($v = trim((string) ($row['national_id'] ?? ''))) !== '' ? mb_substr($v, 0, 40) : null,
            'address_line' => ($v = trim((string) ($row['address'] ?? $row['address_line'] ?? ''))) !== '' ? mb_substr($v, 0, 255) : null,
            'zone_name' => ($v = trim((string) ($row['zone'] ?? $row['zone_name'] ?? ''))) !== '' ? mb_substr($v, 0, 80) : null,
            'connection_type' => ($v = trim((string) ($row['conn_type'] ?? $row['connection_type'] ?? ''))) !== '' ? mb_substr($v, 0, 40) : null,
            'server_name' => ($v = trim((string) ($row['server'] ?? $row['server_name'] ?? ''))) !== '' ? mb_substr($v, 0, 80) : null,
            'protocol_type' => ($v = trim((string) ($row['prot_type'] ?? $row['protocol_type'] ?? ''))) !== '' ? mb_substr($v, 0, 40) : null,
            'profile_name' => ($v = trim((string) ($row['profile'] ?? $row['profile_name'] ?? ''))) !== '' ? mb_substr($v, 0, 80) : null,
            'username' => ($v = trim((string) ($row['username'] ?? ''))) !== '' ? mb_substr(strtolower($v), 0, 120) : null,
            'password_plain' => ($v = trim((string) ($row['password'] ?? $row['password_plain'] ?? ''))) !== '' ? mb_substr($v, 0, 120) : null,
            'customer_type' => ($v = trim((string) ($row['c_type'] ?? $row['customer_type'] ?? ''))) !== '' ? mb_substr($v, 0, 40) : null,
            'package_name' => ($v = trim((string) ($row['package'] ?? $row['package_name'] ?? ''))) !== '' ? mb_substr($v, 0, 100) : null,
            'billing_status' => ($v = trim((string) ($row['b_status'] ?? $row['billing_status'] ?? ''))) !== '' ? mb_substr($v, 0, 40) : null,
            'monthly_bill' => is_numeric($row['m_bill'] ?? $row['monthly_bill'] ?? null) ? (float) ($row['m_bill'] ?? $row['monthly_bill']) : null,
            'bill_month' => ($v = trim((string) ($row['bill_month'] ?? ''))) !== '' ? mb_substr($v, 0, 20) : null,
            'join_date' => ($v = trim((string) ($row['join_date'] ?? ''))) !== '' ? $v : null,
            'expire_date' => ($v = trim((string) ($row['exp_date'] ?? $row['expire_date'] ?? ''))) !== '' ? $v : null,
        ]);
        $inserted++;
    }

    $pdo->commit();

    send_json([
        'ok' => true,
        'message' => 'Bulk rows uploaded',
        'inserted' => $inserted,
        'batch_id' => $batchId,
    ], 201);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    send_json(['ok' => false, 'message' => 'Failed to upload rows', 'error' => $e->getMessage()], 500);
}
