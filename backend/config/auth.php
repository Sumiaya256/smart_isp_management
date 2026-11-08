<?php

declare(strict_types=1);

require_once __DIR__ . '/response.php';

function is_access_matrix_disabled(): bool
{
    return false;
}

function ensure_session_started(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_name('isp_session');
        session_start();
    }
}

function login_user(array $user): void
{
    ensure_session_started();

    session_regenerate_id(true);

    $_SESSION['auth_user'] = [
        'id' => (int) $user['id'],
        'full_name' => (string) $user['full_name'],
        'email' => (string) $user['email'],
        'role_name' => (string) $user['role_name'],
       'position_name' => (string) ($user['position_name'] ?? ''),
        'access_modules' => isset($user['access_modules']) && is_array($user['access_modules'])
            ? array_values($user['access_modules'])
            : [],
        'module_permissions' => isset($user['module_permissions']) && is_array($user['module_permissions'])
            ? $user['module_permissions']
            : [],
    ];
}

function logout_user(): void
{
    ensure_session_started();

    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path'],
            $params['domain'],
            $params['secure'],
            $params['httponly']
        );
    }

    session_destroy();
}

function current_user(): ?array
{
    ensure_session_started();
    return isset($_SESSION['auth_user']) && is_array($_SESSION['auth_user'])
        ? $_SESSION['auth_user']
        : null;
}

function require_auth(): array
{
    $user = current_user();
    if ($user === null) {
        send_json([
            'ok' => false,
            'message' => 'Unauthorized',
        ], 401);
    }

    enforce_module_access_for_current_request($user);

    return $user;
}

function require_roles(array $allowedRoles): array
{
    $user = require_auth();

    if (is_access_matrix_disabled()) {
        return $user;
    }

    $userRole = strtolower(trim((string) ($user['role_name'] ?? '')));
    $allowed = array_map(static fn ($role) => strtolower(trim((string) $role)), $allowedRoles);

    if (!in_array($userRole, $allowed, true)) {
        send_json([
            'ok' => false,
            'message' => 'Forbidden: insufficient role permission',
        ], 403);
    }

    return $user;
}

function require_module_permission(string $moduleName, string $action = 'view', bool $allowLimitedWrite = false): array
{
    $user = require_auth();

    if (!user_has_module_action_access($user, $moduleName, $action, $allowLimitedWrite)) {
        send_json([
            'ok' => false,
            'message' => 'Forbidden: insufficient module permission',
            'module' => $moduleName,
            'action' => $action,
        ], 403);
    }

    return $user;
}

function require_page_permission(string $pageName, string $action = 'view'): array
{
    $user = require_auth();

    if (!user_has_page_action_access($user, $pageName, $action)) {
        send_json([
            'ok' => false,
            'message' => 'Forbidden: insufficient page permission',
            'page' => $pageName,
            'action' => strtolower(trim($action)) === 'edit' ? 'edit' : 'view',
        ], 403);
    }

    return $user;
}

function require_explicit_page_permission(string $pageName, string $action = 'view'): array
{
    $user = require_auth();

    if (!user_has_explicit_page_action_access($user, $pageName, $action)) {
        send_json([
            'ok' => false,
            'message' => 'Forbidden: insufficient page permission',
            'page' => $pageName,
            'action' => strtolower(trim($action)) === 'edit' ? 'edit' : 'view',
        ], 403);
    }

    return $user;
}

function user_has_module_action_access(array $user, string $moduleName, string $action = 'view', bool $allowLimitedWrite = false): bool
{
    if (is_access_matrix_disabled()) {
        return true;
    }

    $action = strtolower(trim($action)) === 'edit' ? 'edit' : 'view';
    $moduleName = canonical_module_name($moduleName);

    $permissions = effective_module_permissions($user);

    if (!isset($permissions[$moduleName])) {
        return false;
    }

    $level = normalize_permission_level_auth((string) $permissions[$moduleName]);
    if ($level === 'none') {
        return false;
    }

    if ($action === 'view') {
        return in_array($level, ['full', 'view', 'limited'], true);
    }

    if ($level === 'full') {
        return true;
    }

    if ($level === 'limited' && $allowLimitedWrite) {
        return true;
    }

    return false;
}

function user_has_page_action_access(array $user, string $pageName, string $action = 'view'): bool
{
    if (is_access_matrix_disabled()) {
        return true;
    }

    $action = strtolower(trim($action)) === 'edit' ? 'edit' : 'view';
    $level = user_page_permission_level($user, $pageName);

    if ($level === 'none') {
        return false;
    }

    if ($action === 'view') {
        return in_array($level, ['full', 'view', 'limited'], true);
    }

    return $level === 'full';
}

