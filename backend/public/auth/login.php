<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/database.php';
require_once dirname(__DIR__, 2) . '/config/auth.php';
require_once dirname(__DIR__) . '/employees/access_matrix.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json([
        'ok' => false,
        'message' => 'Method not allowed',
    ], 405);
}

$input = read_json_input();
$identifier = trim((string) ($input['email'] ?? ''));
$password = (string) ($input['password'] ?? '');

if ($identifier === '' || $password === '') {
    send_json([
        'ok' => false,
        'message' => 'Email/connection mail and password are required',
    ], 422);
}

try {
    $pdo = db();

    $stmt = $pdo->prepare(
        'SELECT u.id, u.full_name, u.email, u.password_hash, u.is_active, r.name AS role_name
         FROM users u
         INNER JOIN roles r ON r.id = u.role_id
         WHERE u.email = :email
         LIMIT 1'
    );
        $stmt->execute(['email' => $identifier]);
    $user = $stmt->fetch();

    if ($user && password_verify($password, (string) $user['password_hash'])) {
        $user['access_modules'] = role_default_modules((string) $user['role_name']);

        if ((int) $user['is_active'] !== 1) {
            send_json([
                'ok' => false,
                'message' => 'Your account is inactive',
            ], 403);
        }

        login_user($user);

        $updateStmt = $pdo->prepare('UPDATE users SET last_login_at = NOW() WHERE id = :id');
        $updateStmt->execute(['id' => $user['id']]);

        send_json([
            'ok' => true,
            'message' => 'Login successful',
            'user' => [
                'id' => (int) $user['id'],
                'full_name' => (string) $user['full_name'],
                'email' => (string) $user['email'],
                'role_name' => (string) $user['role_name'],
                'position_name' => '',
                'access_modules' => $user['access_modules'],
                'module_permissions' => [],
            ],
        ]);
    }

    $employeeAuth = try_employee_login($pdo, $identifier, $password);
    if ($employeeAuth !== null) {
        login_user($employeeAuth);

        send_json([
            'ok' => true,
            'message' => 'Login successful',
            'user' => [
                'id' => (int) $employeeAuth['id'],
                'full_name' => (string) $employeeAuth['full_name'],
                'email' => (string) $employeeAuth['email'],
                'role_name' => (string) $employeeAuth['role_name'],
                'position_name' => (string) ($employeeAuth['position_name'] ?? ''),
                'access_modules' => $employeeAuth['access_modules'],
                'module_permissions' => $employeeAuth['module_permissions'] ?? [],
            ],
        ]);
    }

    $clientAuth = try_client_login($pdo, $identifier, $password);
    if ($clientAuth !== null) {
        login_user($clientAuth);

        send_json([
            'ok' => true,
            'message' => 'Login successful',
            'user' => [
                'id' => (int) $clientAuth['id'],
                'full_name' => (string) $clientAuth['full_name'],
                'email' => (string) $clientAuth['email'],
                'role_name' => (string) $clientAuth['role_name'],
                'access_modules' => $clientAuth['access_modules'],
                'module_permissions' => [],
            ],
        ]);
    }

    send_json([
        'ok' => false,
        'message' => 'Invalid credentials',
    ], 401);

} catch (Throwable $e) {
    send_json([
        'ok' => false,
        'message' => 'Internal server error',
        'error' => $e->getMessage(),
    ], 500);
}

