<?php

declare(strict_types=1);

require_once __DIR__ . '/../backend/config/database.php';
require_once __DIR__ . '/../backend/public/inventory/helpers.php';
require_once __DIR__ . '/../backend/public/assets/helpers.php';
require_once __DIR__ . '/../backend/public/income/helpers.php';
require_once __DIR__ . '/../backend/public/purchase/helpers.php';
require_once __DIR__ . '/../backend/public/payheads/helpers.php';

function ensure_resignation_rules_schema_local(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS hr_resignation_rules (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            rule_name VARCHAR(140) NOT NULL,
            department_name VARCHAR(120) DEFAULT NULL,
            employee_type VARCHAR(80) DEFAULT NULL,
            min_tenure_months INT NOT NULL DEFAULT 0,
            notice_period_days INT NOT NULL DEFAULT 30,
            buyout_allowed TINYINT(1) NOT NULL DEFAULT 0,
            buyout_multiplier DECIMAL(8,2) NOT NULL DEFAULT 1.00,
            final_settlement_days INT NOT NULL DEFAULT 15,
            exit_interview_required TINYINT(1) NOT NULL DEFAULT 1,
            approvals_required TEXT DEFAULT NULL,
            status_label VARCHAR(20) NOT NULL DEFAULT "Active",
            description_text TEXT DEFAULT NULL,
            created_by BIGINT UNSIGNED DEFAULT NULL,
            updated_by BIGINT UNSIGNED DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_hr_resignation_rules_name (rule_name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
}

function fetch_actor_ids(PDO $pdo): array
{
    $stmt = $pdo->query('SELECT id, full_name FROM employees ORDER BY id ASC LIMIT 2');
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $first = isset($rows[0]['id']) ? (int) $rows[0]['id'] : null;
    $second = isset($rows[1]['id']) ? (int) $rows[1]['id'] : $first;
    $firstName = isset($rows[0]['full_name']) ? (string) $rows[0]['full_name'] : 'System User';

    return [
        'created_by' => $first,
        'assigned_to' => $second,
        'assigned_name' => $firstName,
    ];
}

function exists_by_column(PDO $pdo, string $table, string $column, string $value): bool
{
    $sql = sprintf('SELECT id FROM %s WHERE %s = :value LIMIT 1', $table, $column);
    $stmt = $pdo->prepare($sql);
    $stmt->execute(['value' => $value]);
    return (bool) $stmt->fetchColumn();
}

function fetch_id_by_column(PDO $pdo, string $table, string $column, string $value): ?int
{
    $sql = sprintf('SELECT id FROM %s WHERE %s = :value LIMIT 1', $table, $column);
    $stmt = $pdo->prepare($sql);
    $stmt->execute(['value' => $value]);
    $id = $stmt->fetchColumn();
    return $id !== false ? (int) $id : null;
}

$pdo = db();

ensure_inventory_schema($pdo);
ensure_assets_schema($pdo);
ensure_income_schema($pdo);
ensure_purchase_schema($pdo);
ensure_payheads_schema($pdo);
ensure_resignation_rules_schema_local($pdo);

$actors = fetch_actor_ids($pdo);
$createdBy = $actors['created_by'];
$assignedTo = $actors['assigned_to'];
$assignedName = $actors['assigned_name'];

$targetCount = 12;
$today = date('Y-m-d');

$report = [
    'inventory_items' => 0,
    'inventory_movements' => 0,
    'assets_items' => 0,
    'income_entries' => 0,
    'purchase_orders' => 0,
    'purchase_order_items' => 0,
    'hr_payheads' => 0,
    'hr_resignation_rules' => 0,
];

$pdo->beginTransaction();

try {
    $insertInventory = $pdo->prepare(
        'INSERT INTO inventory_items (
            item_code, item_name, category_name, unit_label,
            min_stock, current_stock, unit_cost, active_status,
            created_by_employee_id, assigned_to_employee_id
         ) VALUES (
            :item_code, :item_name, :category_name, :unit_label,
            :min_stock, :current_stock, :unit_cost, :active_status,
            :created_by_employee_id, :assigned_to_employee_id
         )'
    );

    $insertMovement = $pdo->prepare(
        'INSERT INTO inventory_movements (
            inventory_item_id, movement_type, quantity, unit_cost,
            reference_label, notes, created_by_employee_id, assigned_to_employee_id
         ) VALUES (
            :inventory_item_id, :movement_type, :quantity, :unit_cost,
            :reference_label, :notes, :created_by_employee_id, :assigned_to_employee_id
         )'
    );

    for ($i = 1; $i <= $targetCount; $i++) {
        $itemCode = sprintf('ITM-BULK-%03d', $i);
        $itemId = fetch_id_by_column($pdo, 'inventory_items', 'item_code', $itemCode);
        if ($itemId === null) {
            $unitCost = 500 + ($i * 75);
            $stock = 10 + ($i * 2);
            $insertInventory->execute([
                'item_code' => $itemCode,
                'item_name' => 'Dummy Inventory Item ' . $i,
                'category_name' => ($i % 2 === 0) ? 'Network Equipment' : 'Consumables',
                'unit_label' => 'pcs',
                'min_stock' => 5,
                'current_stock' => $stock,
                'unit_cost' => $unitCost,
                'active_status' => 1,
                'created_by_employee_id' => $createdBy,
                'assigned_to_employee_id' => $assignedTo,
            ]);
            $itemId = (int) $pdo->lastInsertId();
            $report['inventory_items']++;
        }

        $movementRef = sprintf('BULK-OPEN-%03d', $i);
        if (!exists_by_column($pdo, 'inventory_movements', 'reference_label', $movementRef)) {
            $insertMovement->execute([
                'inventory_item_id' => $itemId,
                'movement_type' => 'IN',
                'quantity' => 10 + ($i * 2),
                'unit_cost' => 500 + ($i * 75),
                'reference_label' => $movementRef,
                'notes' => 'Bulk dummy opening stock',
                'created_by_employee_id' => $createdBy,
                'assigned_to_employee_id' => $assignedTo,
            ]);
            $report['inventory_movements']++;
        }
    }

    $insertAsset = $pdo->prepare(
        'INSERT INTO assets_items (
            asset_tag, asset_name, type_name, purchase_date, purchase_value,
            assigned_to_name, status_label, notes, created_by_employee_id, assigned_to_employee_id
         ) VALUES (
            :asset_tag, :asset_name, :type_name, :purchase_date, :purchase_value,
            :assigned_to_name, :status_label, :notes, :created_by_employee_id, :assigned_to_employee_id
         )'
    );

    $assetStatuses = ['active', 'assigned', 'repair', 'spare', 'retired'];
    for ($i = 1; $i <= $targetCount; $i++) {
        $assetTag = sprintf('AST-BULK-%03d', $i);
        if (!exists_by_column($pdo, 'assets_items', 'asset_tag', $assetTag)) {
            $insertAsset->execute([
                'asset_tag' => $assetTag,
                'asset_name' => 'Dummy Asset ' . $i,
                'type_name' => ($i % 2 === 0) ? 'Laptop' : 'Router',
                'purchase_date' => date('Y-m-d', strtotime('-' . (20 + $i) . ' days')),
                'purchase_value' => 15000 + ($i * 1200),
                'assigned_to_name' => ($i % 2 === 0) ? $assignedName : 'Store Room',
                'status_label' => $assetStatuses[$i % count($assetStatuses)],
                'notes' => 'Bulk dummy asset seed #' . $i,
                'created_by_employee_id' => $createdBy,
                'assigned_to_employee_id' => $assignedTo,
            ]);
            $report['assets_items']++;
        }
    }

    $insertIncome = $pdo->prepare(
        'INSERT INTO income_entries (
            invoice_no, client_name, package_name, income_type, amount, paid_amount,
            due_date, status_label, payment_method, notes,
            created_by_employee_id, assigned_to_employee_id
         ) VALUES (
            :invoice_no, :client_name, :package_name, :income_type, :amount, :paid_amount,
            :due_date, :status_label, :payment_method, :notes,
            :created_by_employee_id, :assigned_to_employee_id
         )'
    );

    $incomeStatuses = ['paid', 'partial', 'pending'];
    $incomeTypes = ['Monthly Subscription', 'Installation Charge', 'Reconnect Fee', 'Device Sale'];
    for ($i = 1; $i <= $targetCount; $i++) {
        $invoiceNo = sprintf('INV-BULK-%03d', $i);
        if (!exists_by_column($pdo, 'income_entries', 'invoice_no', $invoiceNo)) {
            $amount = 2000 + ($i * 350);
            $status = $incomeStatuses[$i % count($incomeStatuses)];
            $paidAmount = $status === 'paid' ? $amount : ($status === 'partial' ? ($amount / 2) : 0);

            $insertIncome->execute([
                'invoice_no' => $invoiceNo,
                'client_name' => 'Dummy Client ' . $i,
                'package_name' => ($i % 2 === 0) ? 'Business 100 Mbps' : 'Home 40 Mbps',
                'income_type' => $incomeTypes[$i % count($incomeTypes)],
                'amount' => $amount,
                'paid_amount' => $paidAmount,
                'due_date' => date('Y-m-d', strtotime('+' . ($i * 3) . ' days')),
                'status_label' => $status,
                'payment_method' => ($i % 2 === 0) ? 'Bank Transfer' : 'Cash',
                'notes' => 'Bulk dummy income entry #' . $i,
                'created_by_employee_id' => $createdBy,
                'assigned_to_employee_id' => $assignedTo,
            ]);
            $report['income_entries']++;
        }
    }

    $insertPurchase = $pdo->prepare(
        'INSERT INTO purchase_orders (
            po_number, order_date, vendor_name, category_name, requested_by_name,
            delivery_date, status_label, notes, total_amount,
            created_by_employee_id, assigned_to_employee_id
         ) VALUES (
            :po_number, :order_date, :vendor_name, :category_name, :requested_by_name,
            :delivery_date, :status_label, :notes, :total_amount,
            :created_by_employee_id, :assigned_to_employee_id
         )'
    );

    $insertPurchaseItem = $pdo->prepare(
        'INSERT INTO purchase_order_items (
            purchase_order_id, item_name, quantity, unit_price, line_total
         ) VALUES (
            :purchase_order_id, :item_name, :quantity, :unit_price, :line_total
         )'
    );

    $poStatuses = ['Pending', 'Approved', 'Received', 'Partial', 'Cancelled'];
    for ($i = 1; $i <= $targetCount; $i++) {
        $poNumber = sprintf('PO-BULK-%03d', $i);
        $orderId = fetch_id_by_column($pdo, 'purchase_orders', 'po_number', $poNumber);

        if ($orderId === null) {
            $itemAQty = 5 + $i;
            $itemAPrice = 400 + ($i * 20);
            $itemBQty = 2 + $i;
            $itemBPrice = 2500 + ($i * 50);
            $total = ($itemAQty * $itemAPrice) + ($itemBQty * $itemBPrice);

            $insertPurchase->execute([
                'po_number' => $poNumber,
                'order_date' => date('Y-m-d', strtotime('-' . $i . ' days')),
                'vendor_name' => 'Dummy Vendor ' . $i,
                'category_name' => ($i % 2 === 0) ? 'Networking' : 'Electrical',
                'requested_by_name' => $assignedName,
                'delivery_date' => date('Y-m-d', strtotime('+' . (5 + $i) . ' days')),
                'status_label' => $poStatuses[$i % count($poStatuses)],
                'notes' => 'Bulk dummy purchase order #' . $i,
                'total_amount' => $total,
                'created_by_employee_id' => $createdBy,
                'assigned_to_employee_id' => $assignedTo,
            ]);

            $orderId = (int) $pdo->lastInsertId();
            $report['purchase_orders']++;

            $items = [
                ['name' => 'Dummy PO Item A ' . $i, 'qty' => $itemAQty, 'price' => $itemAPrice],
                ['name' => 'Dummy PO Item B ' . $i, 'qty' => $itemBQty, 'price' => $itemBPrice],
            ];

            foreach ($items as $item) {
                $lineTotal = $item['qty'] * $item['price'];
                $insertPurchaseItem->execute([
                    'purchase_order_id' => $orderId,
                    'item_name' => $item['name'],
                    'quantity' => $item['qty'],
                    'unit_price' => $item['price'],
                    'line_total' => $lineTotal,
                ]);
                $report['purchase_order_items']++;
            }
        }
    }

    $insertPayhead = $pdo->prepare(
        'INSERT INTO hr_payheads (
            payhead_code, payhead_name, payhead_type, payhead_category, calculation_type,
            default_value, percentage_base, percentage_rate, formula_expression, slab_definition,
            taxable, pf_applicable, esi_applicable, affect_attendance, pro_rata,
            is_recurring, visible_on_payslip, status_label, priority_order, max_limit,
            gl_code, effective_from, effective_to, description_text, created_by, updated_by
         ) VALUES (
            :payhead_code, :payhead_name, :payhead_type, :payhead_category, :calculation_type,
            :default_value, :percentage_base, :percentage_rate, :formula_expression, :slab_definition,
            :taxable, :pf_applicable, :esi_applicable, :affect_attendance, :pro_rata,
            :is_recurring, :visible_on_payslip, :status_label, :priority_order, :max_limit,
            :gl_code, :effective_from, :effective_to, :description_text, :created_by, :updated_by
         )'
    );

    for ($i = 1; $i <= $targetCount; $i++) {
        $payheadCode = sprintf('PH_BULK_%03d', $i);
        if (!exists_by_column($pdo, 'hr_payheads', 'payhead_code', $payheadCode)) {
            $isDeduction = ($i % 2 === 0);
            $calcType = ($i % 3 === 0) ? 'Percentage' : 'Fixed';
            $defaultValue = $calcType === 'Fixed' ? (300 + ($i * 50)) : 0;
            $percentageRate = $calcType === 'Percentage' ? (1.5 + ($i % 4)) : 0;

            $insertPayhead->execute([
                'payhead_code' => $payheadCode,
                'payhead_name' => 'Dummy Payhead ' . $i,
                'payhead_type' => $isDeduction ? 'Deduction' : 'Earning',
                'payhead_category' => $isDeduction ? 'Statutory' : 'Allowance',
                'calculation_type' => $calcType,
                'default_value' => $defaultValue,
                'percentage_base' => $calcType === 'Percentage' ? 'Basic' : null,
                'percentage_rate' => $percentageRate,
                'formula_expression' => null,
                'slab_definition' => null,
                'taxable' => $isDeduction ? 0 : 1,
                'pf_applicable' => $isDeduction ? 0 : 1,
                'esi_applicable' => 0,
                'affect_attendance' => $isDeduction ? 1 : 0,
                'pro_rata' => 1,
                'is_recurring' => 1,
                'visible_on_payslip' => 1,
                'status_label' => 'Active',
                'priority_order' => 100 + $i,
                'max_limit' => 10000,
                'gl_code' => 'GL-BULK-' . str_pad((string) $i, 3, '0', STR_PAD_LEFT),
                'effective_from' => $today,
                'effective_to' => null,
                'description_text' => 'Bulk dummy payhead seed #' . $i,
                'created_by' => $createdBy,
                'updated_by' => $createdBy,
            ]);
            $report['hr_payheads']++;
        }
    }

    $insertRule = $pdo->prepare(
        'INSERT INTO hr_resignation_rules (
            rule_name, department_name, employee_type, min_tenure_months, notice_period_days,
            buyout_allowed, buyout_multiplier, final_settlement_days, exit_interview_required,
            approvals_required, status_label, description_text, created_by, updated_by
         ) VALUES (
            :rule_name, :department_name, :employee_type, :min_tenure_months, :notice_period_days,
            :buyout_allowed, :buyout_multiplier, :final_settlement_days, :exit_interview_required,
            :approvals_required, :status_label, :description_text, :created_by, :updated_by
         )'
    );

    for ($i = 1; $i <= $targetCount; $i++) {
        $ruleName = sprintf('Dummy Bulk Resignation Rule %02d', $i);
        if (!exists_by_column($pdo, 'hr_resignation_rules', 'rule_name', $ruleName)) {
            $insertRule->execute([
                'rule_name' => $ruleName,
                'department_name' => ($i % 2 === 0) ? 'Operations / Field' : 'Procurement / Store',
                'employee_type' => ($i % 3 === 0) ? 'Contract' : 'Permanent',
                'min_tenure_months' => 3 + $i,
                'notice_period_days' => 15 + ($i % 3) * 15,
                'buyout_allowed' => ($i % 2 === 0) ? 1 : 0,
                'buyout_multiplier' => ($i % 2 === 0) ? 1.5 : 1.0,
                'final_settlement_days' => 10 + ($i % 4) * 5,
                'exit_interview_required' => ($i % 2 === 0) ? 1 : 0,
                'approvals_required' => json_encode(['Line Manager', 'HR', 'Accounts'], JSON_UNESCAPED_SLASHES),
                'status_label' => 'Active',
                'description_text' => 'Bulk dummy resignation rule #' . $i,
                'created_by' => $createdBy,
                'updated_by' => $createdBy,
            ]);
            $report['hr_resignation_rules']++;
        }
    }

    $pdo->commit();

    echo "Bulk dummy seed completed.\n";
    foreach ($report as $table => $count) {
        echo sprintf("%-24s : %d\n", $table, $count);
    }
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    fwrite(STDERR, 'Bulk dummy seed failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
