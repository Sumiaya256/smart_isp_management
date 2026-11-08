<?php
header('Content-Type: application/json');
require_once '../../config/database.php';
require_once '../../config/auth.php';
require_once '../../config/response.php';

// Require authentication
require_auth();

$query = $_GET['q'] ?? '';

if (strlen($query) < 2) {
    send_json(['ok' => false, 'error' => 'Query must be at least 2 characters']);
    exit;
}

try {
    $db = db();

    $stmt = $db->prepare("
        SELECT 
            c.id,
            c.client_code,
            c.full_name,
            c.phone,
            c.email,
            c.address_line,
            c.ward,
            c.zone_name,
            c.status,
            ip.package_name,
            ip.speed_mbps,
            MAX(i.status) as billing_status,
            MAX(i.invoice_no) as latest_invoice_no,
            MAX(i.due_date) as latest_due_date
        FROM clients c
        LEFT JOIN internet_packages ip ON c.package_id = ip.id
        LEFT JOIN invoices i ON c.id = i.client_id
        WHERE (c.client_code LIKE ? 
           OR c.full_name LIKE ? 
           OR c.phone LIKE ? 
           OR c.email LIKE ?)
        AND c.status = 'active'
        GROUP BY c.id
        LIMIT 20
    ");

    $searchParam = '%' . $query . '%';
    $stmt->execute([$searchParam, $searchParam, $searchParam, $searchParam]);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

    send_json([
        'ok' => true,
        'results' => $results,
        'count' => count($results)
    ]);
} catch (Exception $e) {
    http_response_code(500);
    send_json(['ok' => false, 'error' => 'Database error: ' . $e->getMessage()]);
}
