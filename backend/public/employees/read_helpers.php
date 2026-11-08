<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/database.php';
require_once __DIR__ . '/access_matrix.php';

function fetch_employee_list_payload(PDO $pdo): array
{
    $sql =
        'SELECT
            e.id,
            e.employee_code,
            e.full_name,
            e.phone,
            e.email,
            e.join_date,
            e.basic_salary,
            e.employment_status,
            d.department_name,
            p.position_name,
            ep.role_name,
            ep.designation_title,
            ep.status_label,
            ep.gender,
            ep.nid,
            ep.dob,
            ep.blood_group,
            ep.employee_type,
            ep.emergency_phone,
            ep.emergency_name,
            ep.manager_name,
            ep.house_allowance,
            ep.medical_allowance,
            ep.transport_allowance,
            ep.bank_name,
            ep.bank_account,
            ep.education,
            ep.experience_years,
            ep.present_address,
            ep.permanent_address,
            ep.skills,
            ep.notes,
            ep.access_modules,
            ep.created_at AS profile_created_at
         FROM employees e
         LEFT JOIN departments d ON d.id = e.department_id
         LEFT JOIN positions p ON p.id = e.position_id
         LEFT JOIN employee_profiles ep ON ep.employee_id = e.id
         ORDER BY e.id DESC';

    $rows = $pdo->query($sql)->fetchAll();

    return array_map(static function (array $row): array {
        $nameParts = preg_split('/\s+/', trim((string) ($row['full_name'] ?? '')), 2);
        $firstName = $nameParts[0] ?? '';
        $lastName = $nameParts[1] ?? '';

        [$accessPermissionMap, $accessModules] = decode_employee_access_payload((string) ($row['access_modules'] ?? ''));

        return [
            'employeeDbId' => (int) ($row['id'] ?? 0),
            'employeeId' => (string) ($row['employee_code'] ?? $row['id']),
            'employeeCode' => (string) ($row['employee_code'] ?? ''),
            'employeeFirstName' => $firstName,
            'employeeLastName' => $lastName,
            'employeeRole' => (string) ($row['role_name'] ?? ''),
            'employeeDesignation' => (string) ($row['designation_title'] ?: ($row['position_name'] ?? '')),
            'employeeDepartment' => (string) ($row['department_name'] ?? ''),
            'employeeJoiningDate' => (string) ($row['join_date'] ?? ''),
            'employeeNid' => (string) ($row['nid'] ?? ''),
            'employeeDob' => (string) ($row['dob'] ?? ''),
            'employeeBloodGroup' => (string) ($row['blood_group'] ?? ''),
            'employeeType' => (string) ($row['employee_type'] ?? ''),
            'employeePhone' => (string) ($row['phone'] ?? ''),
            'employeeMail' => (string) ($row['email'] ?? ''),
            'employeeStatus' => (string) ($row['status_label'] ?: ($row['employment_status'] ?? 'Active')),
            'employeeGender' => (string) ($row['gender'] ?? ''),
            'employeePhoneNumber' => (string) ($row['phone'] ?? ''),
            'employeeEmergencyPhone' => (string) ($row['emergency_phone'] ?? ''),
            'employeeEmergencyName' => (string) ($row['emergency_name'] ?? ''),
            'employeeManager' => (string) ($row['manager_name'] ?? ''),
            'employeeSalary' => (float) ($row['basic_salary'] ?? 0),
            'employeeHouseAllowance' => (float) ($row['house_allowance'] ?? 0),
            'employeeMedicalAllowance' => (float) ($row['medical_allowance'] ?? 0),
            'employeeTransportAllowance' => (float) ($row['transport_allowance'] ?? 0),
            'employeeBankName' => (string) ($row['bank_name'] ?? ''),
            'employeeBankAccount' => (string) ($row['bank_account'] ?? ''),
            'employeeEducation' => (string) ($row['education'] ?? ''),
            'employeeExperience' => (int) ($row['experience_years'] ?? 0),
            'employeePresentAddress' => (string) ($row['present_address'] ?? ''),
            'employeePermanentAddress' => (string) ($row['permanent_address'] ?? ''),
            'employeeSkills' => (string) ($row['skills'] ?? ''),
            'employeeNotes' => (string) ($row['notes'] ?? ''),
            'employeeAccess' => $accessModules,
            'employeeAccessPermissions' => $accessPermissionMap,
            'createdAt' => (string) ($row['profile_created_at'] ?? ''),
        ];
    }, $rows);
}