function try_employee_login(PDO $pdo, string $email, string $password): ?array
{
    if (!table_exists($pdo, 'employee_profiles')) {
        return null;
    }

    $stmt = $pdo->prepare(
        'SELECT e.id, e.full_name, e.email, e.department_id, e.position_id, d.department_name, pos.position_name, p.password_hash, p.role_name, p.status_label
            , p.access_modules
         FROM employees e
         LEFT JOIN departments d ON d.id = e.department_id
         LEFT JOIN positions pos ON pos.id = e.position_id
         INNER JOIN employee_profiles p ON p.employee_id = e.id
         WHERE e.email = :email
         LIMIT 1'
    );
    $stmt->execute(['email' => $email]);
    $employee = $stmt->fetch();

    if (!$employee) {
        return null;
    }

    $passwordHash = (string) ($employee['password_hash'] ?? '');
    if ($passwordHash === '' || !password_verify($password, $passwordHash)) {
        return null;
    }

    $statusLabel = strtolower(trim((string) ($employee['status_label'] ?? 'active')));
    if ($statusLabel === 'inactive' || $statusLabel === 'left' || $statusLabel === 'resigned') {
        send_json([
            'ok' => false,
            'message' => 'Your account is inactive',
        ], 403);
    }

    [$profilePermissionMap, $profileHasOverride] = decode_access_permission_map((string) ($employee['access_modules'] ?? ''));

    if ($profileHasOverride) {
        // Employee-specific access map is primary so per-employee overrides work.
        $modulePermissions = $profilePermissionMap;
        $accessModules = module_permissions_to_access_modules($modulePermissions);
    } else {
        $modulePermissions = load_position_module_permissions($pdo, (int) ($employee['position_id'] ?? 0));
        $accessModules = module_permissions_to_access_modules($modulePermissions);
    }

    $resolvedRoleName = trim((string) ($employee['role_name'] ?? ''));
    if ($resolvedRoleName === '') {
        $resolvedRoleName = trim((string) ($employee['department_name'] ?? ''));
    }
    if ($resolvedRoleName === '') {
        $resolvedRoleName = 'Employee';
    }

    return [
        'id' => (int) $employee['id'],
        'full_name' => (string) $employee['full_name'],
        'email' => (string) $employee['email'],
        'role_name' => $resolvedRoleName,
        'position_name' => (string) ($employee['position_name'] ?? ''),
        'access_modules' => $profileHasOverride ? $accessModules : (!empty($accessModules) ? $accessModules : role_default_modules($resolvedRoleName)),
        'module_permissions' => $modulePermissions,
    ];
}

function decode_access_modules(string $rawValue): array
{
    $rawValue = trim($rawValue);
    if ($rawValue === '') {
        return [];
    }

    $decoded = json_decode($rawValue, true);
    if (is_array($decoded)) {
        return array_values(array_filter(array_map('strval', $decoded), static fn ($v) => trim($v) !== ''));
    }

    // Backward compatibility for comma-separated storage.
    $parts = array_map('trim', explode(',', $rawValue));
    return array_values(array_filter(array_map('strval', $parts), static fn ($v) => trim($v) !== ''));
}

function decode_access_permission_map(string $rawValue): array
{
    $rawValue = trim($rawValue);
    if ($rawValue === '') {
        return [[], false];
    }

    $decoded = json_decode($rawValue, true);
    if (!is_array($decoded)) {
        return [[], false];
    }

    $allowed = array_flip(available_access_modules());
    $map = [];

    // Legacy shape: ["Module A", "Module B"]
    // Do not treat this as an explicit per-employee permission override.
    // Fallback to position permissions to avoid unintended full access.
    if (array_values($decoded) === $decoded) {
        return [[], false];
    }

    foreach ($decoded as $moduleName => $level) {
        $moduleName = trim((string) $moduleName);
        if ($moduleName === '' || !isset($allowed[$moduleName])) {
            continue;
        }

        $normalizedLevel = normalize_permission_level_auth((string) $level);
        if ($normalizedLevel === 'limited') {
            $normalizedLevel = 'view';
        }
        if (!in_array($normalizedLevel, ['full', 'view', 'none'], true)) {
            $normalizedLevel = 'none';
        }
        $map[$moduleName] = $normalizedLevel;
    }

    return [$map, true];
}

