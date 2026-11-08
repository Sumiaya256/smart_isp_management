<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/database.php';
require_once dirname(__DIR__, 2) . '/config/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json(['ok' => false, 'message' => 'Method not allowed'], 405);
}

require_roles(['Admin']);

$input = read_json_input();
$userId = (int) ($input['user_id'] ?? 0);
$fullName = trim((string) ($input['full_name'] ?? ''));
$email = trim((string) ($input['email'] ?? ''));
$phone = trim((string) ($input['phone'] ?? ''));
$roleId = (int) ($input['role_id'] ?? 0);
$isActive = isset($input['is_active']) ? (int) ((bool) $input['is_active']) : null;

if ($userId <= 0 || $fullName === '' || $email === '' || $roleId <= 0) {
    send_json(['ok' => false, 'message' => 'user_id, full_name, email and role_id are required'], 422);
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    send_json(['ok' => false, 'message' => 'Invalid email format'], 422);
}

try {
    $pdo = db();

    $roleCheck = $pdo->prepare('SELECT id FROM roles WHERE id = :id LIMIT 1');
    $roleCheck->execute(['id' => $roleId]);
    if (!$roleCheck->fetch()) {
        send_json(['ok' => false, 'message' => 'Invalid role_id'], 422);
    }

    $stmt = $pdo->prepare(
        'UPDATE users
         SET full_name = :full_name,
             email = :email,
             phone = :phone,
             role_id = :role_id,
             is_active = COALESCE(:is_active, is_active)
         WHERE id = :id'
    );

    $stmt->bindValue(':full_name', $fullName);
    $stmt->bindValue(':email', $email);
    $stmt->bindValue(':phone', $phone !== '' ? $phone : null);
    $stmt->bindValue(':role_id', $roleId, PDO::PARAM_INT);
    if ($isActive === null) {
        $stmt->bindValue(':is_active', null, PDO::PARAM_NULL);
    } else {
        $stmt->bindValue(':is_active', $isActive, PDO::PARAM_INT);
    }
    $stmt->bindValue(':id', $userId, PDO::PARAM_INT);

    $stmt->execute();

    if ($stmt->rowCount() === 0) {
        send_json(['ok' => false, 'message' => 'No changes made or user not found'], 404);
    }

    send_json(['ok' => true, 'message' => 'User updated successfully']);
} catch (PDOException $e) {
    if ((int) $e->getCode() === 23000) {
        send_json(['ok' => false, 'message' => 'Email already exists'], 409);
    }

    send_json(['ok' => false, 'message' => 'Failed to update user', 'error' => $e->getMessage()], 500);
} catch (Throwable $e) {
    send_json(['ok' => false, 'message' => 'Failed to update user', 'error' => $e->getMessage()], 500);
}
