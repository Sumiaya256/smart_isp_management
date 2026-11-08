<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once dirname(__DIR__) . '/config/database.php';

try {
    $pdo = db();
    $stmt = $pdo->query('SELECT DATABASE() AS db_name, NOW() AS server_time');
    $row = $stmt->fetch();

    echo json_encode([
        'ok' => true,
        'message' => 'Database connection successful',
        'database' => $row['db_name'] ?? null,
        'server_time' => $row['server_time'] ?? null,
    ], JSON_PRETTY_PRINT);
} catch (Throwable $e) {
    http_response_code(500);

    echo json_encode([
        'ok' => false,
        'message' => 'Database connection failed',
        'error' => $e->getMessage(),
    ], JSON_PRETTY_PRINT);
}
