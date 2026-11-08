<?php

declare(strict_types=1);

require __DIR__ . '/../backend/config/database.php';
require __DIR__ . '/../backend/public/billing/helpers.php';
require __DIR__ . '/../backend/public/income/helpers.php';

$pdo = db();

echo "-- ensuring schemas --\n";
ensure_billing_tables($pdo);
ensure_income_schema($pdo);

echo "-- latest invoice --\n";
$invoice = $pdo->query('SELECT id, invoice_no, client_id, amount, status, due_date FROM invoices ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
if (!$invoice) {
    echo "no_invoice\n";
} else {
    echo 'invoice_id=' . (int) $invoice['id'] . PHP_EOL;
    echo 'invoice_no=' . (string) $invoice['invoice_no'] . PHP_EOL;
    echo 'client_id=' . (int) $invoice['client_id'] . PHP_EOL;
    echo 'amount=' . (string) $invoice['amount'] . PHP_EOL;
    echo 'status=' . (string) $invoice['status'] . PHP_EOL;
    echo 'due_date=' . (string) $invoice['due_date'] . PHP_EOL;
}

echo "-- income columns --\n";
$cols = $pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'income_entries' ORDER BY ORDINAL_POSITION")->fetchAll(PDO::FETCH_COLUMN);
foreach ($cols as $col) {
    echo $col . PHP_EOL;
}

echo "-- payments columns --\n";
$payCols = $pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payments' ORDER BY ORDINAL_POSITION")->fetchAll(PDO::FETCH_COLUMN);
foreach ($payCols as $col) {
    echo $col . PHP_EOL;
}

echo "-- client_portal_payments columns --\n";
$portalCols = $pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'client_portal_payments' ORDER BY ORDINAL_POSITION")->fetchAll(PDO::FETCH_COLUMN);
foreach ($portalCols as $col) {
    echo $col . PHP_EOL;
}
