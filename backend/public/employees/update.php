<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/database.php';
require_once dirname(__DIR__, 2) . '/config/auth.php';
require_once __DIR__ . '/access_matrix.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json(['ok' => false, 'message' => 'Method not allowed'], 405);
}

require_page_permission('Employee List', 'edit');

$input = read_json_input();

$employeeCode = trim((string) ($input['employeeId'] ?? $input['employee_code'] ?? ''));
$firstName = trim((string) ($input['employeeFirstName'] ?? ''));
$lastName = trim((string) ($input['employeeLastName'] ?? ''));
$fullName = trim($firstName . ' ' . $lastName);
$employeeDepartment = trim((string) ($input['employeeDepartment'] ?? ''));
$employeeDesignation = trim((string) ($input['employeeDesignation'] ?? ''));
$employeeJoiningDate = trim((string) ($input['employeeJoiningDate'] ?? ''));
$employeePhone = trim((string) ($input['employeePhone'] ?? $input['employeePhoneNumber'] ?? ''));
$employeeMail = trim((string) ($input['employeeMail'] ?? ''));

if (
    $employeeCode === '' ||
    $firstName === '' ||
    $lastName === '' ||
    $employeeDepartment === '' ||
    $employeeDesignation === '' ||
    $employeeJoiningDate === '' ||
    $employeePhone === '' ||
    $employeeMail === ''
) {
    send_json(['ok' => false, 'message' => 'Required fields are missing'], 422);
}

$joinDate = normalize_date($employeeJoiningDate);
if ($joinDate === null) {
    send_json(['ok' => false, 'message' => 'Invalid joining date format'], 422);
}

$dob = normalize_date((string) ($input['employeeDob'] ?? ''));
$employeeStatusLabel = trim((string) ($input['employeeStatus'] ?? 'Active'));
$employmentStatus = map_employment_status($employeeStatusLabel);

$employeeSalary = (float) ($input['employeeSalary'] ?? 0);
$employeeHouseAllowance = (float) ($input['employeeHouseAllowance'] ?? 0);
$employeeMedicalAllowance = (float) ($input['employeeMedicalAllowance'] ?? 0);
$employeeTransportAllowance = (float) ($input['employeeTransportAllowance'] ?? 0);
$employeeExperience = (int) ($input['employeeExperience'] ?? 0);
$employeeNid = nullable_trim((string) ($input['employeeNid'] ?? ''));

$employeeAccessPermissions = normalize_employee_permission_map($input['employeeAccessPermissions'] ?? null);

