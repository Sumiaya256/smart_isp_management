<?php

declare(strict_types=1);

require_once __DIR__ . '/../backend/config/database.php';

$pdo = db();
$total = (int) $pdo->query('SELECT COUNT(*) FROM events_holidays')->fetchColumn();
$holidays = (int) $pdo->query("SELECT COUNT(*) FROM events_holidays WHERE event_type = 'holiday'")->fetchColumn();
$events = (int) $pdo->query("SELECT COUNT(*) FROM events_holidays WHERE event_type = 'event'")->fetchColumn();

echo 'total=' . $total . PHP_EOL;
echo 'holidays=' . $holidays . PHP_EOL;
echo 'events=' . $events . PHP_EOL;
