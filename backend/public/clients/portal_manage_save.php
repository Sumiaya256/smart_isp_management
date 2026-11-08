<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/database.php';
require_once dirname(__DIR__, 2) . '/config/auth.php';
require_once __DIR__ . '/portal_manage_helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json(['ok' => false, 'message' => 'Method not allowed'], 405);
}

$user = require_page_permission('Portal Manage', 'edit');
$input = read_json_input();

try {
    $pdo = db();
    $settings = [
        'portal_enabled' => normalize_bool_setting($input['portal_enabled'] ?? true),
        'self_registration' => normalize_bool_setting($input['self_registration'] ?? true),
        'session_timeout' => max(5, (int) ($input['session_timeout'] ?? 30)),
        'strong_passwords' => normalize_bool_setting($input['strong_passwords'] ?? true),
        'two_factor' => normalize_bool_setting($input['two_factor'] ?? false),
        'email_notifications' => normalize_bool_setting($input['email_notifications'] ?? true),
        'sms_notifications' => normalize_bool_setting($input['sms_notifications'] ?? false),
        'payment_reminders' => normalize_bool_setting($input['payment_reminders'] ?? true),
        'access_hours' => trim((string) ($input['access_hours'] ?? '24/7')),
        'max_attempts' => max(3, (int) ($input['max_attempts'] ?? 5)),
        'lockout_duration' => max(5, (int) ($input['lockout_duration'] ?? 15)),
    ];
    upsert_client_portal_settings($pdo, $settings, (int) ($user['id'] ?? 0));
    send_json(['ok' => true, 'message' => 'Portal settings saved']);
} catch (Throwable $e) {
    send_json(['ok' => false, 'message' => 'Failed to save portal settings', 'error' => $e->getMessage()], 500);
}
