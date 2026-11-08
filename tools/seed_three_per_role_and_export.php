<?php
require __DIR__ . '/../backend/config/database.php';
require __DIR__ . '/../backend/public/employees/access_matrix.php';

$pdo = db();
$pdo->beginTransaction();

$allModules = available_access_modules();

$positions = $pdo->query("SELECT p.id AS position_id, p.position_name, d.department_name FROM positions p JOIN departments d ON d.id=p.department_id ORDER BY d.department_name, p.position_name")->fetchAll(PDO::FETCH_ASSOC);

$firstNamesMale = ['Rahim','Karim','Jahid','Arif','Sajid','Naim','Rashed','Sohan','Tanvir','Nayeem','Mahfuz','Imran','Shakil','Hasib','Ashik','Moin','Rafiq','Shamim','Farhan','Sabbir'];
$firstNamesFemale = ['Ayesha','Nusrat','Mim','Tanjina','Shila','Rupa','Sharmin','Jannat','Sadia','Samia','Lamisa','Riya','Maliha','Faria','Tahmina','Nabila','Iffat','Sumaia','Rumana','Nadia'];
$lastNames = ['Ahmed','Hossain','Islam','Khan','Rahman','Sarker','Chowdhury','Mia','Talukder','Uddin','Mahmud','Hasan','Kabir','Biswas','Mondol'];
$districts = ['Dhaka','Chattogram','Rajshahi','Khulna','Sylhet','Rangpur','Barishal','Mymensingh','Comilla','Gazipur'];
$areas = ['Mirpur','Uttara','Mohammadpur','Dhanmondi','Badda','Basabo','Sholokbahar','Kotwali','Shibganj','Sonadanga'];
$banks = ['Dutch-Bangla Bank','Islami Bank Bangladesh','BRAC Bank','City Bank','Eastern Bank','Sonali Bank'];
$skillsPool = ['Customer Handling','Fiber Maintenance','MikroTik','OLT/ONU Setup','Billing Operations','Inventory Tracking','CRM Reporting','Team Supervision','Network Troubleshooting','Field Operations'];

$insertEmployee = $pdo->prepare("INSERT INTO employees (employee_code, full_name, phone, email, department_id, position_id, join_date, basic_salary, employment_status) VALUES (:employee_code,:full_name,:phone,:email,:department_id,:position_id,:join_date,:basic_salary,'active')");
$insertProfile = $pdo->prepare("INSERT INTO employee_profiles (employee_id, role_name, designation_title, status_label, gender, nid, dob, blood_group, employee_type, emergency_phone, emergency_name, manager_name, house_allowance, medical_allowance, transport_allowance, bank_name, bank_account, education, experience_years, present_address, permanent_address, skills, notes, access_modules, password_hash) VALUES (:employee_id,:role_name,:designation_title,'Active',:gender,:nid,:dob,:blood_group,:employee_type,:emergency_phone,:emergency_name,:manager_name,:house_allowance,:medical_allowance,:transport_allowance,:bank_name,:bank_account,:education,:experience_years,:present_address,:permanent_address,:skills,:notes,:access_modules,:password_hash)");

$getPerms = $pdo->prepare("SELECT module_name, permission_level FROM position_access_modules WHERE position_id=:position_id ORDER BY module_name");

$csvPath = __DIR__ . '/../employee_role_access_seed_3_per_role.csv';
$csv = fopen($csvPath, 'w');
fputcsv($csv, ['Employee Code','Full Name','Role','Department','Position ID','Email','Password','Phone','NID','Join Date','Access Modules','Features (Permission Matrix)']);

$created = 0;
$skipped = 0;
$passwordHash = password_hash('123456', PASSWORD_DEFAULT);
$bloodGroups = ['A+','B+','O+','AB+','A-','B-','O-'];

