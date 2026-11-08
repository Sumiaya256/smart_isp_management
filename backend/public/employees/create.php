<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/database.php';
require_once dirname(__DIR__, 2) . '/config/response.php';
require_once dirname(__DIR__, 2) . '/config/auth.php';
require_once __DIR__ . '/access_matrix.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json(['ok' => false, 'message' => 'Method not allowed'], 405);
}

require_page_permission('Add Employee', 'edit');

$input = read_json_input();

$employeeCode = trim((string) ($input['employeeId'] ?? ''));
$employeeFirstName = trim((string) ($input['employeeFirstName'] ?? ''));
$employeeLastName = trim((string) ($input['employeeLastName'] ?? ''));
$fullName = trim($employeeFirstName . ' ' . $employeeLastName);
$employeeDepartment = trim((string) ($input['employeeDepartment'] ?? ''));
$employeeDesignation = trim((string) ($input['employeeDesignation'] ?? ''));
$employeeJoiningDate = trim((string) ($input['employeeJoiningDate'] ?? ''));
$employeePhone = trim((string) ($input['employeePhone'] ?? $input['employeePhoneNumber'] ?? ''));
$employeeMail = trim((string) ($input['employeeMail'] ?? ''));
$employeePassword = (string) ($input['employeePassword'] ?? '');

if (
    $employeeCode === '' ||
    $employeeFirstName === '' ||
    $employeeLastName === '' ||
    $employeeDepartment === '' ||
    $employeeDesignation === '' ||
    $employeeJoiningDate === '' ||
    $employeePhone === '' ||
    $employeeMail === '' ||
    $employeePassword === ''
) {
    send_json(['ok' => false, 'message' => 'Required fields are missing'], 422);
}

if (strlen($employeePassword) < 6) {
    send_json(['ok' => false, 'message' => 'Password must be at least 6 characters long'], 422);
}

$joinDate = normalize_date($employeeJoiningDate);
if ($joinDate === null) {
    send_json(['ok' => false, 'message' => 'Invalid joining date format'], 422);
}

$dob = normalize_date((string) ($input['employeeDob'] ?? ''));
$dob = $dob ?? $joinDate;
$employeeStatusLabel = trim((string) ($input['employeeStatus'] ?? 'Active'));
$employmentStatus = map_employment_status($employeeStatusLabel);

$employeeSalary = (float) ($input['employeeSalary'] ?? 0);
$employeeHouseAllowance = (float) ($input['employeeHouseAllowance'] ?? 0);
$employeeMedicalAllowance = (float) ($input['employeeMedicalAllowance'] ?? 0);
$employeeTransportAllowance = (float) ($input['employeeTransportAllowance'] ?? 0);
$employeeExperience = (int) ($input['employeeExperience'] ?? 0);
$employeeNid = nullable_trim((string) ($input['employeeNid'] ?? ''));

$employeeRoleName = $employeeDepartment;

$accessModulesRaw = $input['employeeAccess'] ?? [];
$employeeAccessPermissions = normalize_employee_permission_map($input['employeeAccessPermissions'] ?? null);

