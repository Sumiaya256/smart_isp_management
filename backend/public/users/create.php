<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/database.php';
require_once dirname(__DIR__, 2) . '/config/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json(['ok' => false, 'message' => 'Method not allowed'], 405);
}

require_roles(['Admin']);

$input = read_json_input();
$fullName = trim((string) ($input['full_name'] ?? ''));
$email = trim((string) ($input['email'] ?? ''));
$phone = trim((string) ($input['phone'] ?? ''));
$password = (string) ($input['password'] ?? '');
$roleId = (int) ($input['role_id'] ?? 0);

if ($fullName === '' || $email === '' || $password === '' || $roleId <= 0) {
    send_json(['ok' => false, 'message' => 'full_name, email, password and role_id are required'], 422);
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    send_json(['ok' => false, 'message' => 'Invalid email format'], 422);
}

if (strlen($password) < 8) {
    send_json(['ok' => false, 'message' => 'Password must be at least 8 characters'], 422);
}

try {
    $pdo = db();

    $roleCheck = $pdo->prepare('SELECT id FROM roles WHERE id = :id LIMIT 1');
    $roleCheck->execute(['id' => $roleId]);
    if (!$roleCheck->fetch()) {
        send_json(['ok' => false, 'message' => 'Invalid role_id'], 422);
    }

    $stmt = $pdo->prepare(
        'INSERT INTO users (full_name, email, phone, password_hash, role_id, is_active)
         VALUES (:full_name, :email, :phone, :password_hash, :role_id, 1)'
    );

    $stmt->execute([
        'full_name' => $fullName,
        'email' => $email,
        'phone' => $phone !== '' ? $phone : null,
        'password_hash' => password_hash($password, PASSWORD_DEFAULT),
        'role_id' => $roleId,
    ]);

    send_json([
        'ok' => true,
        'message' => 'User created successfully',
        'user_id' => (int) $pdo->lastInsertId(),
    ], 201);
} catch (PDOException $e) {
    if ((int) $e->getCode() === 23000) {
        send_json(['ok' => false, 'message' => 'Email already exists'], 409);
    }

    send_json(['ok' => false, 'message' => 'Failed to create user', 'error' => $e->getMessage()], 500);
} catch (Throwable $e) {
    send_json(['ok' => false, 'message' => 'Failed to create user', 'error' => $e->getMessage()], 500);
}
