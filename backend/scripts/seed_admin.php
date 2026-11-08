<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/config/env.php';

$adminName = env('DEFAULT_ADMIN_NAME', 'System Administrator');
$adminEmail = env('DEFAULT_ADMIN_EMAIL', 'admin@promee.local');
$adminPassword = env('DEFAULT_ADMIN_PASSWORD', 'Admin@12345');

try {
    $pdo = db();

    $roleStmt = $pdo->prepare('SELECT id FROM roles WHERE name = :name LIMIT 1');
    $roleStmt->execute(['name' => 'Admin']);
    $role = $roleStmt->fetch();

    if (!$role) {
        throw new RuntimeException('Admin role not found. Run initial schema first.');
    }

    $existsStmt = $pdo->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
    $existsStmt->execute(['email' => $adminEmail]);
    $exists = $existsStmt->fetch();

    if ($exists) {
        echo 'Admin user already exists for email: ' . $adminEmail . PHP_EOL;
        exit(0);
    }

    $insertStmt = $pdo->prepare(
        'INSERT INTO users (full_name, email, password_hash, role_id, is_active)
         VALUES (:full_name, :email, :password_hash, :role_id, 1)'
    );

    $insertStmt->execute([
        'full_name' => $adminName,
        'email' => $adminEmail,
        'password_hash' => password_hash($adminPassword, PASSWORD_DEFAULT),
        'role_id' => $role['id'],
    ]);

    echo 'Admin user created successfully.' . PHP_EOL;
    echo 'Email: ' . $adminEmail . PHP_EOL;
    echo 'Password: ' . $adminPassword . PHP_EOL;
    echo 'Please change this password after first login.' . PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, 'Seeding failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