foreach ($positions as $idx => $pos) {
    $positionId = (int)$pos['position_id'];
    $positionName = (string)$pos['position_name'];
    $departmentName = (string)$pos['department_name'];

    $deptStmt = $pdo->prepare('SELECT id FROM departments WHERE department_name = :name LIMIT 1');
    $deptStmt->execute(['name' => $departmentName]);
    $departmentId = (int)$deptStmt->fetchColumn();

    $getPerms->execute(['position_id' => $positionId]);
    $perms = $getPerms->fetchAll(PDO::FETCH_ASSOC);
    $modulePermissions = [];
    foreach ($allModules as $moduleName) {
        $modulePermissions[$moduleName] = 'none';
    }

    foreach ($perms as $p) {
        $moduleName = trim((string) ($p['module_name'] ?? ''));
        if ($moduleName === '' || !array_key_exists($moduleName, $modulePermissions)) {
            continue;
        }

        $level = strtolower(trim((string) ($p['permission_level'] ?? 'none')));
        if ($level === 'limited') {
            $level = 'view';
        }
        if ($level !== 'full' && $level !== 'view' && $level !== 'none') {
            $level = 'none';
        }

        $modulePermissions[$moduleName] = $level;
    }

    $accessModules = [];
    $featureMatrix = [];
    foreach ($allModules as $moduleName) {
        $level = $modulePermissions[$moduleName] ?? 'none';
        if ($level === 'full' || $level === 'view') {
            $accessModules[] = $moduleName;
        }
        $featureMatrix[] = $moduleName . ' (' . ($level === 'none' ? 'No Access' : $level) . ')';
    }

    for ($i = 1; $i <= 3; $i++) {
        $employeeCode = sprintf('EMP-R%03d-%02d', $positionId, $i);

        $check = $pdo->prepare('SELECT id FROM employees WHERE employee_code=:employee_code LIMIT 1');
        $check->execute(['employee_code' => $employeeCode]);
        $existingId = (int)$check->fetchColumn();

        $gender = (($idx + $i) % 4 === 0) ? 'Female' : 'Male';
        $firstList = $gender === 'Female' ? $firstNamesFemale : $firstNamesMale;
        $first = $firstList[($idx * 3 + $i) % count($firstList)];
        $last = $lastNames[($idx + $i * 2) % count($lastNames)];
        $fullName = $first . ' ' . $last;

        $emailUser = preg_replace('/[^a-z0-9]+/', '.', strtolower($first . '.' . $last . '.' . $positionId . $i));
        $emailUser = trim($emailUser, '.');
        $email = $emailUser . '@promee.internet';

        $phone = '01' . str_pad((string)(700000000 + ($positionId * 10 + $i)), 9, '0', STR_PAD_LEFT);
        $emergencyPhone = '01' . str_pad((string)(800000000 + ($positionId * 10 + $i)), 9, '0', STR_PAD_LEFT);
        $nid = (string)(1990000000000 + ($positionId * 100) + $i);

        $joinDate = sprintf('202%d-%02d-%02d', 3 + (($idx + $i) % 3), 1 + (($idx + $i) % 12), 1 + (($positionId + $i) % 27));
        $dob = sprintf('19%02d-%02d-%02d', 85 + (($idx + $i) % 12), 1 + (($i + 3) % 12), 1 + (($idx + 7) % 27));

        $exp = 2 + (($idx + $i) % 8);
        if (stripos($positionName, 'Director') !== false || stripos($positionName, 'Manager') !== false) {
            $exp += 4;
        }

        $salaryBase = 24000 + ($positionId % 10) * 1800 + $i * 400;
        if (stripos($positionName, 'Director') !== false) $salaryBase += 45000;
        if (stripos($positionName, 'Manager') !== false) $salaryBase += 18000;
        if (stripos($positionName, 'Senior') !== false) $salaryBase += 10000;
        if (stripos($positionName, 'Engineer') !== false || stripos($positionName, 'Technician') !== false) $salaryBase += 7000;

        $houseAllowance = round($salaryBase * 0.25, 2);
        $medicalAllowance = round($salaryBase * 0.10, 2);
        $transportAllowance = round($salaryBase * 0.08, 2);

        $district = $districts[($idx + $i) % count($districts)];
        $area = $areas[($idx + $i * 2) % count($areas)];
        $presentAddress = $area . ', ' . $district;
        $permanentAddress = 'Village ' . chr(65 + (($idx + $i) % 20)) . ', ' . $district;

        $bank = $banks[($idx + $i) % count($banks)];
        $bankAccount = (string)(100000000000 + ($positionId * 100) + $i);
        $education = 'Bachelor\'s';
        if (stripos($positionName, 'Director') !== false || stripos($positionName, 'Manager') !== false || stripos($positionName, 'Senior') !== false) {
            $education = 'Master\'s';
        }

        $employeeType = (($idx + $i) % 5 === 0) ? 'Contractual' : 'Permanent';
        $managerName = (stripos($positionName, 'Director') !== false) ? 'Board Oversight' : ($departmentName . ' Manager');
        $emergencyName = (($gender === 'Female') ? 'Mrs. ' : 'Md. ') . $last;
        $skills = $skillsPool[($idx + $i) % count($skillsPool)] . ', ' . $skillsPool[($idx + $i + 3) % count($skillsPool)];
        $notes = 'Generated for role-wise test dataset (3 per position).';

        if ($existingId > 0) {
            $skipped++;
            $employeeId = $existingId;
            $updEmp = $pdo->prepare('UPDATE employees SET full_name=:full_name, phone=:phone, email=:email, department_id=:department_id, position_id=:position_id, join_date=:join_date, basic_salary=:basic_salary, employment_status=\'active\' WHERE id=:id');
            $updEmp->execute([
                'full_name' => $fullName,
                'phone' => $phone,
                'email' => $email,
                'department_id' => $departmentId,
                'position_id' => $positionId,
                'join_date' => $joinDate,
                'basic_salary' => $salaryBase,
                'id' => $employeeId,
            ]);

            $upProf = $pdo->prepare("UPDATE employee_profiles SET role_name=:role_name, designation_title=:designation_title, status_label='Active', gender=:gender, nid=:nid, dob=:dob, blood_group=:blood_group, employee_type=:employee_type, emergency_phone=:emergency_phone, emergency_name=:emergency_name, manager_name=:manager_name, house_allowance=:house_allowance, medical_allowance=:medical_allowance, transport_allowance=:transport_allowance, bank_name=:bank_name, bank_account=:bank_account, education=:education, experience_years=:experience_years, present_address=:present_address, permanent_address=:permanent_address, skills=:skills, notes=:notes, access_modules=:access_modules, password_hash=:password_hash WHERE employee_id=:employee_id");
            $upProf->execute([
                'role_name' => $departmentName,
                'designation_title' => $positionName,
                'gender' => $gender,
                'nid' => $nid,
                'dob' => $dob,
                'blood_group' => $bloodGroups[($idx + $i) % count($bloodGroups)],
                'employee_type' => $employeeType,
                'emergency_phone' => $emergencyPhone,
                'emergency_name' => $emergencyName,
                'manager_name' => $managerName,
                'house_allowance' => $houseAllowance,
                'medical_allowance' => $medicalAllowance,
                'transport_allowance' => $transportAllowance,
                'bank_name' => $bank,
                'bank_account' => $bankAccount,
                'education' => $education,
                'experience_years' => $exp,
                'present_address' => $presentAddress,
                'permanent_address' => $permanentAddress,
                'skills' => $skills,
                'notes' => $notes,
                'access_modules' => json_encode($modulePermissions, JSON_UNESCAPED_UNICODE),
                'password_hash' => $passwordHash,
                'employee_id' => $employeeId,
            ]);
        } else {
            $insertEmployee->execute([
                'employee_code' => $employeeCode,
                'full_name' => $fullName,
                'phone' => $phone,
                'email' => $email,
                'department_id' => $departmentId,
                'position_id' => $positionId,
                'join_date' => $joinDate,
                'basic_salary' => $salaryBase,
            ]);
            $employeeId = (int)$pdo->lastInsertId();
            $created++;

            $insertProfile->execute([
                'employee_id' => $employeeId,
                'role_name' => $departmentName,
                'designation_title' => $positionName,
                'gender' => $gender,
                'nid' => $nid,
                'dob' => $dob,
                'blood_group' => $bloodGroups[($idx + $i) % count($bloodGroups)],
                'employee_type' => $employeeType,
                'emergency_phone' => $emergencyPhone,
                'emergency_name' => $emergencyName,
                'manager_name' => $managerName,
                'house_allowance' => $houseAllowance,
                'medical_allowance' => $medicalAllowance,
                'transport_allowance' => $transportAllowance,
                'bank_name' => $bank,
                'bank_account' => $bankAccount,
                'education' => $education,
                'experience_years' => $exp,
                'present_address' => $presentAddress,
                'permanent_address' => $permanentAddress,
                'skills' => $skills,
                'notes' => $notes,
                'access_modules' => json_encode($modulePermissions, JSON_UNESCAPED_UNICODE),
                'password_hash' => $passwordHash,
            ]);
        }

        fputcsv($csv, [
            $employeeCode,
            $fullName,
            $positionName,
            $departmentName,
            $positionId,
            $email,
            '123456',
            $phone,
            $nid,
            $joinDate,
            implode(', ', $accessModules),
            implode('; ', $featureMatrix),
        ]);
    }
}

fclose($csv);
$pdo->commit();

echo 'Created: ' . $created . PHP_EOL;
echo 'Updated existing role-seed rows: ' . $skipped . PHP_EOL;
echo 'CSV: employee_role_access_seed_3_per_role.csv' . PHP_EOL;