try {
    $pdo = db();
    ensure_employee_profile_table($pdo);
    $pdo->beginTransaction();

    $findEmployee = $pdo->prepare('SELECT id FROM employees WHERE employee_code = :employee_code LIMIT 1');
    $findEmployee->execute(['employee_code' => $employeeCode]);
    $employee = $findEmployee->fetch();
    if (!$employee) {
        $pdo->rollBack();
        send_json(['ok' => false, 'message' => 'Employee not found'], 404);
    }

    $employeeId = (int) $employee['id'];

    $dupStmt = $pdo->prepare(
        'SELECT id
         FROM employees
         WHERE id <> :employee_id AND (email = :email OR phone = :phone)
         LIMIT 1'
    );
    $dupStmt->execute([
        'employee_id' => $employeeId,
        'email' => $employeeMail,
        'phone' => $employeePhone,
    ]);
    if ($dupStmt->fetch()) {
        $pdo->rollBack();
        send_json(['ok' => false, 'message' => 'Duplicate found: email or phone already exists'], 409);
    }

    if ($employeeNid !== null) {
        $nidStmt = $pdo->prepare(
            'SELECT employee_id
             FROM employee_profiles
             WHERE nid = :nid AND employee_id <> :employee_id
             LIMIT 1'
        );
        $nidStmt->execute([
            'nid' => $employeeNid,
            'employee_id' => $employeeId,
        ]);
        if ($nidStmt->fetch()) {
            $pdo->rollBack();
            send_json(['ok' => false, 'message' => 'Duplicate found: NID already exists'], 409);
        }
    }

    $departmentId = resolve_department_id($pdo, $employeeDepartment);
    $positionId = resolve_position_id($pdo, $departmentId, $employeeDesignation);

    $positionModulePermissions = load_position_module_permissions($pdo, $positionId);

    $updateEmployee = $pdo->prepare(
        'UPDATE employees
         SET full_name = :full_name,
             phone = :phone,
             email = :email,
             department_id = :department_id,
             position_id = :position_id,
             join_date = :join_date,
             basic_salary = :basic_salary,
             employment_status = :employment_status
         WHERE id = :id'
    );
    $updateEmployee->execute([
        'id' => $employeeId,
        'full_name' => $fullName,
        'phone' => $employeePhone,
        'email' => $employeeMail,
        'department_id' => $departmentId,
        'position_id' => $positionId,
        'join_date' => $joinDate,
        'basic_salary' => $employeeSalary,
        'employment_status' => $employmentStatus,
    ]);

    $existingProfile = $pdo->prepare('SELECT employee_id FROM employee_profiles WHERE employee_id = :employee_id LIMIT 1');
    $existingProfile->execute(['employee_id' => $employeeId]);

    if ($existingProfile->fetch()) {
        $updateProfile = $pdo->prepare(
            'UPDATE employee_profiles
             SET role_name = :role_name,
                 designation_title = :designation_title,
                 status_label = :status_label,
                 gender = :gender,
                 nid = :nid,
                 dob = :dob,
                 blood_group = :blood_group,
                 employee_type = :employee_type,
                 emergency_phone = :emergency_phone,
                 emergency_name = :emergency_name,
                 manager_name = :manager_name,
                 house_allowance = :house_allowance,
                 medical_allowance = :medical_allowance,
                 transport_allowance = :transport_allowance,
                 bank_name = :bank_name,
                 bank_account = :bank_account,
                 education = :education,
                 experience_years = :experience_years,
                 present_address = :present_address,
                 permanent_address = :permanent_address,
                 skills = :skills,
                 notes = :notes,
                 access_modules = :access_modules
             WHERE employee_id = :employee_id'
        );
    } else {
        $updateProfile = $pdo->prepare(
            'INSERT INTO employee_profiles
            (employee_id, role_name, designation_title, status_label, gender, nid, dob, blood_group, employee_type, emergency_phone, emergency_name,
             manager_name, house_allowance, medical_allowance, transport_allowance, bank_name, bank_account, education, experience_years,
             present_address, permanent_address, skills, notes, access_modules)
            VALUES
            (:employee_id, :role_name, :designation_title, :status_label, :gender, :nid, :dob, :blood_group, :employee_type, :emergency_phone, :emergency_name,
             :manager_name, :house_allowance, :medical_allowance, :transport_allowance, :bank_name, :bank_account, :education, :experience_years,
             :present_address, :permanent_address, :skills, :notes, :access_modules)'
        );
    }

    if (empty($employeeAccessPermissions)) {
        $accessModulesRaw = $input['employeeAccess'] ?? [];
        if (is_array($accessModulesRaw)) {
            foreach ($accessModulesRaw as $moduleName) {
                $moduleName = trim((string) $moduleName);
                if ($moduleName !== '') {
                    $employeeAccessPermissions[$moduleName] = 'full';
                }
            }
        }
    }

    if (empty($employeeAccessPermissions)) {
        $employeeAccessPermissions = normalize_employee_permission_map($positionModulePermissions);
    }

    if (empty($employeeAccessPermissions)) {
        $deptModules = load_department_access_modules($pdo, $departmentId);
        foreach ($deptModules as $moduleName) {
            $moduleName = trim((string) $moduleName);
            if ($moduleName !== '') {
                $employeeAccessPermissions[$moduleName] = 'full';
            }
        }
    }

    $employeeAccessPermissions = complete_employee_permission_map($employeeAccessPermissions);

    $updateProfile->execute([
        'employee_id' => $employeeId,
        'role_name' => nullable_trim((string) ($input['employeeRole'] ?? '')),
        'designation_title' => nullable_trim($employeeDesignation),
        'status_label' => nullable_trim($employeeStatusLabel),
        'gender' => nullable_trim((string) ($input['employeeGender'] ?? '')),
        'nid' => $employeeNid,
        'dob' => $dob,
        'blood_group' => nullable_trim((string) ($input['employeeBloodGroup'] ?? '')),
        'employee_type' => nullable_trim((string) ($input['employeeType'] ?? '')),
        'emergency_phone' => nullable_trim((string) ($input['employeePhoneNumber'] ?? $input['employeeEmergencyPhone'] ?? '')),
        'emergency_name' => nullable_trim((string) ($input['employeeEmergencyName'] ?? '')),
        'manager_name' => nullable_trim((string) ($input['employeeManager'] ?? '')),
        'house_allowance' => $employeeHouseAllowance,
        'medical_allowance' => $employeeMedicalAllowance,
        'transport_allowance' => $employeeTransportAllowance,
        'bank_name' => nullable_trim((string) ($input['employeeBankName'] ?? '')),
        'bank_account' => nullable_trim((string) ($input['employeeBankAccount'] ?? '')),
        'education' => nullable_trim((string) ($input['employeeEducation'] ?? '')),
        'experience_years' => max(0, $employeeExperience),
        'present_address' => nullable_trim((string) ($input['employeePresentAddress'] ?? '')),
        'permanent_address' => nullable_trim((string) ($input['employeePermanentAddress'] ?? '')),
        'skills' => nullable_trim((string) ($input['employeeSkills'] ?? '')),
        'notes' => nullable_trim((string) ($input['employeeNotes'] ?? '')),
        'access_modules' => !empty($employeeAccessPermissions) ? json_encode($employeeAccessPermissions, JSON_UNESCAPED_UNICODE) : null,
    ]);

    $pdo->commit();

    send_json([
        'ok' => true,
        'message' => 'Employee updated successfully',
        'employee_code' => $employeeCode,
    ]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    send_json([
        'ok' => false,
        'message' => 'Failed to update employee',
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

function load_department_access_modules(PDO $pdo, int $departmentId): array
{
    if ($departmentId <= 0 || !table_exists_local($pdo, 'department_access_modules')) {
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

function table_exists_local(PDO $pdo, string $tableName): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*)
         FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name'
    );
    $stmt->execute(['table_name' => $tableName]);
    return (int) $stmt->fetchColumn() > 0;
}
