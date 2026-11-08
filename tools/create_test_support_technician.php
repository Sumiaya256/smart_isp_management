<?php

declare(strict_types=1);

require __DIR__ . '/../backend/config/database.php';
require __DIR__ . '/../backend/public/employees/access_matrix.php';

$pdo = db();

$positionStmt = $pdo->prepare(
    "SELECT p.id AS position_id, d.id AS department_id
     FROM positions p
     INNER JOIN departments d ON d.id = p.department_id
     WHERE d.department_name = 'Support' AND p.position_name = 'Technician'
     LIMIT 1"
);
$positionStmt->execute();
$pos = $positionStmt->fetch(PDO::FETCH_ASSOC);

if (!$pos) {
    echo "support_technician_position_not_found\n";
    exit(1);
}

$positionId = (int) ($pos['position_id'] ?? 0);
$departmentId = (int) ($pos['department_id'] ?? 0);

$next = (int) $pdo->query(
    "SELECT COALESCE(MAX(CAST(employee_code AS UNSIGNED)), 2026000) + 1
     FROM employees
     WHERE employee_code REGEXP '^[0-9]+$'"
)->fetchColumn();

$employeeCode = (string) $next;
$email = 'test.support.tech.' . $employeeCode . '@promee.internet';

$map = [];
$positionPermissions = load_position_module_permissions($pdo, $positionId);
foreach (available_access_modules() as $moduleName) {
    $map[$moduleName] = $positionPermissions[$moduleName] ?? 'none';
}

$pdo->beginTransaction();
try {
    $insEmp = $pdo->prepare(
        'INSERT INTO employees
        (employee_code, full_name, phone, email, department_id, position_id, join_date, basic_salary, employment_status)
        VALUES
        (:employee_code, :full_name, :phone, :email, :department_id, :position_id, :join_date, :basic_salary, :employment_status)'
    );
    $insEmp->execute([
        'employee_code' => $employeeCode,
        'full_name' => 'Test Technician',
        'phone' => '01795555556',
        'email' => $email,
        'department_id' => $departmentId,
        'position_id' => $positionId,
        'join_date' => '2026-04-11',
        'basic_salary' => 25000,
        'employment_status' => 'active',
    ]);

    $employeeId = (int) $pdo->lastInsertId();

    $insProfile = $pdo->prepare(
        'INSERT INTO employee_profiles
        (employee_id, role_name, designation_title, status_label, gender, nid, dob, blood_group, employee_type, emergency_phone, emergency_name,
         manager_name, house_allowance, medical_allowance, transport_allowance, bank_name, bank_account, education, experience_years,
         present_address, permanent_address, skills, notes, access_modules, password_hash)
        VALUES
        (:employee_id, :role_name, :designation_title, :status_label, :gender, :nid, :dob, :blood_group, :employee_type, :emergency_phone, :emergency_name,
         :manager_name, :house_allowance, :medical_allowance, :transport_allowance, :bank_name, :bank_account, :education, :experience_years,
         :present_address, :permanent_address, :skills, :notes, :access_modules, :password_hash)'
    );
    $insProfile->execute([
        'employee_id' => $employeeId,
        'role_name' => 'Support',
        'designation_title' => 'Technician',
        'status_label' => 'Active',
        'gender' => 'Male',
        'nid' => (string) (4000000000000 + $employeeId),
        'dob' => '1996-05-15',
        'blood_group' => 'B+',
        'employee_type' => 'Permanent',
        'emergency_phone' => '01890000001',
        'emergency_name' => 'Md. Karim',
        'manager_name' => 'Support Manager',
        'house_allowance' => 5000,
        'medical_allowance' => 2000,
        'transport_allowance' => 1500,
        'bank_name' => 'BRAC Bank',
        'bank_account' => (string) (600000000000 + $employeeId),
        'education' => 'Diploma',
        'experience_years' => 3,
        'present_address' => 'Mirpur, Dhaka',
        'permanent_address' => 'Cumilla, Bangladesh',
        'skills' => 'Fiber, Support',
        'notes' => 'Matrix verification test employee',
        'access_modules' => json_encode($map, JSON_UNESCAPED_UNICODE),
        'password_hash' => password_hash('123456', PASSWORD_DEFAULT),
    ]);

    $pdo->commit();

    echo 'created_employee_id=' . $employeeId . PHP_EOL;
    echo 'created_employee_code=' . $employeeCode . PHP_EOL;
    echo 'created_employee_email=' . $email . PHP_EOL;
    echo 'access_modules=' . json_encode($map, JSON_UNESCAPED_UNICODE) . PHP_EOL;
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, 'create_failed=' . $e->getMessage() . PHP_EOL);
    exit(1);
}
