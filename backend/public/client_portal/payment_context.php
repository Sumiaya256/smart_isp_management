<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/database.php';
require_once dirname(__DIR__, 2) . '/config/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    send_json(['ok' => false, 'message' => 'Method not allowed'], 405);
}

$user = require_auth();
$roleName = strtolower(trim((string) ($user['role_name'] ?? '')));
if (!is_access_matrix_disabled() && $roleName !== 'client') {
    send_json(['ok' => false, 'message' => 'Forbidden: client access only'], 403);
}

try {
    $pdo = db();

    $stmt = $pdo->prepare(
        'SELECT c.id, c.client_code, c.full_name,
                COALESCE(p.monthly_price, 0) AS monthly_price,
                COALESCE(p.package_name, "N/A") AS package_name
         FROM clients c
         LEFT JOIN internet_packages p ON p.id = c.package_id
         WHERE c.id = :id
         LIMIT 1'
    );
    $stmt->execute(['id' => (int) $user['id']]);
    $client = $stmt->fetch();

    if (!$client) {
        send_json(['ok' => false, 'message' => 'Client not found'], 404);
    }

    send_json([
        'ok' => true,
        'context' => [
            'client_id' => (int) $client['id'],
            'client_code' => (string) $client['client_code'],
            'client_name' => (string) $client['full_name'],
            'package_name' => (string) $client['package_name'],
            'fixed_amount' => (float) $client['monthly_price'],
            'current_month' => date('Y-m'),
            'current_month_label' => date('F Y'),
            'receivers' => [
                'bkash' => '01756202157',
                'nagad' => '01756202157',
                'card' => '123123321312',
            ],
        ],
    ]);
} catch (Throwable $e) {
    send_json(['ok' => false, 'message' => 'Failed to load payment context', 'error' => $e->getMessage()], 500);
}