try {
    $pdo = db();
    ensure_employee_profile_table($pdo);
    $pdo->beginTransaction();

    $dupStmt = $pdo->prepare(
        'SELECT employee_code, email, phone
         FROM employees
         WHERE employee_code = :employee_code OR email = :email OR phone = :phone
         LIMIT 1'
    );
    $dupStmt->execute([
        'employee_code' => $employeeCode,
        'email' => $employeeMail,
        'phone' => $employeePhone,
    ]);
    $existing = $dupStmt->fetch();

    if ($existing) {
        send_json(['ok' => false, 'message' => 'Duplicate found: employee code, email or phone already exists'], 409);
    }

    if ($employeeNid !== null) {
        $nidStmt = $pdo->prepare(
            'SELECT employee_id
             FROM employee_profiles
             WHERE nid = :nid
             LIMIT 1'
        );
        $nidStmt->execute(['nid' => $employeeNid]);
        if ($nidStmt->fetch()) {
            send_json(['ok' => false, 'message' => 'Duplicate found: NID already exists'], 409);
        }
    }

    $departmentId = resolve_department_id($pdo, $employeeDepartment);
    $positionId = resolve_position_id($pdo, $departmentId, $employeeDesignation);

    $positionModulePermissions = load_position_module_permissions($pdo, $positionId);

    if (empty($employeeAccessPermissions)) {
        $employeeAccessPermissions = normalize_employee_permission_map($positionModulePermissions);
    }

    if (empty($employeeAccessPermissions) && is_array($accessModulesRaw)) {
        foreach ($accessModulesRaw as $moduleName) {
            $moduleName = trim((string) $moduleName);
            if ($moduleName !== '') {
                $employeeAccessPermissions[$moduleName] = 'full';
            }
        }
        $employeeAccessPermissions = normalize_employee_permission_map($employeeAccessPermissions);
    }

    if (empty($employeeAccessPermissions)) {
        $departmentAccessModules = load_department_access_modules($pdo, $departmentId);
        foreach ($departmentAccessModules as $moduleName) {
            $moduleName = trim((string) $moduleName);
            if ($moduleName !== '') {
                $employeeAccessPermissions[$moduleName] = 'full';
            }
        }
        $employeeAccessPermissions = normalize_employee_permission_map($employeeAccessPermissions);
    }

    $employeeAccessPermissions = complete_employee_permission_map($employeeAccessPermissions);

    $insertEmployee = $pdo->prepare(
        'INSERT INTO employees
        (employee_code, full_name, phone, email, department_id, position_id, join_date, basic_salary, employment_status)
        VALUES
        (:employee_code, :full_name, :phone, :email, :department_id, :position_id, :join_date, :basic_salary, :employment_status)'
    );
    $insertEmployee->execute([
        'employee_code' => $employeeCode,
        'full_name' => $fullName,
        'phone' => $employeePhone,
        'email' => $employeeMail,
        'department_id' => $departmentId,
        'position_id' => $positionId,
        'join_date' => $joinDate,
        'basic_salary' => $employeeSalary,
        'employment_status' => $employmentStatus,
    ]);

    $employeeId = (int) $pdo->lastInsertId();

    $insertProfile = $pdo->prepare(
        'INSERT INTO employee_profiles
        (employee_id, role_name, designation_title, status_label, gender, nid, dob, blood_group, employee_type, emergency_phone, emergency_name,
         manager_name, house_allowance, medical_allowance, transport_allowance, bank_name, bank_account, education, experience_years,
         present_address, permanent_address, skills, notes, access_modules, password_hash)
        VALUES
        (:employee_id, :role_name, :designation_title, :status_label, :gender, :nid, :dob, :blood_group, :employee_type, :emergency_phone, :emergency_name,
         :manager_name, :house_allowance, :medical_allowance, :transport_allowance, :bank_name, :bank_account, :education, :experience_years,
         :present_address, :permanent_address, :skills, :notes, :access_modules, :password_hash)'
    );
    $insertProfile->execute([
        'employee_id' => $employeeId,
        'role_name' => $employeeRoleName,
        'designation_title' => nullable_trim($employeeDesignation) ?? 'N/A',
        'status_label' => nullable_trim($employeeStatusLabel) ?? 'Active',
        'gender' => nullable_trim((string) ($input['employeeGender'] ?? '')) ?? 'Not Specified',
        'nid' => $employeeNid,
        'dob' => $dob,
        'blood_group' => nullable_trim((string) ($input['employeeBloodGroup'] ?? '')) ?? 'N/A',
        'employee_type' => nullable_trim((string) ($input['employeeType'] ?? '')) ?? 'Permanent',
        'emergency_phone' => nullable_trim((string) ($input['employeeEmergencyPhone'] ?? '')) ?? $employeePhone,
        'emergency_name' => nullable_trim((string) ($input['employeeEmergencyName'] ?? '')) ?? $fullName,
        'manager_name' => nullable_trim((string) ($input['employeeManager'] ?? '')) ?? 'N/A',
        'house_allowance' => $employeeHouseAllowance,
        'medical_allowance' => $employeeMedicalAllowance,
        'transport_allowance' => $employeeTransportAllowance,
        'bank_name' => nullable_trim((string) ($input['employeeBankName'] ?? '')) ?? 'N/A',
        'bank_account' => nullable_trim((string) ($input['employeeBankAccount'] ?? '')) ?? 'N/A',
        'education' => nullable_trim((string) ($input['employeeEducation'] ?? '')) ?? 'N/A',
        'experience_years' => max(0, $employeeExperience),
        'present_address' => nullable_trim((string) ($input['employeePresentAddress'] ?? '')) ?? 'N/A',
        'permanent_address' => nullable_trim((string) ($input['employeePermanentAddress'] ?? '')) ?? 'N/A',
        'skills' => nullable_trim((string) ($input['employeeSkills'] ?? '')) ?? 'N/A',
        'notes' => nullable_trim((string) ($input['employeeNotes'] ?? '')) ?? 'N/A',
        'access_modules' => json_encode($employeeAccessPermissions, JSON_UNESCAPED_UNICODE),
        'password_hash' => password_hash($employeePassword, PASSWORD_DEFAULT),
    ]);

    $pdo->commit();

    send_json([
        'ok' => true,
        'message' => 'Employee saved to database successfully',
        'employee' => [
            'id' => $employeeId,
            'employee_code' => $employeeCode,
            'full_name' => $fullName,
        ],
    ], 201);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    send_json([
        'ok' => false,
        'message' => 'Failed to save employee to database',
        'error' => $e->getMessage(),
    ], 500);
}

