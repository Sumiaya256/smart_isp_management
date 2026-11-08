<?php

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    send_json(['ok' => false, 'message' => 'Method not allowed'], 405);
}

require_module_permission('HR & Payroll', 'view');

try {
    $pdo = db();
    ensure_resignation_schema($pdo);

    $rows = $pdo->query(
        'SELECT
            id,
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
            created_at,
            updated_at
         FROM hr_resignations
         ORDER BY submitted_on DESC, id DESC'
    )->fetchAll();

    $items = array_map(static function (array $row): array {
        return [
            'id' => (int) $row['id'],
            'employeeId' => $row['employee_id'] !== null ? (int) $row['employee_id'] : null,
            'employeeCode' => (string) ($row['employee_code'] ?? ''),
            'employeeName' => (string) ($row['employee_name'] ?? ''),
            'departmentName' => (string) ($row['department_name'] ?? ''),
            'designationTitle' => (string) ($row['designation_title'] ?? ''),
            'resignationType' => (string) ($row['resignation_type'] ?? 'Voluntary'),
            'submittedOn' => (string) ($row['submitted_on'] ?? ''),
            'noticeDays' => (int) ($row['notice_days'] ?? 0),
            'lastWorkingDate' => (string) ($row['last_working_date'] ?? ''),
            'reasonText' => (string) ($row['reason_text'] ?? ''),
            'handoverStatus' => (string) ($row['handover_status'] ?? 'Pending'),
            'assetClearanceStatus' => (string) ($row['asset_clearance_status'] ?? 'Pending'),
            'financeClearanceStatus' => (string) ($row['finance_clearance_status'] ?? 'Pending'),
            'hrClearanceStatus' => (string) ($row['hr_clearance_status'] ?? 'Pending'),
            'exitInterviewDate' => (string) ($row['exit_interview_date'] ?? ''),
            'knowledgeTransferDone' => (int) ($row['knowledge_transfer_done'] ?? 0) === 1,
            'finalSettlementAmount' => (float) ($row['final_settlement_amount'] ?? 0),
            'finalSettlementStatus' => (string) ($row['final_settlement_status'] ?? 'Pending'),
            'statusLabel' => (string) ($row['status_label'] ?? 'Submitted'),
            'remarksText' => (string) ($row['remarks_text'] ?? ''),
            'approvedOn' => (string) ($row['approved_on'] ?? ''),
            'relievedOn' => (string) ($row['relieved_on'] ?? ''),
            'createdAt' => (string) ($row['created_at'] ?? ''),
            'updatedAt' => (string) ($row['updated_at'] ?? ''),
        ];
    }, $rows);

    $summary = [
        'total' => count($items),
        'submitted' => 0,
        'approved' => 0,
        'rejected' => 0,
        'relieved' => 0,
        'pendingClearance' => 0,
    ];

    foreach ($items as $item) {
        $status = $item['statusLabel'];
        if ($status === 'Submitted') {
            $summary['submitted']++;
        } elseif ($status === 'Approved') {
            $summary['approved']++;
        } elseif ($status === 'Rejected') {
            $summary['rejected']++;
        } elseif ($status === 'Relieved') {
            $summary['relieved']++;
        }

        if (
            $item['handoverStatus'] !== 'Done' ||
            $item['assetClearanceStatus'] !== 'Done' ||
            $item['financeClearanceStatus'] !== 'Done' ||
            $item['hrClearanceStatus'] !== 'Done'
        ) {
            $summary['pendingClearance']++;
        }
    }

    send_json([
        'ok' => true,
        'resignations' => $items,
        'summary' => $summary,
    ]);
} catch (Throwable $e) {
    send_json([
        'ok' => false,
        'message' => 'Failed to fetch resignations',
        'error' => $e->getMessage(),
    ], 500);
}
