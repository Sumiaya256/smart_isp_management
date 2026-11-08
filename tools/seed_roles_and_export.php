<?php

declare(strict_types=1);

require __DIR__ . '/../backend/config/database.php';
require __DIR__ . '/../backend/public/employees/access_matrix.php';

$pdo = db();

$allModules = available_access_modules();
$deptMap = default_department_module_map();
$positionMatrix = default_position_permission_matrix();

function normalize_email_local(string $role): string
{
    $local = strtolower(trim($role));
    $local = preg_replace('/[^a-z0-9]+/', '.', $local);
    $local = trim((string) $local, '.');
    if ($local === '') {
        $local = 'employee';
    }
    return $local;
}

function flatten_permissions_for_report(array $permissions): string
{
    if (!$permissions) {
        return '';
    }

    $parts = [];
    foreach ($permissions as $module => $level) {
        $parts[] = $module . ' (' . $level . ')';
    }

    return implode('; ', $parts);
}

$reportRows = [];

try {
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
    $pdo->exec('DELETE FROM employee_profiles');
    $pdo->exec('DELETE FROM employees');
    $pdo->exec('DELETE FROM position_access_modules');
    $pdo->exec('DELETE FROM department_access_modules');
    $pdo->exec('DELETE FROM positions');
    $pdo->exec('DELETE FROM departments');
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

    $insertDept = $pdo->prepare('INSERT INTO departments (department_name) VALUES (:name)');
    $insertDeptAccess = $pdo->prepare('INSERT INTO department_access_modules (department_id, module_name) VALUES (:department_id, :module_name)');
    $insertPos = $pdo->prepare('INSERT INTO positions (department_id, position_name) VALUES (:department_id, :position_name)');

    $insertEmp = $pdo->prepare(
        'INSERT INTO employees
         (employee_code, full_name, phone, email, department_id, position_id, join_date, basic_salary, employment_status)
         VALUES
         (:employee_code, :full_name, :phone, :email, :department_id, :position_id, :join_date, :basic_salary, :employment_status)'
    );

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

    $departmentIds = [];
    foreach ($deptMap as $departmentName => $modules) {
        $insertDept->execute(['name' => $departmentName]);
        $departmentId = (int) $pdo->lastInsertId();
        $departmentIds[$departmentName] = $departmentId;

        foreach ($modules as $moduleName) {
            $insertDeptAccess->execute([
                'department_id' => $departmentId,
                'module_name' => $moduleName,
            ]);
        }
    }

    $passwordPlain = '123456';
    $passwordHash = password_hash($passwordPlain, PASSWORD_DEFAULT);
    $joinDate = date('Y-m-d');
    $counter = 1;

    foreach ($positionMatrix as $departmentName => $positions) {
        $departmentId = (int) ($departmentIds[$departmentName] ?? 0);
        if ($departmentId <= 0) {
            continue;
        }

        foreach ($positions as $positionName => $permissionMap) {
            $insertPos->execute([
                'department_id' => $departmentId,
                'position_name' => $positionName,
            ]);
            $positionId = (int) $pdo->lastInsertId();

            if (isset($permissionMap['*'])) {
                $wildLevel = normalize_permission_level((string) $permissionMap['*']);
                $effectivePermissions = [];
                foreach ($allModules as $m) {
                    $effectivePermissions[$m] = $wildLevel;
                }
            } else {
                $effectivePermissions = normalize_module_permissions($permissionMap);
            }

            save_position_module_permissions($pdo, $positionId, $effectivePermissions);
            $accessModules = module_permissions_to_access_modules($effectivePermissions);

            $code = 'EMP-' . str_pad((string) $counter, 3, '0', STR_PAD_LEFT);
            $local = normalize_email_local($positionName);
            $email = $local . '@role.test';
            $fullName = $positionName . ' Dummy';
            $phone = '017' . str_pad((string) $counter, 8, '0', STR_PAD_LEFT);
            $nid = 'DUMMY-NID-' . str_pad((string) $counter, 4, '0', STR_PAD_LEFT);

            $insertEmp->execute([
                'employee_code' => $code,
                'full_name' => $fullName,
                'phone' => $phone,
                'email' => $email,
                'department_id' => $departmentId,
                'position_id' => $positionId,
                'join_date' => $joinDate,
                'basic_salary' => 25000 + ($counter * 500),
                'employment_status' => 'active',
            ]);
            $employeeId = (int) $pdo->lastInsertId();

            $insertProfile->execute([
                'employee_id' => $employeeId,
                'role_name' => $positionName,
                'designation_title' => $positionName,
                'status_label' => 'Active',
                'gender' => 'Not Specified',
                'nid' => $nid,
                'dob' => '1995-01-01',
                'blood_group' => 'N/A',
                'employee_type' => 'Permanent',
                'emergency_phone' => $phone,
                'emergency_name' => $fullName,
                'manager_name' => 'System Admin',
                'house_allowance' => 0,
                'medical_allowance' => 0,
                'transport_allowance' => 0,
                'bank_name' => 'N/A',
                'bank_account' => 'N/A',
                'education' => 'N/A',
                'experience_years' => 2,
                'present_address' => 'Dummy Address',
                'permanent_address' => 'Dummy Address',
                'skills' => 'Testing',
                'notes' => 'Seeded test account',
                'access_modules' => json_encode(array_values($accessModules), JSON_UNESCAPED_UNICODE),
                'password_hash' => $passwordHash,
            ]);

            $reportRows[] = [
                'Employee Code' => $code,
                'Full Name' => $fullName,
                'Role' => $positionName,
                'Department' => $departmentName,
                'Email' => $email,
                'Password' => $passwordPlain,
                'Access Modules' => implode(', ', $accessModules),
                'Features (Permission Matrix)' => flatten_permissions_for_report($effectivePermissions),
            ];

            $counter++;
        }
    }

    $csvPath = dirname(__DIR__) . '/employee_role_test_credentials.csv';
    $fp = fopen($csvPath, 'wb');
    if ($fp === false) {
        throw new RuntimeException('Unable to create CSV file.');
    }

    if (!empty($reportRows)) {
        fputcsv($fp, array_keys($reportRows[0]));
        foreach ($reportRows as $row) {
            fputcsv($fp, array_values($row));
        }
    }
    fclose($fp);

    echo 'Seeded employees: ' . count($reportRows) . PHP_EOL;
    echo 'CSV export: ' . $csvPath . PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, 'Error: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
