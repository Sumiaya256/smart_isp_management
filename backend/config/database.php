<?php

declare(strict_types=1);

require_once __DIR__ . '/env.php';

function db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $host = env('DB_HOST', '127.0.0.1');
    $port = env('DB_PORT', '3306');
    $name = env('DB_NAME', 'isp_management');
    $user = env('DB_USER', 'root');
    $pass = env('DB_PASSWORD', '');
    $charset = env('DB_CHARSET', 'utf8mb4');

    $hosts = array_values(array_unique(array_filter([
        $host,
        $host === '127.0.0.1' ? 'localhost' : '127.0.0.1',
        'localhost',
    ])));

    $ports = array_values(array_unique(array_filter([
        (string) $port,
        '3306',
        '3307',
    ])));

    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];

    $lastError = null;
    foreach ($hosts as $h) {
        foreach ($ports as $p) {
            try {
                $dsn = "mysql:host={$h};port={$p};dbname={$name};charset={$charset}";
                $pdo = new PDO($dsn, $user, $pass, $options);
                return $pdo;
            } catch (Throwable $e) {
                $lastError = $e;
            }
        }
    }

    $message = 'Database connection failed. Please start MySQL in XAMPP and verify DB_HOST/DB_PORT in backend/.env.';
    if ($lastError instanceof Throwable) {
        $message .= ' Last error: ' . $lastError->getMessage();
    }
    throw new RuntimeException($message);
}
