<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/database.php';
require_once dirname(__DIR__, 2) . '/config/auth.php';
require_once __DIR__ . '/scheduler_helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json(['ok' => false, 'message' => 'Method not allowed'], 405);
}

$user = require_page_permission('Scheduler', 'edit');
$input = read_json_input();

$requestId = (int) ($input['request_id'] ?? 0);
$clientName = trim((string) ($input['client_name'] ?? ''));
$clientPhone = trim((string) ($input['client_phone'] ?? ''));
$clientAddress = trim((string) ($input['client_address'] ?? ''));
$appointmentType = normalize_appointment_type((string) ($input['appointment_type'] ?? 'installation'));
$appointmentDate = trim((string) ($input['appointment_date'] ?? ''));
$appointmentTime = to_time_string((string) ($input['appointment_time'] ?? ''));
$technicianEmployeeId = (int) ($input['technician_employee_id'] ?? 0);
$priority = normalize_priority_level((string) ($input['priority'] ?? 'normal'));
$notes = trim((string) ($input['notes'] ?? ''));

if ($appointmentDate === '' || $appointmentTime === null || $technicianEmployeeId <= 0) {
    send_json(['ok' => false, 'message' => 'Date, time, and technician are required'], 422);
}

$dateObj = DateTime::createFromFormat('Y-m-d', $appointmentDate);
if (!$dateObj || $dateObj->format('Y-m-d') !== $appointmentDate) {
    send_json(['ok' => false, 'message' => 'Invalid appointment date format'], 422);
}

$requestCode = null;

try {
    $pdo = db();
    ensure_support_appointments_table($pdo);
    ensure_connection_requests_table($pdo);

    if ($requestId > 0) {
        $reqStmt = $pdo->prepare(
            'SELECT id, request_code, client_name, phone, address_line, status
             FROM client_connection_requests
             WHERE id = :id
             LIMIT 1'
        );
        $reqStmt->execute(['id' => $requestId]);
        $requestRow = $reqStmt->fetch();

        if (!$requestRow) {
            send_json(['ok' => false, 'message' => 'Request not found'], 404);
        }

        if (is_limited_module_permission($user, 'Support & Ticketing')) {
            $scopeEmployeeId = (int) ($user['id'] ?? 0);
            $scopeCheck = $pdo->prepare(
                'SELECT id
                 FROM client_connection_requests
                 WHERE id = :id
                   AND (created_by_employee_id = :scope_employee_id OR assigned_to_employee_id = :scope_employee_id)
                 LIMIT 1'
            );
            $scopeCheck->execute([
                'id' => $requestId,
                'scope_employee_id' => $scopeEmployeeId,
            ]);
            if (!$scopeCheck->fetch()) {
                send_json(['ok' => false, 'message' => 'Forbidden: limited access allows only own/assigned requests'], 403);
            }
        }

        $clientName = $clientName !== '' ? $clientName : (string) ($requestRow['client_name'] ?? '');
        $clientPhone = $clientPhone !== '' ? $clientPhone : (string) ($requestRow['phone'] ?? '');
        $clientAddress = $clientAddress !== '' ? $clientAddress : (string) ($requestRow['address_line'] ?? '');
        $requestCode = (string) ($requestRow['request_code'] ?? '');
    }

    if ($clientName === '' || $clientPhone === '' || $clientAddress === '') {
        send_json(['ok' => false, 'message' => 'Client name, phone, and address are required'], 422);
    }

    $techStmt = $pdo->prepare(
        'SELECT e.id, e.full_name
         FROM employees e
         LEFT JOIN departments d ON d.id = e.department_id
         LEFT JOIN positions pos ON pos.id = e.position_id
         LEFT JOIN employee_profiles ep ON ep.employee_id = e.id
         WHERE e.id = :id
           AND LOWER(COALESCE(d.department_name, "")) = "support"
           AND LOWER(COALESCE(e.employment_status, "active")) = "active"
           AND (
                LOWER(COALESCE(pos.position_name, "")) LIKE "%technician%"
                OR LOWER(COALESCE(ep.role_name, "")) = "technician"
                OR LOWER(COALESCE(ep.designation_title, "")) LIKE "%technician%"
           )
         LIMIT 1'
    );
    $techStmt->execute(['id' => $technicianEmployeeId]);
    $technician = $techStmt->fetch();

    if (!$technician) {
        send_json(['ok' => false, 'message' => 'Invalid technician selected'], 422);
    }

    $scopeEmployeeId = (int) ($user['id'] ?? 0);

    $pdo->beginTransaction();

    $code = generate_appointment_code($pdo);
    $insert = $pdo->prepare(
        'INSERT INTO support_appointments
        (appointment_code, request_id, request_code, client_name, client_phone, client_address,
         appointment_type, appointment_date, appointment_time, technician_employee_id, technician_name,
         priority, status, notes, created_by_employee_id, assigned_to_employee_id)
        VALUES
        (:appointment_code, :request_id, :request_code, :client_name, :client_phone, :client_address,
         :appointment_type, :appointment_date, :appointment_time, :technician_employee_id, :technician_name,
         :priority, :status, :notes, :created_by_employee_id, :assigned_to_employee_id)'
    );

    $insert->execute([
        'appointment_code' => $code,
        'request_id' => $requestId > 0 ? $requestId : null,
        'request_code' => $requestCode,
        'client_name' => mb_substr($clientName, 0, 150),
        'client_phone' => mb_substr($clientPhone, 0, 40),
        'client_address' => mb_substr($clientAddress, 0, 255),
        'appointment_type' => $appointmentType,
        'appointment_date' => $appointmentDate,
        'appointment_time' => $appointmentTime,
        'technician_employee_id' => $technicianEmployeeId,
        'technician_name' => (string) ($technician['full_name'] ?? ''),
        'priority' => $priority,
        'status' => 'scheduled',
        'notes' => $notes !== '' ? mb_substr($notes, 0, 5000) : null,
        'created_by_employee_id' => $scopeEmployeeId > 0 ? $scopeEmployeeId : null,
        'assigned_to_employee_id' => $technicianEmployeeId,
    ]);

    $appointmentId = (int) $pdo->lastInsertId();

    if ($requestId > 0) {
        $reqUpdate = $pdo->prepare(
            'UPDATE client_connection_requests
             SET status = "scheduled", assigned_to_employee_id = :assigned_to_employee_id
             WHERE id = :id'
        );
        $reqUpdate->execute([
            'assigned_to_employee_id' => $technicianEmployeeId,
            'id' => $requestId,
        ]);
    }

    $pdo->commit();

    send_json([
        'ok' => true,
        'message' => 'Appointment scheduled successfully',
        'appointment' => [
            'id' => $appointmentId,
            'appointment_code' => $code,
            'client_name' => $clientName,
            'appointment_date' => $appointmentDate,
            'appointment_time' => $appointmentTime,
            'technician_name' => (string) ($technician['full_name'] ?? ''),
            'status' => 'scheduled',
        ],
    ], 201);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    send_json(['ok' => false, 'message' => 'Failed to schedule appointment', 'error' => $e->getMessage()], 500);
}
