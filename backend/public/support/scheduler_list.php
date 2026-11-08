<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/database.php';
require_once dirname(__DIR__, 2) . '/config/auth.php';
require_once __DIR__ . '/scheduler_helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    send_json(['ok' => false, 'message' => 'Method not allowed'], 405);
}

$user = require_page_permission('Scheduler', 'view');
$monthInfo = scheduler_month_range((string) ($_GET['month'] ?? date('Y-m')));

try {
    $pdo = db();
    ensure_support_appointments_table($pdo);
    ensure_connection_requests_table($pdo);

    $scope = apply_scheduler_scope_where($user, 'sa', 'scope_employee_id');

    $appointmentsSql =
        'SELECT sa.id, sa.appointment_code, sa.request_id, sa.request_code, sa.client_name, sa.client_phone,
                sa.client_address, sa.appointment_type, sa.appointment_date, sa.appointment_time,
                sa.technician_employee_id, sa.technician_name, sa.priority, sa.status, sa.notes,
                sa.created_at, sa.updated_at
         FROM support_appointments sa
         WHERE sa.appointment_date BETWEEN :start_date AND :end_date';

    $appointmentsParams = [
        'start_date' => $monthInfo['start'],
        'end_date' => $monthInfo['end'],
    ];

    $appointmentsSql .= $scope['sql'];
    $appointmentsParams = array_merge($appointmentsParams, $scope['params']);
    $appointmentsSql .= ' ORDER BY sa.appointment_date ASC, sa.appointment_time ASC, sa.id DESC';

    $apptStmt = $pdo->prepare($appointmentsSql);
    $apptStmt->execute($appointmentsParams);
    $appointments = $apptStmt->fetchAll();

    $todaySql =
        'SELECT sa.id, sa.appointment_code, sa.request_id, sa.request_code, sa.client_name, sa.client_phone,
                sa.client_address, sa.appointment_type, sa.appointment_date, sa.appointment_time,
                sa.technician_employee_id, sa.technician_name, sa.priority, sa.status, sa.notes,
                sa.created_at, sa.updated_at
         FROM support_appointments sa
         WHERE sa.appointment_date = CURDATE()';

    $todayParams = [];
    $todayScope = apply_scheduler_scope_where($user, 'sa', 'today_scope_employee_id');
    $todaySql .= $todayScope['sql'];
    $todayParams = array_merge($todayParams, $todayScope['params']);
    $todaySql .= ' ORDER BY sa.appointment_time ASC, sa.id ASC';

    $todayStmt = $pdo->prepare($todaySql);
    $todayStmt->execute($todayParams);
    $todayAppointments = $todayStmt->fetchAll();

    $statsSql =
        'SELECT
            SUM(CASE WHEN sa.appointment_date = CURDATE() THEN 1 ELSE 0 END) AS today_count,
            SUM(CASE WHEN sa.status = "pending" THEN 1 ELSE 0 END) AS pending_count,
            SUM(CASE WHEN sa.status = "completed" THEN 1 ELSE 0 END) AS completed_count,
            COUNT(*) AS total_count
         FROM support_appointments sa
         WHERE 1 = 1';

    $statsParams = [];
    $statsScope = apply_scheduler_scope_where($user, 'sa', 'stats_scope_employee_id');
    $statsSql .= $statsScope['sql'];
    $statsParams = array_merge($statsParams, $statsScope['params']);

    $statsStmt = $pdo->prepare($statsSql);
    $statsStmt->execute($statsParams);
    $stats = $statsStmt->fetch() ?: [];

    $pendingRequestSql =
        'SELECT COUNT(*)
         FROM client_connection_requests r
         WHERE r.status = "pending"';
    $pendingRequestParams = [];
    if (is_limited_module_permission($user, 'Support & Ticketing')) {
        $pendingRequestSql .= ' AND (r.created_by_employee_id = :pending_scope_employee_id OR r.assigned_to_employee_id = :pending_scope_employee_id)';
        $pendingRequestParams['pending_scope_employee_id'] = (int) ($user['id'] ?? 0);
    }
    $pendingRequestStmt = $pdo->prepare($pendingRequestSql);
    $pendingRequestStmt->execute($pendingRequestParams);
    $pendingRequestCount = (int) $pendingRequestStmt->fetchColumn();

    $techSql =
        'SELECT e.id, e.employee_code, e.full_name, e.phone, e.email,
                COALESCE(pos.position_name, "") AS position_name,
                COUNT(CASE WHEN sa.appointment_date = CURDATE() AND sa.status IN ("pending", "scheduled", "in_progress") THEN 1 END) AS today_assigned
         FROM employees e
         LEFT JOIN departments d ON d.id = e.department_id
         LEFT JOIN positions pos ON pos.id = e.position_id
         LEFT JOIN employee_profiles ep ON ep.employee_id = e.id
         LEFT JOIN support_appointments sa ON sa.technician_employee_id = e.id
         WHERE LOWER(COALESCE(d.department_name, "")) = "support"
           AND LOWER(COALESCE(e.employment_status, "active")) = "active"
           AND (
                LOWER(COALESCE(pos.position_name, "")) LIKE "%technician%"
                OR LOWER(COALESCE(ep.role_name, "")) = "technician"
                OR LOWER(COALESCE(ep.designation_title, "")) LIKE "%technician%"
           )
         GROUP BY e.id, e.employee_code, e.full_name, e.phone, e.email, pos.position_name
         ORDER BY e.full_name ASC';

    $techStmt = $pdo->query($techSql);
    $techniciansRaw = $techStmt->fetchAll();
    $technicians = array_map(static function (array $row): array {
        $todayAssigned = (int) ($row['today_assigned'] ?? 0);
        return [
            'id' => (int) ($row['id'] ?? 0),
            'employee_code' => (string) ($row['employee_code'] ?? ''),
            'full_name' => (string) ($row['full_name'] ?? ''),
            'phone' => (string) ($row['phone'] ?? ''),
            'email' => (string) ($row['email'] ?? ''),
            'position_name' => (string) ($row['position_name'] ?? 'Technician'),
            'today_assigned' => $todayAssigned,
            'availability' => $todayAssigned >= 3 ? 'busy' : 'available',
        ];
    }, $techniciansRaw);

    $availableTechnicians = 0;
    foreach ($technicians as $tech) {
        if (($tech['availability'] ?? 'busy') === 'available') {
            $availableTechnicians += 1;
        }
    }

    $requests = fetch_pending_requests_for_scheduler($pdo, $user, 120);

    send_json([
        'ok' => true,
        'month' => $monthInfo['month'],
        'range' => [
            'start' => $monthInfo['start'],
            'end' => $monthInfo['end'],
        ],
        'stats' => [
            'today_appointments' => (int) ($stats['today_count'] ?? 0),
            'pending_schedule' => $pendingRequestCount,
            'available_technicians' => $availableTechnicians,
            'completed_appointments' => (int) ($stats['completed_count'] ?? 0),
            'total_appointments' => (int) ($stats['total_count'] ?? 0),
        ],
        'today_appointments' => $todayAppointments,
        'month_appointments' => $appointments,
        'technicians' => $technicians,
        'request_options' => $requests,
        'can_create' => user_has_page_action_access($user, 'Scheduler', 'edit'),
        'can_update_status' => user_has_page_action_access($user, 'Scheduler', 'edit'),
    ]);
} catch (Throwable $e) {
    send_json(['ok' => false, 'message' => 'Failed to load scheduler data', 'error' => $e->getMessage()], 500);
}
