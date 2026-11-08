<?php

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json(['ok' => false, 'message' => 'Method not allowed'], 405);
}

$user = require_explicit_page_permission('Resignation', 'edit');
$input = read_json_input();

$resignationId = clean_i($input, 'resignationId');
$employeeId = clean_i($input, 'employeeId');
$employeeCode = clean_s($input, 'employeeCode', 64);
$employeeName = clean_s($input, 'employeeName', 190);
$departmentName = clean_s($input, 'departmentName', 120);
$designationTitle = clean_s($input, 'designationTitle', 120);
$resignationType = clean_s($input, 'resignationType', 40);
$submittedOn = clean_s($input, 'submittedOn', 20);
$noticeDays = max(0, clean_i($input, 'noticeDays'));
$lastWorkingDate = clean_s($input, 'lastWorkingDate', 20);
$reasonText = clean_s($input, 'reasonText', 4000);
$handoverStatus = clean_s($input, 'handoverStatus', 30);
$assetClearanceStatus = clean_s($input, 'assetClearanceStatus', 30);
$financeClearanceStatus = clean_s($input, 'financeClearanceStatus', 30);
$hrClearanceStatus = clean_s($input, 'hrClearanceStatus', 30);
$exitInterviewDate = clean_s($input, 'exitInterviewDate', 20);
$finalSettlementStatus = clean_s($input, 'finalSettlementStatus', 30);
$statusLabel = clean_s($input, 'statusLabel', 30);
$remarksText = clean_s($input, 'remarksText', 4000);
$approvedOn = clean_s($input, 'approvedOn', 20);
$relievedOn = clean_s($input, 'relievedOn', 20);

if ($employeeName === '' || $submittedOn === '') {
    send_json([
        'ok' => false,
        'message' => 'employeeName and submittedOn are required',
    ], 422);
}

if ($resignationType === '') {
    $resignationType = 'Voluntary';
}
if (!in_array($resignationType, ['Voluntary', 'Retirement', 'Termination', 'Contract End'], true)) {
    send_json(['ok' => false, 'message' => 'Invalid resignationType'], 422);
}

if ($handoverStatus === '') {
    $handoverStatus = 'Pending';
}
if ($assetClearanceStatus === '') {
    $assetClearanceStatus = 'Pending';
}
if ($financeClearanceStatus === '') {
    $financeClearanceStatus = 'Pending';
}
if ($hrClearanceStatus === '') {
    $hrClearanceStatus = 'Pending';
}

$allowedFlow = ['Pending', 'In Progress', 'Done'];
foreach ([$handoverStatus, $assetClearanceStatus, $financeClearanceStatus, $hrClearanceStatus] as $flowStatus) {
    if (!in_array($flowStatus, $allowedFlow, true)) {
        send_json(['ok' => false, 'message' => 'Invalid clearance/handover status'], 422);
    }
}

if ($finalSettlementStatus === '') {
    $finalSettlementStatus = 'Pending';
}
if (!in_array($finalSettlementStatus, ['Pending', 'Processed', 'Paid'], true)) {
    send_json(['ok' => false, 'message' => 'Invalid finalSettlementStatus'], 422);
}

if ($statusLabel === '') {
    $statusLabel = 'Submitted';
}
if (!in_array($statusLabel, ['Submitted', 'Approved', 'Rejected', 'Relieved', 'Cancelled'], true)) {
    send_json(['ok' => false, 'message' => 'Invalid statusLabel'], 422);
}

try {
    $pdo = db();
    ensure_resignation_schema($pdo);

    $payload = [
        'employee_id' => $employeeId > 0 ? $employeeId : null,
        'employee_code' => null_if_empty($employeeCode),
        'employee_name' => $employeeName,
        'department_name' => null_if_empty($departmentName),
        'designation_title' => null_if_empty($designationTitle),
        'resignation_type' => $resignationType,
        'submitted_on' => $submittedOn,
        'notice_days' => $noticeDays,
        'last_working_date' => null_if_empty($lastWorkingDate),
        'reason_text' => null_if_empty($reasonText),
        'handover_status' => $handoverStatus,
        'asset_clearance_status' => $assetClearanceStatus,
        'finance_clearance_status' => $financeClearanceStatus,
        'hr_clearance_status' => $hrClearanceStatus,
        'exit_interview_date' => null_if_empty($exitInterviewDate),
        'knowledge_transfer_done' => clean_b($input, 'knowledgeTransferDone'),
        'final_settlement_amount' => clean_f($input, 'finalSettlementAmount'),
        'final_settlement_status' => $finalSettlementStatus,
        'status_label' => $statusLabel,
        'remarks_text' => null_if_empty($remarksText),
        'approved_on' => null_if_empty($approvedOn),
        'relieved_on' => null_if_empty($relievedOn),
        'updated_by' => (int) ($user['id'] ?? 0),
    ];

    if ($resignationId > 0) {
        $update = $pdo->prepare(
            'UPDATE hr_resignations SET
                employee_id = :employee_id,
                employee_code = :employee_code,
                employee_name = :employee_name,
                department_name = :department_name,
                designation_title = :designation_title,
                resignation_type = :resignation_type,
                submitted_on = :submitted_on,
                notice_days = :notice_days,
                last_working_date = :last_working_date,
                reason_text = :reason_text,
                handover_status = :handover_status,
                asset_clearance_status = :asset_clearance_status,
                finance_clearance_status = :finance_clearance_status,
                hr_clearance_status = :hr_clearance_status,
                exit_interview_date = :exit_interview_date,
                knowledge_transfer_done = :knowledge_transfer_done,
                final_settlement_amount = :final_settlement_amount,
                final_settlement_status = :final_settlement_status,
                status_label = :status_label,
                remarks_text = :remarks_text,
                approved_on = :approved_on,
                relieved_on = :relieved_on,
                updated_by = :updated_by
             WHERE id = :id'
        );
        $payload['id'] = $resignationId;
        $update->execute($payload);

        send_json([
            'ok' => true,
            'message' => 'Resignation updated successfully',
            'resignationId' => $resignationId,
        ]);
    }

    $insert = $pdo->prepare(
        'INSERT INTO hr_resignations (
            employee_id,
            employee_code,
            employee_name,
            department_name,
            designation_title,
            resignation_type,
            submitted_on,
            notice_days,
            last_working_date,
            reason_text,
            handover_status,
            asset_clearance_status,
            finance_clearance_status,
            hr_clearance_status,
            exit_interview_date,
            knowledge_transfer_done,
            final_settlement_amount,
            final_settlement_status,
            status_label,
            remarks_text,
            approved_on,
            relieved_on,
            created_by,
            updated_by
         ) VALUES (
            :employee_id,
            :employee_code,
            :employee_name,
            :department_name,
            :designation_title,
            :resignation_type,
            :submitted_on,
            :notice_days,
            :last_working_date,
            :reason_text,
            :handover_status,
            :asset_clearance_status,
            :finance_clearance_status,
            :hr_clearance_status,
            :exit_interview_date,
            :knowledge_transfer_done,
            :final_settlement_amount,
            :final_settlement_status,
            :status_label,
            :remarks_text,
            :approved_on,
            :relieved_on,
            :created_by,
            :updated_by
         )'
    );

    $payload['created_by'] = (int) ($user['id'] ?? 0);
    $insert->execute($payload);

    send_json([
        'ok' => true,
        'message' => 'Resignation created successfully',
        'resignationId' => (int) $pdo->lastInsertId(),
    ], 201);
} catch (Throwable $e) {
    send_json([
        'ok' => false,
        'message' => 'Failed to save resignation',
        'error' => $e->getMessage(),
    ], 500);
}