function user_has_explicit_page_action_access(array $user, string $pageName, string $action = 'view'): bool
{
    if (is_access_matrix_disabled()) {
        return true;
    }

    $action = strtolower(trim($action)) === 'edit' ? 'edit' : 'view';
    $level = user_page_permission_level($user, $pageName, false);

    if ($level === 'none') {
        return false;
    }

    if ($action === 'view') {
        return in_array($level, ['full', 'view', 'limited'], true);
    }

    return $level === 'full';
}

function user_has_any_page_action_access(array $user, array $pageNames, string $action = 'view'): bool
{
    foreach ($pageNames as $pageName) {
        if (user_has_page_action_access($user, (string) $pageName, $action)) {
            return true;
        }
    }

    return false;
}

function user_page_permission_level(array $user, string $pageName, bool $allowParentFallback = true): string
{
    $permissions = effective_module_permissions($user);
    $target = normalize_page_key($pageName);

    foreach ($permissions as $permissionName => $level) {
        if (normalize_page_key((string) $permissionName) === $target) {
            return normalize_permission_level_auth((string) $level);
        }
    }

    if ($allowParentFallback) {
        $parentModule = page_parent_module_name($pageName);
        if ($parentModule !== '') {
            $parentTarget = normalize_page_key($parentModule);
            foreach ($permissions as $permissionName => $level) {
                if (normalize_page_key((string) $permissionName) === $parentTarget) {
                    return normalize_permission_level_auth((string) $level);
                }
            }
        }
    }

    return 'none';
}

function page_parent_module_name(string $pageName): string
{
    $page = normalize_page_key($pageName);
    $map = [
        'new request' => 'Client',
        'add new client' => 'Client',
        'client list' => 'Client',
        'left client' => 'Client',
        'scheduler' => 'Client',
        'change request' => 'Client',
        'portal manage' => 'Client',
        'billing' => 'Billing',
        'bulk client import' => 'Mikrotik Server',
        'employee list' => 'HR & Payroll',
        'add employee' => 'HR & Payroll',
        'salary sheet' => 'HR & Payroll',
        'department' => 'HR & Payroll',
        'position' => 'HR & Payroll',
        'payhead' => 'HR & Payroll',
        'payroll' => 'HR & Payroll',
        'resign rule' => 'HR & Payroll',
        'resignation' => 'HR & Payroll',
        'internet packages' => 'HR & Payroll',
        'attendance' => 'Attendance',
        'leave management' => 'Leave Management',
        'apply leave' => 'Leave Management',
        'events & holidays' => 'Events & Holidays',
        'ticket list' => 'Support & Ticketing',
        'new ticket' => 'Support & Ticketing',
        'support team' => 'Support & Ticketing',
        'ticket reports' => 'Support & Ticketing',
        'service history' => 'Support & Ticketing',
        'task management' => 'Task Management',
        'purchase' => 'Purchase',
        'inventory' => 'Inventory',
        'assets' => 'Assets',
        'income' => 'Income',
    ];

    return $map[$page] ?? '';
}

function normalize_page_key(string $value): string
{
    $normalized = strtolower(trim(preg_replace('/\s+/', ' ', $value) ?? ''));
    $aliases = [
        'add new' => 'add new client',
        'daily attendance' => 'attendance',
    ];

    return $aliases[$normalized] ?? $normalized;
}

function is_limited_module_permission(array $user, string $moduleName): bool
{
    if (is_access_matrix_disabled()) {
        return false;
    }

    $moduleName = canonical_module_name($moduleName);
    $permissions = effective_module_permissions($user);
    if (!isset($permissions[$moduleName])) {
        return false;
    }

    return normalize_permission_level_auth((string) $permissions[$moduleName]) === 'limited';
}

