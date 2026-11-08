<?php
header('Content-Type: application/json');
require_once '../../config/database.php';
require_once '../../config/auth.php';
require_once '../../config/response.php';

// Require dashboard module view permission (works with position matrix roles).
require_module_permission('Dashboard', 'view');

function scalar_count(PDO $db, string $sql): int
{
    $row = $db->query($sql)->fetch(PDO::FETCH_ASSOC);
    return (int) ($row['count'] ?? 0);
}

function scalar_total(PDO $db, string $sql): float
{
    $row = $db->query($sql)->fetch(PDO::FETCH_ASSOC);
    return (float) ($row['total'] ?? 0);
}

try {
    $db = db();

    // Total active clients
    $totalClients = scalar_count($db, "SELECT COUNT(*) as count FROM clients WHERE status = 'active'");

    // New Clients (joined in last 30 days)
    $newClients = scalar_count($db, "
        SELECT COUNT(*) as count FROM clients 
        WHERE status = 'active' 
        AND connection_start_date >= DATE_SUB(NOW(), INTERVAL 30 DAY)
    ");

    // Paid Clients (have at least one paid invoice)
    $paidClients = scalar_count($db, "
        SELECT COUNT(DISTINCT c.id) as count 
        FROM clients c
        INNER JOIN invoices i ON c.id = i.client_id
        WHERE c.status = 'active'
        AND i.status = 'paid'
    ");

    // Unpaid Clients (have unpaid or partial invoices)
    $unpaidClients = scalar_count($db, "
        SELECT COUNT(DISTINCT c.id) as count 
        FROM clients c
        INNER JOIN invoices i ON c.id = i.client_id
        WHERE c.status = 'active'
        AND (i.status = 'unpaid' OR i.status = 'partial' OR i.status = 'overdue')
    ");

    // Bill Expiry Notifications (invoices due within 7 days)
    $stmt = $db->query("
        SELECT c.id, c.client_code, c.full_name, i.invoice_no, i.due_date, i.amount
        FROM clients c
        INNER JOIN invoices i ON c.id = i.client_id
        WHERE c.status = 'active'
        AND i.status IN ('unpaid', 'partial', 'overdue')
        AND i.due_date <= DATE_ADD(NOW(), INTERVAL 7 DAY)
        AND i.due_date >= NOW()
        ORDER BY i.due_date ASC
        LIMIT 10
    ");
    $expiryNotifications = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Left clients use current schema value: disconnected.
    $leftClients = scalar_count($db, "SELECT COUNT(*) as count FROM clients WHERE status = 'disconnected'");

    // Additional stats for dashboard
    $totalInvoices = scalar_count($db, "SELECT COUNT(*) as count FROM invoices");

    // Revenue in last 30 days to match the card label.
    $totalRevenue = scalar_total($db, "
        SELECT COALESCE(SUM(amount), 0) as total
        FROM payments
        WHERE payment_date >= DATE_SUB(NOW(), INTERVAL 30 DAY)
    ");

    send_json([
        'ok' => true,
        'stats' => [
            'totalClients' => (int)$totalClients,
            'newClients' => (int)$newClients,
            'paidClients' => (int)$paidClients,
            'unpaidClients' => (int)$unpaidClients,
            'leftClients' => (int)$leftClients,
            'totalInvoices' => (int)$totalInvoices,
            'totalRevenue' => (float)$totalRevenue
        ],
        'billExpiryNotifications' => $expiryNotifications
    ]);
} catch (Exception $e) {
    http_response_code(500);
    send_json(['ok' => false, 'error' => 'Database error: ' . $e->getMessage()]);
}
