<?php

declare(strict_types=1);

require_once __DIR__ . '/../backend/config/database.php';
require_once __DIR__ . '/../backend/public/events/helpers.php';

try {
    $pdo = db();
    ensure_events_schema($pdo);

    $rows = [
        ['event_type' => 'holiday', 'event_date' => '2026-01-01', 'title' => 'New Year Day', 'description_text' => 'Public holiday observed nationwide.'],
        ['event_type' => 'holiday', 'event_date' => '2026-02-14', 'title' => 'Shab-e-Barat', 'description_text' => 'Government holiday for holy night observance.'],
        ['event_type' => 'holiday', 'event_date' => '2026-02-21', 'title' => 'Shaheed Day & International Mother Language Day', 'description_text' => 'National holiday in remembrance of Language Movement martyrs.'],
        ['event_type' => 'holiday', 'event_date' => '2026-03-17', 'title' => 'Birthday of Bangabandhu Sheikh Mujibur Rahman', 'description_text' => 'National celebration and official holiday.'],
        ['event_type' => 'holiday', 'event_date' => '2026-03-26', 'title' => 'Independence Day', 'description_text' => 'National holiday marking independence of Bangladesh.'],
        ['event_type' => 'holiday', 'event_date' => '2026-03-27', 'title' => 'Laylat al-Qadr (Shab-e-Qadr)', 'description_text' => 'Government holiday for religious observance.'],
        ['event_type' => 'holiday', 'event_date' => '2026-03-30', 'title' => 'Eid-ul-Fitr Holiday - Day 1', 'description_text' => 'First day of Eid-ul-Fitr government holiday.'],
        ['event_type' => 'holiday', 'event_date' => '2026-03-31', 'title' => 'Eid-ul-Fitr Holiday - Day 2', 'description_text' => 'Second day of Eid-ul-Fitr government holiday.'],
        ['event_type' => 'holiday', 'event_date' => '2026-04-01', 'title' => 'Eid-ul-Fitr Holiday - Day 3', 'description_text' => 'Third day of Eid-ul-Fitr government holiday.'],
        ['event_type' => 'holiday', 'event_date' => '2026-04-14', 'title' => 'Pohela Boishakh (Bengali New Year)', 'description_text' => 'National holiday celebrating Bengali New Year.'],
        ['event_type' => 'holiday', 'event_date' => '2026-05-01', 'title' => 'May Day', 'description_text' => 'International Workers Day public holiday.'],
        ['event_type' => 'holiday', 'event_date' => '2026-05-23', 'title' => 'Buddha Purnima', 'description_text' => 'Public holiday observed by Buddhist communities.'],
        ['event_type' => 'holiday', 'event_date' => '2026-06-07', 'title' => 'Eid-ul-Adha Holiday - Day 1', 'description_text' => 'First day of Eid-ul-Adha government holiday.'],
        ['event_type' => 'holiday', 'event_date' => '2026-06-08', 'title' => 'Eid-ul-Adha Holiday - Day 2', 'description_text' => 'Second day of Eid-ul-Adha government holiday.'],
        ['event_type' => 'holiday', 'event_date' => '2026-06-09', 'title' => 'Eid-ul-Adha Holiday - Day 3', 'description_text' => 'Third day of Eid-ul-Adha government holiday.'],
        ['event_type' => 'holiday', 'event_date' => '2026-07-17', 'title' => 'Ashura', 'description_text' => 'Government holiday for Muharram observance.'],
        ['event_type' => 'holiday', 'event_date' => '2026-08-15', 'title' => 'National Mourning Day', 'description_text' => 'National holiday commemorating Bangabandhu and family.'],
        ['event_type' => 'holiday', 'event_date' => '2026-09-16', 'title' => 'Eid-e-Milad-un-Nabi', 'description_text' => 'Public holiday for Eid-e-Milad-un-Nabi.'],
        ['event_type' => 'holiday', 'event_date' => '2026-10-23', 'title' => 'Durga Puja (Bijoya Dashami)', 'description_text' => 'Public holiday for Durga Puja.'],
        ['event_type' => 'holiday', 'event_date' => '2026-12-16', 'title' => 'Victory Day', 'description_text' => 'National holiday celebrating liberation victory.'],
        ['event_type' => 'holiday', 'event_date' => '2026-12-25', 'title' => 'Christmas Day', 'description_text' => 'Public holiday observed by Christian communities.'],

        ['event_type' => 'event', 'event_date' => '2026-01-05', 'title' => 'ISP Annual Strategy Kickoff', 'description_text' => 'Executive planning session for yearly network and service targets.'],
        ['event_type' => 'event', 'event_date' => '2026-01-20', 'title' => 'Core Router Firmware Audit', 'description_text' => 'Mikrotik firmware and security baseline review for all POP routers.'],
        ['event_type' => 'event', 'event_date' => '2026-02-10', 'title' => 'NOC Incident Response Drill', 'description_text' => 'Quarterly outage simulation for NOC and support teams.'],
        ['event_type' => 'event', 'event_date' => '2026-03-08', 'title' => 'Fiber Preventive Maintenance Window', 'description_text' => 'Scheduled backbone maintenance and signal quality checks.'],
        ['event_type' => 'event', 'event_date' => '2026-04-24', 'title' => 'Customer Experience Workshop', 'description_text' => 'Cross-team workshop on complaint handling and retention strategy.'],
        ['event_type' => 'event', 'event_date' => '2026-05-18', 'title' => 'Billing Cycle Validation Day', 'description_text' => 'Audit of invoice, payment, and reconnection workflows.'],
        ['event_type' => 'event', 'event_date' => '2026-06-20', 'title' => 'Mikrotik QoS Optimization Session', 'description_text' => 'Traffic shaping optimization for peak-hour bandwidth stability.'],
        ['event_type' => 'event', 'event_date' => '2026-07-26', 'title' => 'Field Technician Safety Training', 'description_text' => 'Safety and ladder protocol refresher for deployment teams.'],
        ['event_type' => 'event', 'event_date' => '2026-08-22', 'title' => 'Disaster Recovery Dry Run', 'description_text' => 'Failover rehearsal for billing and support services.'],
        ['event_type' => 'event', 'event_date' => '2026-09-12', 'title' => 'Cybersecurity Awareness Week Launch', 'description_text' => 'Security awareness kickoff for all employee modules and portals.'],
        ['event_type' => 'event', 'event_date' => '2026-10-10', 'title' => 'Regional POP Health Review', 'description_text' => 'Regional performance review of network nodes and uptime KPIs.'],
        ['event_type' => 'event', 'event_date' => '2026-11-14', 'title' => 'Year-End Client Retention Campaign', 'description_text' => 'Operations and CRM sync for retention and upgrade offers.'],
        ['event_type' => 'event', 'event_date' => '2026-12-05', 'title' => 'Staff Recognition & Awards', 'description_text' => 'Recognition event for high-performing departments and staff.'],
    ];

    $inserted = 0;
    $skipped = 0;

    $stmt = $pdo->prepare(
        'INSERT INTO events_holidays (title, event_type, event_date, description_text, created_by_employee_id, assigned_to_employee_id)
         SELECT :title, :event_type, :event_date, :description_text, NULL, NULL
         FROM DUAL
         WHERE NOT EXISTS (
            SELECT 1 FROM events_holidays
            WHERE title = :title_check AND event_date = :event_date_check
         )'
    );

    foreach ($rows as $row) {
        $stmt->execute([
            'title' => events_str_cut((string) $row['title'], 180),
            'event_type' => (string) $row['event_type'],
            'event_date' => (string) $row['event_date'],
            'description_text' => (string) $row['description_text'],
            'title_check' => (string) $row['title'],
            'event_date_check' => (string) $row['event_date'],
        ]);

        if ($stmt->rowCount() > 0) {
            $inserted++;
        } else {
            $skipped++;
        }
    }

    echo "Seed completed. Inserted: {$inserted}, Skipped(existing): {$skipped}" . PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, 'Seed failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
