<?php

declare(strict_types=1);

require_once __DIR__ . '/../backend/config/database.php';

$pdo = db();

// Remove four non-holiday internal events to keep seeded total within requested range.
$removeTitles = [
    'Staff Recognition & Awards',
    'Year-End Client Retention Campaign',
    'Regional POP Health Review',
    'Cybersecurity Awareness Week Launch',
];

$stmt = $pdo->prepare('DELETE FROM events_holidays WHERE event_type = :event_type AND title = :title LIMIT 1');
$deleted = 0;
foreach ($removeTitles as $title) {
    $stmt->execute([
        'event_type' => 'event',
        'title' => $title,
    ]);
    $deleted += $stmt->rowCount();
}

echo 'deleted=' . $deleted . PHP_EOL;
