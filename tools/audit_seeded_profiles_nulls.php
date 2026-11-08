<?php

declare(strict_types=1);

require __DIR__ . '/../backend/config/database.php';

$pdo = db();

$columns = [
    'role_name',
    'designation_title',
    'status_label',
    'gender',
    'nid',
    'dob',
    'blood_group',
    'employee_type',
    'emergency_phone',
    'emergency_name',
    'manager_name',
    'house_allowance',
    'medical_allowance',
    'transport_allowance',
    'bank_name',
    'bank_account',
    'education',
    'experience_years',
    'present_address',
    'permanent_address',
    'skills',
    'notes',
    'access_modules',
    'password_hash',
];

$totalStmt = $pdo->query("SELECT COUNT(*) FROM employees WHERE employee_code LIKE 'EMP-R%'");
$total = (int) $totalStmt->fetchColumn();

echo 'seeded_total=' . $total . PHP_EOL;

foreach ($columns as $col) {
    $sql = "SELECT COUNT(*)
            FROM employee_profiles ep
            INNER JOIN employees e ON e.id = ep.employee_id
            WHERE e.employee_code LIKE 'EMP-R%'
              AND (ep.$col IS NULL OR TRIM(CAST(ep.$col AS CHAR)) = '')";
    $stmt = $pdo->query($sql);
    $count = (int) $stmt->fetchColumn();
    echo $col . '_null_or_empty=' . $count . PHP_EOL;
}
