<?php

declare(strict_types=1);

require __DIR__ . '/../backend/config/database.php';

$pdo = db();

$findEmployees = $pdo->query(
    "SELECT id, employee_code
     FROM employees
     WHERE employee_code LIKE 'EMP-R%'
     ORDER BY id ASC"
);
$employees = $findEmployees->fetchAll(PDO::FETCH_ASSOC);

if (empty($employees)) {
    echo "dummy_found=0" . PHP_EOL;
    echo "dummy_deleted=0" . PHP_EOL;
    exit(0);
}

$employeeIds = array_map(static fn ($row) => (int) ($row['id'] ?? 0), $employees);
$employeeIds = array_values(array_filter($employeeIds, static fn ($id) => $id > 0));

if (empty($employeeIds)) {
    echo "dummy_found=0" . PHP_EOL;
    echo "dummy_deleted=0" . PHP_EOL;
    exit(0);
}

$placeholders = implode(',', array_fill(0, count($employeeIds), '?'));

$refStmt = $pdo->query(
    "SELECT
        k.TABLE_NAME AS table_name,
        k.COLUMN_NAME AS column_name,
        c.IS_NULLABLE AS is_nullable
     FROM information_schema.KEY_COLUMN_USAGE k
     INNER JOIN information_schema.COLUMNS c
        ON c.TABLE_SCHEMA = k.TABLE_SCHEMA
       AND c.TABLE_NAME = k.TABLE_NAME
       AND c.COLUMN_NAME = k.COLUMN_NAME
     WHERE k.TABLE_SCHEMA = DATABASE()
       AND k.REFERENCED_TABLE_NAME = 'employees'
       AND k.TABLE_NAME <> 'employees'"
);
$references = $refStmt->fetchAll(PDO::FETCH_ASSOC);

$updatedToNull = 0;
$deletedDependents = 0;

$pdo->beginTransaction();
try {
    foreach ($references as $ref) {
        $table = (string) ($ref['table_name'] ?? '');
        $column = (string) ($ref['column_name'] ?? '');
        $nullable = strtoupper((string) ($ref['is_nullable'] ?? 'NO')) === 'YES';

        if ($table === '' || $column === '') {
            continue;
        }

        if ($nullable) {
            $sql = sprintf('UPDATE `%s` SET `%s` = NULL WHERE `%s` IN (%s)', $table, $column, $column, $placeholders);
            $stmt = $pdo->prepare($sql);
            $stmt->execute($employeeIds);
            $updatedToNull += $stmt->rowCount();
            continue;
        }

        $sql = sprintf('DELETE FROM `%s` WHERE `%s` IN (%s)', $table, $column, $placeholders);
        $stmt = $pdo->prepare($sql);
        $stmt->execute($employeeIds);
        $deletedDependents += $stmt->rowCount();
    }

    // Defensive cleanup for profile rows in case FK does not cascade.
    $profileDelete = $pdo->prepare(sprintf('DELETE FROM employee_profiles WHERE employee_id IN (%s)', $placeholders));
    $profileDelete->execute($employeeIds);
    $deletedDependents += $profileDelete->rowCount();

    $deleteEmployees = $pdo->prepare(sprintf('DELETE FROM employees WHERE id IN (%s)', $placeholders));
    $deleteEmployees->execute($employeeIds);
    $deletedEmployees = $deleteEmployees->rowCount();

    $pdo->commit();

    echo 'dummy_found=' . count($employeeIds) . PHP_EOL;
    echo 'references_nullified=' . $updatedToNull . PHP_EOL;
    echo 'dependent_rows_deleted=' . $deletedDependents . PHP_EOL;
    echo 'dummy_deleted=' . $deletedEmployees . PHP_EOL;
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    fwrite(STDERR, 'cleanup_failed=' . $e->getMessage() . PHP_EOL);
    exit(1);
}