function normalize_date(string $value): ?string
{
    $value = trim($value);
    if ($value === '') {
        return null;
    }

    $date = DateTime::createFromFormat('Y-m-d', $value);
    $errors = DateTime::getLastErrors();
    $warningCount = is_array($errors) ? (int) ($errors['warning_count'] ?? 0) : 0;
    $errorCount = is_array($errors) ? (int) ($errors['error_count'] ?? 0) : 0;

    if (!$date || $warningCount > 0 || $errorCount > 0) {
        return null;
    }

    return $date->format('Y-m-d');
}

function nullable_trim(string $value): ?string
{
    $trimmed = trim($value);
    return $trimmed === '' ? null : $trimmed;
}

function map_employment_status(string $statusLabel): string
{
    $status = strtolower(trim($statusLabel));
    if ($status === 'inactive' || $status === 'resigned' || $status === 'left') {
        return 'left';
    }

    return 'active';
}

function resolve_department_id(PDO $pdo, string $departmentName): int
{
    $departmentName = trim($departmentName);

    $find = $pdo->prepare('SELECT id FROM departments WHERE department_name = :name LIMIT 1');
    $find->execute(['name' => $departmentName]);
    $row = $find->fetch();

    if ($row) {
        return (int) $row['id'];
    }

    $insert = $pdo->prepare('INSERT INTO departments (department_name) VALUES (:name)');
    $insert->execute(['name' => $departmentName]);
    return (int) $pdo->lastInsertId();
}

function resolve_position_id(PDO $pdo, int $departmentId, string $positionName): int
{
    $positionName = trim($positionName);

    $find = $pdo->prepare(
        'SELECT id FROM positions
         WHERE department_id = :department_id AND position_name = :position_name
         LIMIT 1'
    );
    $find->execute([
        'department_id' => $departmentId,
        'position_name' => $positionName,
    ]);
    $row = $find->fetch();

    if ($row) {
        return (int) $row['id'];
    }

    $insert = $pdo->prepare('INSERT INTO positions (department_id, position_name) VALUES (:department_id, :position_name)');
    $insert->execute([
        'department_id' => $departmentId,
        'position_name' => $positionName,
    ]);
    return (int) $pdo->lastInsertId();
}

function load_department_access_modules(PDO $pdo, int $departmentId): array
{
    if ($departmentId <= 0) {
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

function normalize_employee_permission_level(string $level): string
{
    $value = strtolower(trim($level));
    if ($value === 'limited') {
        return 'view';
    }
    if ($value === 'full' || $value === 'view' || $value === 'none') {
        return $value;
    }
    return 'none';
}

function normalize_employee_permission_map($raw): array
{
    if (!is_array($raw)) {
        return [];
    }

    $allowed = array_flip(available_access_modules());
    $normalized = [];

    foreach ($raw as $moduleName => $level) {
        $moduleName = trim((string) $moduleName);
        if ($moduleName === '' || !isset($allowed[$moduleName])) {
            continue;
        }
        $normalized[$moduleName] = normalize_employee_permission_level((string) $level);
    }

    return $normalized;
}

function complete_employee_permission_map(array $raw): array
{
    $normalized = normalize_employee_permission_map($raw);
    $complete = [];
    foreach (available_access_modules() as $moduleName) {
        $complete[$moduleName] = $normalized[$moduleName] ?? 'none';
    }
    return $complete;
}

function ensure_employee_profile_table(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS employee_profiles (
            employee_id BIGINT UNSIGNED PRIMARY KEY,
            role_name VARCHAR(100) NULL,
            designation_title VARCHAR(120) NULL,
            status_label VARCHAR(40) NULL,
            gender VARCHAR(20) NULL,
            nid VARCHAR(50) NULL UNIQUE,
            dob DATE NULL,
            blood_group VARCHAR(10) NULL,
            employee_type VARCHAR(40) NULL,
            emergency_phone VARCHAR(30) NULL,
            emergency_name VARCHAR(120) NULL,
            manager_name VARCHAR(120) NULL,
            house_allowance DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            medical_allowance DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            transport_allowance DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            bank_name VARCHAR(120) NULL,
            bank_account VARCHAR(80) NULL,
            education VARCHAR(120) NULL,
            experience_years INT UNSIGNED NOT NULL DEFAULT 0,
            present_address VARCHAR(255) NULL,
            permanent_address VARCHAR(255) NULL,
            skills VARCHAR(255) NULL,
            notes TEXT NULL,
            access_modules JSON NULL,
            password_hash VARCHAR(255) NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            CONSTRAINT fk_employee_profiles_employee FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
        ) ENGINE=InnoDB'
    );
}