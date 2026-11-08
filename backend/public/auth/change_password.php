<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/database.php';
require_once dirname(__DIR__, 2) . '/config/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json([
        'ok' => false,
        'message' => 'Method not allowed',
    ], 405);
}

$user = require_auth();
$input = read_json_input();

$oldPassword = (string) ($input['old_password'] ?? '');
$newPassword = (string) ($input['new_password'] ?? '');
$confirmPassword = (string) ($input['confirm_password'] ?? '');

if ($oldPassword === '' || $newPassword === '' || $confirmPassword === '') {
    send_json([
        'ok' => false,
        'message' => 'Old password, new password and confirm password are required',
    ], 422);
}

if ($newPassword !== $confirmPassword) {
    send_json([
        'ok' => false,
        'message' => 'New password and confirm password do not match',
    ], 422);
}

if (strlen($newPassword) < 6) {
    send_json([
        'ok' => false,
        'message' => 'New password must be at least 6 characters',
    ], 422);
}

if ($oldPassword === $newPassword) {
    send_json([
        'ok' => false,
        'message' => 'New password must be different from old password',
    ], 422);
}

try {
    $pdo = db();
    $userId = (int) ($user['id'] ?? 0);
    $role = strtolower(trim((string) ($user['role_name'] ?? '')));

    if ($userId <= 0) {
        send_json([
            'ok' => false,
            'message' => 'Invalid user session',
        ], 401);
    }

    $hashColumn = '';
    $selectSql = '';
    $updateSql = '';
    $params = ['id' => $userId];

    if ($role === 'client') {
        if (!table_exists($pdo, 'clients') || !column_exists($pdo, 'clients', 'connection_password_hash')) {
            send_json(['ok' => false, 'message' => 'Client password storage not configured'], 500);
        }

        $hashColumn = 'connection_password_hash';
        $selectSql = 'SELECT connection_password_hash AS password_hash FROM clients WHERE id = :id LIMIT 1';
        $updateSql = 'UPDATE clients SET connection_password_hash = :new_hash WHERE id = :id';
    } elseif (table_exists($pdo, 'employee_profiles') && in_array($role, ['employee', 'hr', 'operation', 'accounts', 'it', 'support', 'administration'], true)) {
        if (!column_exists($pdo, 'employee_profiles', 'password_hash')) {
            send_json(['ok' => false, 'message' => 'Employee password storage not configured'], 500);
        }

        $hashColumn = 'password_hash';
        $selectSql = 'SELECT p.password_hash AS password_hash FROM employee_profiles p WHERE p.employee_id = :id LIMIT 1';
        $updateSql = 'UPDATE employee_profiles SET password_hash = :new_hash WHERE employee_id = :id';
    } else {
        if (!table_exists($pdo, 'users') || !column_exists($pdo, 'users', 'password_hash')) {
            send_json(['ok' => false, 'message' => 'User password storage not configured'], 500);
        }

        $hashColumn = 'password_hash';
        $selectSql = 'SELECT password_hash FROM users WHERE id = :id LIMIT 1';
        $updateSql = 'UPDATE users SET password_hash = :new_hash WHERE id = :id';
    }

    $stmt = $pdo->prepare($selectSql);
    $stmt->execute($params);
    $row = $stmt->fetch();

    $currentHash = $row ? (string) ($row[$hashColumn] ?? $row['password_hash'] ?? '') : '';

    if ($currentHash === '' || !password_verify($oldPassword, $currentHash)) {
        send_json([
            'ok' => false,
            'message' => 'Old password is incorrect',
        ], 401);
    }

    $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
    $updateStmt = $pdo->prepare($updateSql);
    $updateStmt->execute([
        'new_hash' => $newHash,
        'id' => $userId,
    ]);

    send_json([
        'ok' => true,
        'message' => 'Password updated successfully',
    ]);
} catch (Throwable $e) {
    send_json([
        'ok' => false,
        'message' => 'Failed to update password',
        'error' => $e->getMessage(),
    ], 500);
}

function table_exists(PDO $pdo, string $tableName): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*)
         FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name'
    );
    $stmt->execute(['table_name' => $tableName]);
    return (int) $stmt->fetchColumn() > 0;
}

function column_exists(PDO $pdo, string $tableName, string $columnName): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*)
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name AND COLUMN_NAME = :column_name'
    );
    $stmt->execute([
        'table_name' => $tableName,
        'column_name' => $columnName,
    ]);
    return (int) $stmt->fetchColumn() > 0;
}
