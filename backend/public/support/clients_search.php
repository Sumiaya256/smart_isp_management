<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/database.php';
require_once dirname(__DIR__, 2) . '/config/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    send_json([
        'ok' => false,
        'message' => 'Method not allowed',
    ], 405);
}

require_auth();
require_page_permission('New Ticket', 'view');

$query = trim((string) ($_GET['q'] ?? ''));

try {
    $pdo = db();

    if ($query === '' || strlen($query) < 2) {
        send_json([
            'ok' => true,
            'clients' => [],
        ]);
    }

    $stmt = $pdo->prepare(
        'SELECT c.id, c.client_code, c.full_name, c.phone,
                COALESCE(p.package_name, "N/A") AS package_name,
                COALESCE(p.speed_mbps, 0) AS speed_mbps,
                COALESCE(NULLIF(TRIM(c.status), ""), "active") AS status
         FROM clients c
         LEFT JOIN internet_packages p ON p.id = c.package_id
         WHERE COALESCE(NULLIF(LOWER(TRIM(c.status)), ""), "active") NOT IN ("inactive", "left", "disconnected", "resigned")
           AND (
                c.client_code LIKE :search_code
                OR c.full_name LIKE :search_name
                OR c.phone LIKE :search_phone
           )
         ORDER BY c.full_name ASC
         LIMIT 20'
    );

    $wild = '%' . $query . '%';
    $stmt->execute([
        'search_code' => $wild,
        'search_name' => $wild,
        'search_phone' => $wild,
    ]);

    $clients = $stmt->fetchAll();

    send_json([
        'ok' => true,
        'clients' => $clients,
    ]);
} catch (Throwable $e) {
    send_json([
        'ok' => false,
        'message' => 'Failed to search clients',
        'error' => $e->getMessage(),
    ], 500);
}