function enforce_module_access_for_current_request(array $user): void
{
    if (is_access_matrix_disabled()) {
        return;
    }

    $role = strtolower(trim((string) ($user['role_name'] ?? '')));
    if ($role === 'client') {
        return;
    }

    $scriptPath = current_script_path();

    $pageRequirement = page_access_requirement_for_request($scriptPath, (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if ($pageRequirement !== null) {
        $action = (string) ($pageRequirement['action'] ?? 'view');
        $pages = $pageRequirement['pages'] ?? [];
        $mode = (string) ($pageRequirement['mode'] ?? 'any');

        $allowed = false;
        if (is_array($pages) && !empty($pages)) {
            if ($mode === 'all') {
                $allowed = true;
                foreach ($pages as $pageName) {
                    if (!user_has_page_action_access($user, (string) $pageName, $action)) {
                        $allowed = false;
                        break;
                    }
                }
            } else {
                $allowed = user_has_any_page_action_access($user, $pages, $action);
            }
        }

        if (!$allowed) {
            send_json([
                'ok' => false,
                'message' => 'Forbidden: insufficient page permission',
                'pages' => $pages,
                'action' => $action,
            ], 403);
        }

        return;
    }

    $module = module_from_script_path($scriptPath);
    if ($module === null) {
        return;
    }

    $action = action_from_request($scriptPath, (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    $allowLimitedWrite = $action === 'edit' && module_allows_limited_write($module);

    if (!user_has_module_action_access($user, $module, $action, $allowLimitedWrite)) {
        if (is_accounts_payroll_override_allowed($user, $scriptPath, $action)) {
            return;
        }

        send_json([
            'ok' => false,
            'message' => 'Forbidden: insufficient module permission',
            'module' => $module,
            'action' => $action,
        ], 403);
    }
}

function page_access_requirement_for_request(string $scriptPath, string $method): ?array
{
    $path = strtolower($scriptPath);
    $method = strtoupper(trim($method));

    if (strpos($path, '/backend/public/employees/list_departments.php') !== false) {
        return ['pages' => ['Employee List'], 'action' => 'view', 'mode' => 'any'];
    }
    if (strpos($path, '/backend/public/employees/list_positions.php') !== false) {
        return ['pages' => ['Employee List'], 'action' => 'view', 'mode' => 'any'];
    }
    if (strpos($path, '/backend/public/employees/list_employee_list.php') !== false) {
        return ['pages' => ['Employee List'], 'action' => 'view', 'mode' => 'any'];
    }
    if (strpos($path, '/backend/public/employees/list_departments_employee_list.php') !== false) {
        return ['pages' => ['Employee List'], 'action' => 'view', 'mode' => 'any'];
    }
    if (strpos($path, '/backend/public/employees/list_positions_employee_list.php') !== false) {
        return ['pages' => ['Employee List'], 'action' => 'view', 'mode' => 'any'];
    }
    if (strpos($path, '/backend/public/employees/list_salary_sheet.php') !== false) {
        return ['pages' => ['Salary Sheet'], 'action' => 'view', 'mode' => 'any'];
    }
    if (strpos($path, '/backend/public/employees/list_payroll.php') !== false) {
        return ['pages' => ['Payroll'], 'action' => 'view', 'mode' => 'any'];
    }
    if (strpos($path, '/backend/public/employees/list_resignation.php') !== false) {
        return ['pages' => ['Resignation'], 'action' => 'view', 'mode' => 'any'];
    }
    if (strpos($path, '/backend/public/employees/list_departments_add_employee.php') !== false) {
        return ['pages' => ['Add Employee'], 'action' => 'view', 'mode' => 'any'];
    }
    if (strpos($path, '/backend/public/employees/list_positions_add_employee.php') !== false) {
        return ['pages' => ['Add Employee'], 'action' => 'view', 'mode' => 'any'];
    }
    if (strpos($path, '/backend/public/employees/list_departments_department.php') !== false) {
        return ['pages' => ['Department'], 'action' => 'view', 'mode' => 'any'];
    }
    if (strpos($path, '/backend/public/employees/list_departments_position.php') !== false) {
        return ['pages' => ['Position'], 'action' => 'view', 'mode' => 'any'];
    }
    if (strpos($path, '/backend/public/employees/list_positions_position.php') !== false) {
        return ['pages' => ['Position'], 'action' => 'view', 'mode' => 'any'];
    }
    if (strpos($path, '/backend/public/employees/next_employee_code_add_employee.php') !== false) {
        return ['pages' => ['Add Employee'], 'action' => 'view', 'mode' => 'any'];
    }
    if (strpos($path, '/backend/public/employees/next_employee_code.php') !== false) {
        return ['pages' => ['Employee List'], 'action' => 'view', 'mode' => 'any'];
    }
    if (strpos($path, '/backend/public/employees/create.php') !== false) {
        return ['pages' => ['Add Employee'], 'action' => 'edit', 'mode' => 'any'];
    }
    if (strpos($path, '/backend/public/employees/update.php') !== false) {
        return ['pages' => ['Employee List'], 'action' => 'edit', 'mode' => 'any'];
    }
    if (strpos($path, '/backend/public/employees/delete.php') !== false) {
        return ['pages' => ['Employee List'], 'action' => 'edit', 'mode' => 'any'];
    }
    if (strpos($path, '/backend/public/employees/upsert_department.php') !== false) {
        return ['pages' => ['Department'], 'action' => 'edit', 'mode' => 'any'];
    }
    if (strpos($path, '/backend/public/employees/update_department.php') !== false) {
        return ['pages' => ['Department'], 'action' => 'edit', 'mode' => 'any'];
    }
    if (strpos($path, '/backend/public/employees/delete_department.php') !== false) {
        return ['pages' => ['Department'], 'action' => 'edit', 'mode' => 'any'];
    }
    if (strpos($path, '/backend/public/employees/upsert_position.php') !== false) {
        return ['pages' => ['Position'], 'action' => 'edit', 'mode' => 'any'];
    }
    if (strpos($path, '/backend/public/employees/update_position.php') !== false) {
        return ['pages' => ['Position'], 'action' => 'edit', 'mode' => 'any'];
    }
    if (strpos($path, '/backend/public/employees/delete_position.php') !== false) {
        return ['pages' => ['Position'], 'action' => 'edit', 'mode' => 'any'];
    }
    if (strpos($path, '/backend/public/employees/sync_access_structure.php') !== false) {
        return ['pages' => ['Department', 'Position'], 'action' => 'edit', 'mode' => 'any'];
    }
    if (strpos($path, '/backend/public/payheads/list.php') !== false) {
        return ['pages' => ['Payhead'], 'action' => 'view', 'mode' => 'any'];
    }
    if (strpos($path, '/backend/public/payheads/upsert.php') !== false) {
        return ['pages' => ['Payhead'], 'action' => 'edit', 'mode' => 'any'];
    }
    if (strpos($path, '/backend/public/payheads/delete.php') !== false) {
        return ['pages' => ['Payhead'], 'action' => 'edit', 'mode' => 'any'];
    }
    if (strpos($path, '/backend/public/payroll/list.php') !== false) {
        return ['pages' => ['Payroll'], 'action' => 'view', 'mode' => 'any'];
    }
    if (strpos($path, '/backend/public/payroll/process.php') !== false) {
        return ['pages' => ['Payroll'], 'action' => 'edit', 'mode' => 'any'];
    }
    if (strpos($path, '/backend/public/payroll/mark_paid.php') !== false) {
        return ['pages' => ['Payroll'], 'action' => 'edit', 'mode' => 'any'];
    }
    if (strpos($path, '/backend/public/resignations/list_rules.php') !== false) {
        return ['pages' => ['Resign Rule'], 'action' => 'view', 'mode' => 'any'];
    }
    if (strpos($path, '/backend/public/resignations/upsert_rule.php') !== false) {
        return ['pages' => ['Resign Rule'], 'action' => 'edit', 'mode' => 'any'];
    }
    if (strpos($path, '/backend/public/resignations/delete_rule.php') !== false) {
        return ['pages' => ['Resign Rule'], 'action' => 'edit', 'mode' => 'any'];
    }
    if (strpos($path, '/backend/public/resignations/list.php') !== false) {
        return ['pages' => ['Resignation'], 'action' => 'view', 'mode' => 'any'];
    }
    if (strpos($path, '/backend/public/resignations/upsert.php') !== false) {
        return ['pages' => ['Resignation'], 'action' => 'edit', 'mode' => 'any'];
    }
    if (strpos($path, '/backend/public/resignations/update_status.php') !== false) {
        return ['pages' => ['Resignation'], 'action' => 'edit', 'mode' => 'any'];
    }
    if (strpos($path, '/backend/public/resignations/delete.php') !== false) {
        return ['pages' => ['Resignation'], 'action' => 'edit', 'mode' => 'any'];
    }

    if (strpos($path, '/backend/public/packages/list.php') !== false) {
        return ['pages' => ['Internet Packages'], 'action' => 'view', 'mode' => 'any'];
    }
    if (strpos($path, '/backend/public/packages/upsert.php') !== false) {
        return ['pages' => ['Internet Packages'], 'action' => 'edit', 'mode' => 'any'];
    }
    if (strpos($path, '/backend/public/packages/delete.php') !== false) {
        return ['pages' => ['Internet Packages'], 'action' => 'edit', 'mode' => 'any'];
    }

    if (strpos($path, '/backend/public/clients/requests_list.php') !== false) {
        return ['pages' => ['New Request'], 'action' => 'view', 'mode' => 'any'];
    }
    if (strpos($path, '/backend/public/clients/requests_create.php') !== false) {
        return ['pages' => ['New Request'], 'action' => 'edit', 'mode' => 'any'];
    }
    if (strpos($path, '/backend/public/clients/create.php') !== false) {
        return ['pages' => ['Add New Client'], 'action' => 'view', 'mode' => 'any'];
    }
    if (strpos($path, '/backend/public/clients/list.php') !== false) {
        return ['pages' => ['Client List'], 'action' => 'view', 'mode' => 'any'];
    }
    if (strpos($path, '/backend/public/clients/get.php') !== false) {
        return ['pages' => ['Client List'], 'action' => 'edit', 'mode' => 'any'];
    }
    if (strpos($path, '/backend/public/clients/update.php') !== false) {
        return ['pages' => ['Client List'], 'action' => 'edit', 'mode' => 'any'];
    }
    if (strpos($path, '/backend/public/clients/delete.php') !== false) {
        return ['pages' => ['Client List'], 'action' => 'edit', 'mode' => 'any'];
    }
    if (strpos($path, '/backend/public/clients/list_left.php') !== false) {
        return ['pages' => ['Left Client'], 'action' => 'view', 'mode' => 'any'];
    }
    if (strpos($path, '/backend/public/clients/terminate.php') !== false) {
        return ['pages' => ['Left Client'], 'action' => 'edit', 'mode' => 'any'];
    }
    if (strpos($path, '/backend/public/clients/search_active.php') !== false) {
        return ['pages' => ['Change Request', 'Left Client'], 'action' => 'view', 'mode' => 'any'];
    }
    if (strpos($path, '/backend/public/clients/service_requests_create.php') !== false) {
        return ['pages' => ['Change Request'], 'action' => 'edit', 'mode' => 'any'];
    }
    if (strpos($path, '/backend/public/clients/service_requests_update_status.php') !== false) {
        return ['pages' => ['Change Request', 'Left Client'], 'action' => 'edit', 'mode' => 'any'];
    }
    if (strpos($path, '/backend/public/clients/service_requests_list.php') !== false) {
        $kind = strtolower(trim((string) ($_GET['kind'] ?? '')));
        if ($kind === 'close_connection') {
            return ['pages' => ['Left Client'], 'action' => 'view', 'mode' => 'any'];
        }
        if ($kind === 'change') {
            return ['pages' => ['Change Request'], 'action' => 'view', 'mode' => 'any'];
        }
        return ['pages' => ['Change Request', 'Left Client'], 'action' => 'view', 'mode' => 'any'];
    }
    if (strpos($path, '/backend/public/support/scheduler_list.php') !== false) {
        return ['pages' => ['Scheduler'], 'action' => 'view', 'mode' => 'any'];
    }
    if (strpos($path, '/backend/public/support/scheduler_create.php') !== false) {
        return ['pages' => ['Scheduler'], 'action' => 'edit', 'mode' => 'any'];
    }
    if (strpos($path, '/backend/public/support/scheduler_update_status.php') !== false) {
        return ['pages' => ['Scheduler'], 'action' => 'edit', 'mode' => 'any'];
    }
    if (strpos($path, '/backend/public/support/clients_search.php') !== false) {
        return ['pages' => ['New Ticket'], 'action' => 'view', 'mode' => 'any'];
    }
    if (strpos($path, '/backend/public/support/tickets_create_staff.php') !== false) {
        return ['pages' => ['New Ticket'], 'action' => 'view', 'mode' => 'any'];
    }
    if (strpos($path, '/backend/public/support/tickets_customer_list.php') !== false) {
        return ['pages' => ['Ticket List', 'Ticket Reports', 'Service History'], 'action' => 'view', 'mode' => 'any'];
    }
    if (strpos($path, '/backend/public/support/tickets_update.php') !== false) {
        return ['pages' => ['Ticket List'], 'action' => 'edit', 'mode' => 'any'];
    }
    if (strpos($path, '/backend/public/support/tickets_delete.php') !== false) {
        return ['pages' => ['Ticket List'], 'action' => 'edit', 'mode' => 'any'];
    }
    if (strpos($path, '/backend/public/support/team_list.php') !== false) {
        return ['pages' => ['Support Team'], 'action' => 'view', 'mode' => 'any'];
    }

    if (strpos($path, '/backend/public/tasks/list.php') !== false) {
        return ['pages' => ['Task Management'], 'action' => 'view', 'mode' => 'any'];
    }
    if (strpos($path, '/backend/public/tasks/upsert.php') !== false) {
        return ['pages' => ['Task Management'], 'action' => 'edit', 'mode' => 'any'];
    }
    if (strpos($path, '/backend/public/tasks/update_status.php') !== false) {
        return ['pages' => ['Task Management'], 'action' => 'edit', 'mode' => 'any'];
    }
    if (strpos($path, '/backend/public/tasks/delete.php') !== false) {
        return ['pages' => ['Task Management'], 'action' => 'edit', 'mode' => 'any'];
    }

    if (strpos($path, '/backend/public/purchase/list.php') !== false) {
        return ['pages' => ['Purchase'], 'action' => 'view', 'mode' => 'any'];
    }
    if (strpos($path, '/backend/public/purchase/get.php') !== false) {
        return ['pages' => ['Purchase'], 'action' => 'view', 'mode' => 'any'];
    }
    if (strpos($path, '/backend/public/purchase/report_export.php') !== false) {
        return ['pages' => ['Purchase'], 'action' => 'view', 'mode' => 'any'];
    }
    if (strpos($path, '/backend/public/purchase/report_print.php') !== false) {
        return ['pages' => ['Purchase'], 'action' => 'view', 'mode' => 'any'];
    }
    if (strpos($path, '/backend/public/purchase/upsert.php') !== false) {
        return ['pages' => ['Purchase'], 'action' => 'edit', 'mode' => 'any'];
    }
    if (strpos($path, '/backend/public/purchase/update_status.php') !== false) {
        return ['pages' => ['Purchase'], 'action' => 'edit', 'mode' => 'any'];
    }
    if (strpos($path, '/backend/public/purchase/delete.php') !== false) {
        return ['pages' => ['Purchase'], 'action' => 'edit', 'mode' => 'any'];
    }

    if (strpos($path, '/backend/public/inventory/list.php') !== false) {
        return ['pages' => ['Inventory'], 'action' => 'view', 'mode' => 'any'];
    }
    if (strpos($path, '/backend/public/inventory/get.php') !== false) {
        return ['pages' => ['Inventory'], 'action' => 'view', 'mode' => 'any'];
    }
    if (strpos($path, '/backend/public/inventory/activity.php') !== false) {
        return ['pages' => ['Inventory'], 'action' => 'view', 'mode' => 'any'];
    }
    if (strpos($path, '/backend/public/inventory/stats.php') !== false) {
        return ['pages' => ['Inventory'], 'action' => 'view', 'mode' => 'any'];
    }
    if (strpos($path, '/backend/public/inventory/report_export.php') !== false) {
        return ['pages' => ['Inventory'], 'action' => 'view', 'mode' => 'any'];
    }
    if (strpos($path, '/backend/public/inventory/report_print.php') !== false) {
        return ['pages' => ['Inventory'], 'action' => 'view', 'mode' => 'any'];
    }
    if (strpos($path, '/backend/public/inventory/upsert.php') !== false) {
        return ['pages' => ['Inventory'], 'action' => 'edit', 'mode' => 'any'];
    }
    if (strpos($path, '/backend/public/inventory/move_stock.php') !== false) {
        return ['pages' => ['Inventory'], 'action' => 'edit', 'mode' => 'any'];
    }
    if (strpos($path, '/backend/public/inventory/delete.php') !== false) {
        return ['pages' => ['Inventory'], 'action' => 'edit', 'mode' => 'any'];
    }

    if (strpos($path, '/backend/public/assets/list.php') !== false) {
        return ['pages' => ['Assets'], 'action' => 'view', 'mode' => 'any'];
    }
    if (strpos($path, '/backend/public/assets/upsert.php') !== false) {
        return ['pages' => ['Assets'], 'action' => 'edit', 'mode' => 'any'];
    }
    if (strpos($path, '/backend/public/assets/delete.php') !== false) {
        return ['pages' => ['Assets'], 'action' => 'edit', 'mode' => 'any'];
    }

    if (strpos($path, '/backend/public/income/list.php') !== false) {
        return ['pages' => ['Income'], 'action' => 'view', 'mode' => 'any'];
    }
    if (strpos($path, '/backend/public/income/upsert.php') !== false) {
        return ['pages' => ['Income'], 'action' => 'edit', 'mode' => 'any'];
    }
    if (strpos($path, '/backend/public/income/delete.php') !== false) {
        return ['pages' => ['Income'], 'action' => 'edit', 'mode' => 'any'];
    }

    if (strpos($path, '/backend/public/clients/portal_manage_data.php') !== false) {
        return ['pages' => ['Portal Manage'], 'action' => 'view', 'mode' => 'any'];
    }
    if (strpos($path, '/backend/public/clients/portal_manage_save.php') !== false) {
        return ['pages' => ['Portal Manage'], 'action' => 'edit', 'mode' => 'any'];
    }
    if (strpos($path, '/backend/public/clients/portal_manage_user_action.php') !== false) {
        return ['pages' => ['Portal Manage'], 'action' => 'edit', 'mode' => 'any'];
    }
    if (strpos($path, '/backend/public/billing/list_invoices.php') !== false) {
        return ['pages' => ['Billing'], 'action' => 'view', 'mode' => 'any'];
    }
    if (strpos($path, '/backend/public/billing/list_clients.php') !== false) {
        return ['pages' => ['Billing'], 'action' => 'edit', 'mode' => 'any'];
    }
    if (strpos($path, '/backend/public/billing/create_invoice.php') !== false) {
        return ['pages' => ['Billing'], 'action' => 'edit', 'mode' => 'any'];
    }
    if (strpos($path, '/backend/public/billing/collect_payment.php') !== false) {
        return ['pages' => ['Billing'], 'action' => 'edit', 'mode' => 'any'];
    }
    if (strpos($path, '/backend/public/mikrotik/bulk_import_list.php') !== false) {
        return ['pages' => ['Bulk Client Import'], 'action' => 'view', 'mode' => 'any'];
    }
    if (strpos($path, '/backend/public/mikrotik/bulk_import_upload.php') !== false) {
        return ['pages' => ['Bulk Client Import'], 'action' => 'edit', 'mode' => 'any'];
    }
    if (strpos($path, '/backend/public/mikrotik/bulk_import_process.php') !== false) {
        return ['pages' => ['Bulk Client Import'], 'action' => 'edit', 'mode' => 'any'];
    }
    if (strpos($path, '/backend/public/mikrotik/bulk_import_clear.php') !== false) {
        return ['pages' => ['Bulk Client Import'], 'action' => 'edit', 'mode' => 'any'];
    }
    if (strpos($path, '/backend/public/mikrotik/bulk_import_delete.php') !== false) {
        return ['pages' => ['Bulk Client Import'], 'action' => 'edit', 'mode' => 'any'];
    }

    if (strpos($path, '/backend/public/leave/list.php') !== false) {
        return ['pages' => ['Leave Management'], 'action' => 'view', 'mode' => 'any'];
    }
    if (strpos($path, '/backend/public/leave/update_status.php') !== false) {
        return ['pages' => ['Leave Management'], 'action' => 'edit', 'mode' => 'any'];
    }
    if (strpos($path, '/backend/public/leave/apply_context.php') !== false) {
        return ['pages' => ['Apply Leave'], 'action' => 'view', 'mode' => 'any'];
    }
    if (strpos($path, '/backend/public/leave/upsert.php') !== false) {
        return ['pages' => ['Apply Leave'], 'action' => 'view', 'mode' => 'any'];
    }
    if (strpos($path, '/backend/public/leave/upload_attachment.php') !== false) {
        return ['pages' => ['Apply Leave'], 'action' => 'view', 'mode' => 'any'];
    }

    if (strpos($path, '/backend/public/events/list.php') !== false) {
        return ['pages' => ['Events & Holidays'], 'action' => 'view', 'mode' => 'any'];
    }
    if (strpos($path, '/backend/public/events/upsert.php') !== false) {
        return ['pages' => ['Events & Holidays'], 'action' => 'edit', 'mode' => 'any'];
    }
    if (strpos($path, '/backend/public/events/delete.php') !== false) {
        return ['pages' => ['Events & Holidays'], 'action' => 'edit', 'mode' => 'any'];
    }

    if (strpos($path, '/backend/public/attendance/dashboard.php') !== false) {
        return ['pages' => ['Attendance'], 'action' => 'view', 'mode' => 'any'];
    }
    if (strpos($path, '/backend/public/attendance/my_records.php') !== false) {
        return ['pages' => ['Attendance'], 'action' => 'view', 'mode' => 'any'];
    }
    if (strpos($path, '/backend/public/attendance/fingerprint.php') !== false) {
        return ['pages' => ['Attendance'], 'action' => 'edit', 'mode' => 'any'];
    }

    return null;
}

function is_accounts_payroll_override_allowed(array $user, string $scriptPath, string $action): bool
{
    $positionName = trim((string) ($user['position_name'] ?? ''));
    if (!in_array($positionName, ['Accounts Manager', 'Accounts Staff', 'Admin / Director'], true)) {
        return false;
    }

    $path = strtolower($scriptPath);
    if (strpos($path, '/backend/public/payroll/') !== false) {
        return in_array($action, ['view', 'edit'], true);
    }

    if (strpos($path, '/backend/public/payheads/list.php') !== false) {
        return $action === 'view';
    }

    if (strpos($path, '/backend/public/payheads/upsert.php') !== false || strpos($path, '/backend/public/payheads/delete.php') !== false) {
        return $action === 'edit';
    }

    if (strpos($path, '/backend/public/employees/list.php') !== false) {
        return $action === 'view';
    }

    return false;
}

function effective_module_permissions(array $user): array
{
    $rawPermissions = $user['module_permissions'] ?? [];
    $result = [];

    if (is_array($rawPermissions)) {
        foreach ($rawPermissions as $moduleName => $level) {
            $canonical = canonical_module_name((string) $moduleName);
            $normalized = normalize_permission_level_auth((string) $level);
            if ($canonical !== '' && $normalized !== 'none') {
                $result[$canonical] = $normalized;
            }
        }
    }

    if (!empty($result)) {
        return $result;
    }

    $accessModules = $user['access_modules'] ?? [];
    if (is_array($accessModules)) {
        foreach ($accessModules as $moduleName) {
            $canonical = canonical_module_name((string) $moduleName);
            if ($canonical !== '') {
                $result[$canonical] = 'full';
            }
        }
    }

    return $result;
}

function current_script_path(): string
{
    $script = (string) ($_SERVER['SCRIPT_NAME'] ?? $_SERVER['PHP_SELF'] ?? '');
    return str_replace('\\', '/', $script);
}

function module_from_script_path(string $scriptPath): ?string
{
    $path = strtolower($scriptPath);

    if (strpos($path, '/backend/public/clients/') !== false) {
        return 'Client';
    }

    if (strpos($path, '/backend/public/billing/') !== false || strpos($path, '/backend/public/protected/billing_') !== false) {
        return 'Billing';
    }

    if (strpos($path, '/backend/public/support/') !== false) {
        return 'Support & Ticketing';
    }

    if (strpos($path, '/backend/public/mikrotik/') !== false) {
        return 'Mikrotik Server';
    }

    if (strpos($path, '/backend/public/purchase/') !== false) {
        return 'Purchase';
    }

    if (strpos($path, '/backend/public/inventory/') !== false) {
        return 'Inventory';
    }

    if (strpos($path, '/backend/public/assets/') !== false) {
        return 'Assets';
    }

    if (strpos($path, '/backend/public/income/') !== false) {
        return 'Income';
    }

    if (strpos($path, '/backend/public/leave/') !== false) {
        return 'Leave Management';
    }

    if (strpos($path, '/backend/public/events/') !== false) {
        return 'Events & Holidays';
    }

    if (strpos($path, '/backend/public/attendance/') !== false) {
        return 'HR & Payroll';
    }

    if (strpos($path, '/backend/public/dashboard/') !== false) {
        return 'Dashboard';
    }

    if (
        strpos($path, '/backend/public/employees/') !== false ||
        strpos($path, '/backend/public/payroll/') !== false ||
        strpos($path, '/backend/public/payheads/') !== false ||
        strpos($path, '/backend/public/resignations/') !== false ||
        strpos($path, '/backend/public/packages/') !== false
    ) {
        return 'HR & Payroll';
    }

    return null;
}

function action_from_request(string $scriptPath, string $method): string
{
    $method = strtoupper(trim($method));
    $file = strtolower(basename($scriptPath));

    if ($method === 'GET') {
        return 'view';
    }

    if (
        strpos($file, 'list') !== false ||
        strpos($file, 'search') !== false ||
        strpos($file, 'get') !== false ||
        strpos($file, 'stats') !== false
    ) {
        return 'view';
    }

    return 'edit';
}

function normalize_permission_level_auth(string $level): string
{
    $value = strtolower(trim($level));
    if ($value === 'full' || $value === 'view' || $value === 'limited' || $value === 'none') {
        return $value;
    }
    return 'none';
}

function canonical_module_name(string $moduleName): string
{
    $raw = trim($moduleName);
    if ($raw === '') {
        return '';
    }

    $normalized = strtolower(preg_replace('/\s+/', ' ', $raw) ?? '');
    $aliases = [
        'purchase orders' => 'Purchase',
        'hr and payroll' => 'HR & Payroll',
        'support and ticketing' => 'Support & Ticketing',
    ];

    if (isset($aliases[$normalized])) {
        return $aliases[$normalized];
    }

    return $raw;
}

function module_allows_limited_write(string $moduleName): bool
{
    $canonical = canonical_module_name($moduleName);
    return in_array($canonical, ['Support & Ticketing', 'Client', 'Billing', 'HR & Payroll', 'Purchase', 'Inventory', 'Assets', 'Income', 'Leave Management', 'Events & Holidays', 'Mikrotik Server'], true);
}