function decode_employee_access_payload(string $rawValue): array
{
    $rawValue = trim($rawValue);
    if ($rawValue === '') {
        return [[], []];
    }

    $decoded = json_decode($rawValue, true);
    if (!is_array($decoded)) {
        return [[], []];
    }

    $accessMap = [];

    // Legacy shape: ["Module A", "Module B"]
    if (array_values($decoded) === $decoded) {
        foreach ($decoded as $item) {
            $moduleName = trim((string) $item);
            if ($moduleName !== '') {
                $accessMap[$moduleName] = 'full';
            }
        }
    } else {
        foreach ($decoded as $moduleName => $level) {
            $moduleName = trim((string) $moduleName);
            $normalizedLevel = strtolower(trim((string) $level));
            if ($normalizedLevel === 'limited') {
                $normalizedLevel = 'view';
            }
            if ($moduleName === '' || !in_array($normalizedLevel, ['full', 'view', 'none'], true)) {
                continue;
            }
            $accessMap[$moduleName] = $normalizedLevel;
        }
    }

    $accessModules = [];
    foreach ($accessMap as $moduleName => $level) {
        if ($level === 'full' || $level === 'view') {
            $accessModules[] = $moduleName;
        }
    }

    return [$accessMap, array_values(array_unique($accessModules))];
}

function fetch_department_payload(PDO $pdo): array
{
    ensure_department_access_table($pdo);

    $deptRows = $pdo->query(
        'SELECT d.id, d.department_name, COUNT(e.id) AS employee_count
         FROM departments d
         LEFT JOIN employees e ON e.department_id = d.id
         GROUP BY d.id, d.department_name
         ORDER BY d.department_name ASC'
    )->fetchAll();

    $accessRows = $pdo->query('SELECT department_id, module_name FROM department_access_modules ORDER BY module_name ASC')->fetchAll();
    $accessMap = [];
    foreach ($accessRows as $row) {
        $deptId = (int) $row['department_id'];
        if (!isset($accessMap[$deptId])) {
            $accessMap[$deptId] = [];
        }
        $accessMap[$deptId][] = (string) $row['module_name'];
    }

    return array_map(static function (array $row) use ($accessMap): array {
        $id = (int) $row['id'];
        return [
            'id' => $id,
            'name' => (string) $row['department_name'],
            'employee_count' => (int) ($row['employee_count'] ?? 0),
            'options' => $accessMap[$id] ?? [],
        ];
    }, $deptRows);
}

function fetch_position_payload(PDO $pdo): array
{
    ensure_position_access_table($pdo);

    $rows = $pdo->query(
        'SELECT p.id, p.position_name, d.department_name
         FROM positions p
         INNER JOIN departments d ON d.id = p.department_id
         ORDER BY d.department_name ASC, p.position_name ASC'
    )->fetchAll();

    $positions = array_map(static function (array $row): array {
        return [
            'id' => (int) $row['id'],
            'name' => (string) $row['position_name'],
            'department' => (string) $row['department_name'],
            'module_permissions' => [],
        ];
    }, $rows);

    foreach ($positions as $index => $position) {
        $positions[$index]['module_permissions'] = load_position_module_permissions($pdo, (int) $position['id']);
    }

    return $positions;
}

function fetch_next_employee_code_value(PDO $pdo): string
{
    $base = 2026001;

    $max = (int) $pdo->query(
        "SELECT COALESCE(MAX(CAST(employee_code AS UNSIGNED)), 0) FROM employees WHERE employee_code REGEXP '^[0-9]+$'"
    )->fetchColumn();

    return (string) max($base, $max + 1);
}