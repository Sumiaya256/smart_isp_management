<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/database.php';
require_once dirname(__DIR__, 2) . '/config/auth.php';
require_once __DIR__ . '/scope_helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    send_json(['ok' => false, 'message' => 'Method not allowed'], 405);
}

$user = require_auth();
if (!user_has_any_page_action_access($user, ['Change Request', 'Left Client'], 'view')) {
    send_json(['ok' => false, 'message' => 'Forbidden: insufficient page permission'], 403);
}

$query = trim((string) ($_GET['q'] ?? ''));

try {
    $pdo = db();
    ensure_client_scope_columns($pdo);

    if ($query === '' || strlen($query) < 2) {
        send_json(['ok' => true, 'clients' => []]);
    }

    $scope = apply_client_scope_where($user, 'c', 'scope_employee_id');

    $stmt = $pdo->prepare(
                "SELECT c.id, c.client_code, c.full_name, c.phone, c.email,
                                COALESCE(p.package_name, 'N/A') AS package_name,
                c.status
         FROM clients c
         LEFT JOIN internet_packages p ON c.package_id = p.id
                    WHERE COALESCE(NULLIF(LOWER(TRIM(c.status)), ''), 'active') NOT IN ('inactive', 'left', 'disconnected', 'resigned')
                        AND (c.client_code LIKE :search_code OR c.full_name LIKE :search_name OR c.phone LIKE :search_phone)
                        {$scope['sql']}
                 LIMIT 15"
    );

        $searchParam = '%' . $query . '%';
        $params = [
            'search_code' => $searchParam,
            'search_name' => $searchParam,
            'search_phone' => $searchParam,
        ];
        if (!empty($scope['params'])) {
            $params = array_merge($params, $scope['params']);
        }

        $stmt->execute($params);
    $clients = $stmt->fetchAll();

    send_json([
        'ok' => true,
        'clients' => $clients,
    ]);
} catch (Throwable $e) {
    send_json(['ok' => false, 'message' => 'Failed to search clients', 'error' => $e->getMessage()], 500);
}