function load_department_access_modules(PDO $pdo, int $departmentId): array
{
    if ($departmentId <= 0 || !table_exists($pdo, 'department_access_modules')) {
        return [];
    }

    $stmt = $pdo->prepare(
        'SELECT module_name
         FROM department_access_modules
         WHERE department_id = :department_id
         ORDER BY module_name ASC'
    );
    $stmt->execute(['department_id' => $departmentId]);
    $rows = $stmt->fetchAll();

    return array_values(array_filter(array_map(static fn ($row) => (string) ($row['module_name'] ?? ''), $rows), static fn ($v) => trim($v) !== ''));
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

function ensure_client_portal_tracking_columns(PDO $pdo): void
{
    if (!table_exists($pdo, 'clients')) {
        return;
    }

    if (!column_exists($pdo, 'clients', 'portal_last_login_at')) {
        $pdo->exec('ALTER TABLE clients ADD COLUMN portal_last_login_at DATETIME NULL AFTER connection_password_hash');
    }

    if (!column_exists($pdo, 'clients', 'portal_login_count')) {
        $pdo->exec('ALTER TABLE clients ADD COLUMN portal_login_count INT NOT NULL DEFAULT 0 AFTER portal_last_login_at');
    }
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

function try_client_login(PDO $pdo, string $identifier, string $password): ?array
{
    if (!table_exists($pdo, 'clients')) {
        return null;
    }

    if (!column_exists($pdo, 'clients', 'connection_email') || !column_exists($pdo, 'clients', 'connection_password_hash')) {
        return null;
    }

    $query =
        'SELECT c.id, c.full_name, c.client_code, c.connection_email, c.status, c.connection_password_hash'
        . ' FROM clients c
            WHERE c.connection_email = :identifier
            LIMIT 1';

    $stmt = $pdo->prepare($query);
    $stmt->execute(['identifier' => $identifier]);
    $client = $stmt->fetch();

    if (!$client) {
        return null;
    }

    $passwordHash = (string) ($client['connection_password_hash'] ?? '');
    if ($passwordHash === '' || !password_verify($password, $passwordHash)) {
        return null;
    }

    $status = strtolower(trim((string) ($client['status'] ?? 'active')));
    if ($status !== 'active') {
        send_json([
            'ok' => false,
            'message' => 'Your account is inactive',
        ], 403);
    }

    ensure_client_portal_tracking_columns($pdo);

    $trackingStmt = $pdo->prepare(
        'UPDATE clients
         SET portal_last_login_at = NOW(),
             portal_login_count = COALESCE(portal_login_count, 0) + 1
         WHERE id = :id'
    );
    $trackingStmt->execute(['id' => (int) $client['id']]);

    $roleName = 'Client';

    return [
        'id' => (int) $client['id'],
        'full_name' => (string) ($client['full_name'] ?: ('Client ' . (string) $client['client_code'])),
        'email' => (string) ($client['connection_email'] ?: (string) $client['client_code']),
        'role_name' => $roleName,
        'access_modules' => role_default_modules($roleName),
    ];
}

function role_default_modules(string $roleName): array
{
    $role = strtolower(trim($roleName));

    $departmentDefaults = default_department_module_map();
    foreach ($departmentDefaults as $departmentName => $modules) {
        if (strtolower($departmentName) === $role) {
            return $modules;
        }
    }

    if ($role === 'admin') {
        return available_access_modules();
    }

    if ($role === 'administration') {
        return [
            'Dashboard',
            'Client',
            'Billing',
            'HR & Payroll',
            'Leave Management',
            'Task Management',
            'Purchase',
            'Assets',
            'Income',
            'Inventory',
        ];
    }

    if ($role === 'bill collector') {
        return ['Dashboard', 'Billing', 'Purchase'];
    }

    if ($role === 'support staff' || $role === 'technician') {
        return ['Dashboard', 'Support & Ticketing', 'Task Management', 'Mikrotik Server'];
    }

    if ($role === 'hr') {
        return ['Dashboard', 'Client', 'HR & Payroll', 'Leave Management', 'Income', 'Assets', 'Inventory'];
    }

    if ($role === 'accountant') {
        return ['Dashboard', 'Billing', 'Assets', 'Income', 'Purchase', 'Inventory'];
    }

    if ($role === 'client') {
        return ['Dashboard', 'Billing', 'Support & Ticketing'];
    }

    return ['Dashboard'];
}
