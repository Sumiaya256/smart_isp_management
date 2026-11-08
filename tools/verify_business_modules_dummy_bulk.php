<?php

declare(strict_types=1);

require_once __DIR__ . '/../backend/config/database.php';

$pdo = db();

$counts = [
    'inventory_items_bulk' => 'SELECT COUNT(*) FROM inventory_items WHERE item_code LIKE "ITM-BULK-%"',
    'inventory_movements_bulk' => 'SELECT COUNT(*) FROM inventory_movements WHERE reference_label LIKE "BULK-OPEN-%"',
    'assets_items_bulk' => 'SELECT COUNT(*) FROM assets_items WHERE asset_tag LIKE "AST-BULK-%"',
    'income_entries_bulk' => 'SELECT COUNT(*) FROM income_entries WHERE invoice_no LIKE "INV-BULK-%"',
    'purchase_orders_bulk' => 'SELECT COUNT(*) FROM purchase_orders WHERE po_number LIKE "PO-BULK-%"',
    'purchase_order_items_bulk' => 'SELECT COUNT(*) FROM purchase_order_items poi INNER JOIN purchase_orders po ON po.id = poi.purchase_order_id WHERE po.po_number LIKE "PO-BULK-%"',
    'hr_payheads_bulk' => 'SELECT COUNT(*) FROM hr_payheads WHERE payhead_code LIKE "PH_BULK_%"',
    'hr_resignation_rules_bulk' => 'SELECT COUNT(*) FROM hr_resignation_rules WHERE rule_name LIKE "Dummy Bulk Resignation Rule %"',
];

foreach ($counts as $label => $sql) {
    $count = (int) $pdo->query($sql)->fetchColumn();
    echo str_pad($label, 28, ' ') . ': ' . $count . PHP_EOL;
}
