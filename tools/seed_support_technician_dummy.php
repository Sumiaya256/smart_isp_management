<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/backend/config/database.php';

$employeeCode = 'EMP-019';
$fullName = 'Technician Dummy';
$email = 'technician.support@role.test';
$phone = '01700000019';
$password = '123456';
$departmentName = 'Support';
$positionName = 'Technician';
$joinDate = date('Y-m-d');
$accessModules = ['Dashboard', 'Support & Ticketing', 'Task Management', 'Client'];
$modulePermissions = [
    'Dashboard' => 'view',
    'Support & Ticketing' => 'full',
    'Task Management' => 'limited',
    'Client' => 'view',
];

try {
    $pdo = db();
    if (!$pdo->inTransaction()) {
        $pdo->beginTransaction();
    }

    $deptStmt = $pdo->prepare('SELECT id FROM departments WHERE department_name = :name LIMIT 1');
    $deptStmt->execute(['name' => $departmentName]);
    $departmentId = (int) ($deptStmt->fetchColumn() ?: 0);
    if ($departmentId <= 0) {
        $insDept = $pdo->prepare('INSERT INTO departments (department_name) VALUES (:name)');
        $insDept->execute(['name' => $departmentName]);
        $departmentId = (int) $pdo->lastInsertId();
    }

    $posStmt = $pdo->prepare('SELECT id FROM positions WHERE department_id = :department_id AND position_name = :position_name LIMIT 1');
    $posStmt->execute([
        'department_id' => $departmentId,
        'position_name' => $positionName,
    ]);
    $positionId = (int) ($posStmt->fetchColumn() ?: 0);
    if ($positionId <= 0) {
        $insPos = $pdo->prepare('INSERT INTO positions (department_id, position_name) VALUES (:department_id, :position_name)');
        $insPos->execute([
            'department_id' => $departmentId,
            'position_name' => $positionName,
        ]);
        $positionId = (int) $pdo->lastInsertId();
    }

    $empStmt = $pdo->prepare('SELECT id FROM employees WHERE employee_code = :employee_code OR email = :email LIMIT 1');
    $empStmt->execute([
        'employee_code' => $employeeCode,
        'email' => $email,
    ]);
    $employeeId = (int) ($empStmt->fetchColumn() ?: 0);

    if ($employeeId <= 0) {
        $insEmp = $pdo->prepare(
            'INSERT INTO employees
            (employee_code, full_name, phone, email, department_id, position_id, join_date, basic_salary, employment_status)
            VALUES
            (:employee_code, :full_name, :phone, :email, :department_id, :position_id, :join_date, :basic_salary, :employment_status)'
        );
        $insEmp->execute([
            'employee_code' => $employeeCode,
            'full_name' => $fullName,
            'phone' => $phone,
            'email' => $email,
            'department_id' => $departmentId,
            'position_id' => $positionId,
            'join_date' => $joinDate,
            'basic_salary' => 30000,
            'employment_status' => 'active',
        ]);
        $employeeId = (int) $pdo->lastInsertId();
    } else {
        $updEmp = $pdo->prepare(
            'UPDATE employees
             SET full_name = :full_name,
                 phone = :phone,
                 department_id = :department_id,
                 position_id = :position_id,
                 employment_status = :employment_status
             WHERE id = :id'
        );
        $updEmp->execute([
            'full_name' => $fullName,
            'phone' => $phone,
            'department_id' => $departmentId,
            'position_id' => $positionId,
            'employment_status' => 'active',
            'id' => $employeeId,
        ]);
    }

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
            house_allowance DECIMAL(12,2) NOT NULL DEFAULT 0,
            medical_allowance DECIMAL(12,2) NOT NULL DEFAULT 0,
            transport_allowance DECIMAL(12,2) NOT NULL DEFAULT 0,
            bank_name VARCHAR(120) NULL,
            bank_account VARCHAR(120) NULL,
            education VARCHAR(255) NULL,
            experience_years INT NOT NULL DEFAULT 0,
            present_address VARCHAR(255) NULL,
            permanent_address VARCHAR(255) NULL,
            skills TEXT NULL,
            notes TEXT NULL,
            access_modules TEXT NULL,
            password_hash VARCHAR(255) NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );

    $profileExistsStmt = $pdo->prepare('SELECT employee_id FROM employee_profiles WHERE employee_id = :employee_id LIMIT 1');
    $profileExistsStmt->execute(['employee_id' => $employeeId]);
    $profileExists = (bool) $profileExistsStmt->fetchColumn();

    if ($profileExists) {
        $updProfile = $pdo->prepare(
            'UPDATE employee_profiles
             SET role_name = :role_name,
                 designation_title = :designation_title,
                 status_label = :status_label,
                 access_modules = :access_modules,
                 password_hash = :password_hash
             WHERE employee_id = :employee_id'
        );
        $updProfile->execute([
            'role_name' => 'Technician',
            'designation_title' => $positionName,
            'status_label' => 'Active',
            'access_modules' => json_encode($accessModules, JSON_UNESCAPED_UNICODE),
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'employee_id' => $employeeId,
        ]);
    } else {
        $insProfile = $pdo->prepare(
            'INSERT INTO employee_profiles
             (employee_id, role_name, designation_title, status_label, gender, emergency_phone, emergency_name, manager_name,
              bank_name, bank_account, education, present_address, permanent_address, skills, notes, access_modules, password_hash)
             VALUES
             (:employee_id, :role_name, :designation_title, :status_label, :gender, :emergency_phone, :emergency_name, :manager_name,
              :bank_name, :bank_account, :education, :present_address, :permanent_address, :skills, :notes, :access_modules, :password_hash)'
        );
        $insProfile->execute([
            'employee_id' => $employeeId,
            'role_name' => 'Technician',
            'designation_title' => $positionName,
            'status_label' => 'Active',
            'gender' => 'Not Specified',
            'emergency_phone' => $phone,
            'emergency_name' => $fullName,
            'manager_name' => 'Support Manager',
            'bank_name' => 'N/A',
            'bank_account' => 'N/A',
            'education' => 'N/A',
            'present_address' => 'N/A',
            'permanent_address' => 'N/A',
            'skills' => 'Field support, Router setup, ONU troubleshooting',
            'notes' => 'Dummy support technician for scheduler testing',
            'access_modules' => json_encode($accessModules, JSON_UNESCAPED_UNICODE),
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
        ]);
    }

    $pdo->exec('CREATE TABLE IF NOT EXISTS position_module_permissions (position_id BIGINT UNSIGNED NOT NULL, module_name VARCHAR(120) NOT NULL, permission_level VARCHAR(20) NOT NULL DEFAULT "view", PRIMARY KEY (position_id, module_name)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

    $delPerm = $pdo->prepare('DELETE FROM position_module_permissions WHERE position_id = :position_id');
    $delPerm->execute(['position_id' => $positionId]);

    $insPerm = $pdo->prepare('INSERT INTO position_module_permissions (position_id, module_name, permission_level) VALUES (:position_id, :module_name, :permission_level)');
    foreach ($modulePermissions as $moduleName => $permissionLevel) {
        $insPerm->execute([
            'position_id' => $positionId,
            'module_name' => $moduleName,
            'permission_level' => $permissionLevel,
        ]);
    }

    if ($pdo->inTransaction()) {
        $pdo->commit();
    }

    echo "Support technician dummy upserted successfully. Employee ID: {$employeeId}, Code: {$employeeCode}\n";
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, 'Error: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
