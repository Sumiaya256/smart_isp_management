<?php

declare(strict_types=1);

require_once __DIR__ . '/../backend/config/database.php';

$pdo = db();
$queries = [
    'inventory_items' => 'SELECT item_code,item_name,current_stock,unit_cost FROM inventory_items WHERE item_code LIKE "ITM-DMY-%" ORDER BY id DESC LIMIT 2',
    'assets_items' => 'SELECT asset_tag,asset_name,status_label,purchase_value FROM assets_items WHERE asset_tag LIKE "AST-DMY-%" ORDER BY id DESC LIMIT 2',
    'income_entries' => 'SELECT invoice_no,client_name,income_type,amount,paid_amount,status_label FROM income_entries WHERE invoice_no LIKE "INV-DMY-INC-%" ORDER BY id DESC LIMIT 2',
    'purchase_orders' => 'SELECT po_number,vendor_name,status_label,total_amount FROM purchase_orders WHERE po_number LIKE "PO-DMY-%" ORDER BY id DESC LIMIT 2',
    'hr_payheads' => 'SELECT payhead_code,payhead_name,payhead_type,calculation_type,status_label FROM hr_payheads WHERE payhead_code LIKE "PH_DMY_%" ORDER BY id DESC LIMIT 2',
    'hr_resignation_rules' => 'SELECT rule_name,department_name,employee_type,notice_period_days,status_label FROM hr_resignation_rules WHERE rule_name LIKE "Dummy %" ORDER BY id DESC LIMIT 2',
];

foreach ($queries as $label => $sql) {
    echo PHP_EOL . '[' . $label . ']' . PHP_EOL;
    $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $row) {
        echo json_encode($row, JSON_UNESCAPED_SLASHES) . PHP_EOL;
    }
}
