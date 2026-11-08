<?php

declare(strict_types=1);

require __DIR__ . '/../backend/config/database.php';

$pdo = db();
$stmt = $pdo->query(
    "SELECT COLUMN_NAME, IS_NULLABLE, COLUMN_DEFAULT, COLUMN_TYPE
     FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payments'
     ORDER BY ORDINAL_POSITION"
);
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    echo sprintf(
        "%s nullable=%s default=%s type=%s",
        (string) $row['COLUMN_NAME'],
        (string) $row['IS_NULLABLE'],
        $row['COLUMN_DEFAULT'] === null ? 'NULL' : (string) $row['COLUMN_DEFAULT'],
        (string) $row['COLUMN_TYPE']
    ) . PHP_EOL;
}
