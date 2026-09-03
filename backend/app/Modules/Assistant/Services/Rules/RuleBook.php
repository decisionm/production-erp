<?php

namespace App\Modules\Assistant\Services\Rules;

/**
 * Every question Ask ERP can answer with no model and no API key.
 *
 * WHY THIS EXISTS. The reference the owner pointed at (s4_agentic) has no LLM
 * either: its parser maps keywords onto fixed templates over mock JSON, and
 * says so in its own header. This is the same idea aimed at the real tables —
 * which makes the page work on a server with no key and no bill, and keeps
 * working when a key runs out of credit.
 *
 * WHAT A RULE MAY NOT DO. No rule names a money column — no rate, cost,
 * amount or salary anywhere. The reason is precise: SqlGuard strips hidden
 * columns only for the tables the retriever happened to rank into this
 * question's specs, while a rule's SQL may touch a table it never ranked. A
 * rule that selected `average_cost` could therefore slip a cost past FC-06
 * for a reader with no finance permission. Keeping money out of the rule book
 * entirely is the version of that guarantee a test can enforce, and
 * RuleBookTest does.
 *
 * PERMISSION IS STILL THE GUARD'S JOB. Every rule's SQL goes through
 * SqlGuard against the reader's full allowed-table set exactly as a model's
 * would, so a storekeeper asking a sales question is refused by the same
 * machinery, not by anything here.
 */
final class RuleBook
{
    /** @return list<QuestionRule> */
    public static function all(): array
    {
        return [
            ...self::inventory(),
            ...self::procurement(),
            ...self::production(),
            ...self::sales(),
            ...self::attendance(),
        ];
    }

    /**
     * The questions THIS reader can actually get an answer to — a rule counts
     * only when every table it touches is one they may see.
     *
     * This is what the page offers instead of the table list. Chips reading
     * "GRN Schedule Allocations" and "Store Issue Bag Scans" told a
     * supervisor nothing about what to type, and an Administrator holding
     * every permission saw all 122 of them at once. A question you can click
     * and send is the useful version of the same information, and hiding the
     * ones that would only be refused is the honest version.
     *
     * @param  list<string>  $allowedTables
     * @return list<string>
     */
    public static function examplesFor(array $allowedTables): array
    {
        $allowed = array_flip($allowedTables);
        $examples = [];

        foreach (self::all() as $rule) {
            if ($rule->example === '') {
                continue;
            }
            foreach ($rule->tables as $table) {
                if (! isset($allowed[$table])) {
                    continue 2;
                }
            }
            $examples[] = $rule->example;
        }

        return $examples;
    }

    /** @return list<QuestionRule> */
    private static function inventory(): array
    {
        return [
            new QuestionRule(
                key: 'stock_on_hand',
                example: 'How much stock do we have?',
                label: 'Stock on hand by item',
                keywords: ['stock on hand', 'stock in hand', 'how much stock', 'stock level', 'current stock', 'stock'],
                tables: ['stock_balances', 'items', 'warehouses'],
                sql: 'SELECT i.sku AS sku, i.name AS item, w.name AS warehouse, ROUND(SUM(sb.quantity), 3) AS quantity, i.uom AS uom
FROM stock_balances sb
JOIN items i ON i.id = sb.item_id
JOIN warehouses w ON w.id = sb.warehouse_id
WHERE i.deleted_at IS NULL AND w.deleted_at IS NULL AND sb.quantity <> 0
GROUP BY i.sku, i.name, w.name, i.uom
ORDER BY quantity DESC
LIMIT 200',
                answerTemplate: '{{count}} item and store combinations hold stock.',
                chartHint: 'none',
            ),
            new QuestionRule(
                key: 'low_stock',
                example: 'Which items are below reorder level?',
                label: 'Items at or below reorder level',
                keywords: ['low stock', 'below reorder', 'reorder level', 'running out', 'need to order', 'shortage'],
                tables: ['stock_balances', 'items'],
                sql: 'SELECT i.sku AS sku, i.name AS item, ROUND(COALESCE(SUM(sb.quantity), 0), 3) AS quantity, i.reorder_level AS reorder_level, i.uom AS uom
FROM items i
LEFT JOIN stock_balances sb ON sb.item_id = i.id
WHERE i.deleted_at IS NULL AND i.is_active = 1 AND i.reorder_level > 0
GROUP BY i.sku, i.name, i.reorder_level, i.uom
HAVING quantity <= i.reorder_level
ORDER BY quantity ASC
LIMIT 200',
                answerTemplate: '{{count}} items are at or below their reorder level.',
                chartHint: 'bar',
            ),
            new QuestionRule(
                key: 'stock_by_warehouse',
                example: 'Stock by store',
                label: 'Stock by store',
                keywords: ['stock by warehouse', 'stock by store', 'warehouse stock', 'store stock', 'godown'],
                tables: ['stock_balances', 'warehouses'],
                sql: 'SELECT w.name AS warehouse, COUNT(DISTINCT sb.item_id) AS items, ROUND(SUM(sb.quantity), 3) AS quantity
FROM stock_balances sb
JOIN warehouses w ON w.id = sb.warehouse_id
WHERE w.deleted_at IS NULL AND sb.quantity <> 0
GROUP BY w.name
ORDER BY quantity DESC
LIMIT 100',
                answerTemplate: 'Stock sits in {{count}} stores.',
                chartHint: 'bar',
            ),
        ];
    }

    /** @return list<QuestionRule> */
    private static function procurement(): array
    {
        return [
            new QuestionRule(
                key: 'open_purchase_orders',
                example: 'Show open purchase orders',
                label: 'Open purchase orders',
                keywords: ['open purchase order', 'open po', 'pending purchase order', 'pending po', 'purchase order', 'open orders'],
                tables: ['purchase_orders', 'vendors'],
                sql: "SELECT po.id AS po_number, v.name AS vendor, po.status AS status, po.order_date AS order_date, po.expected_date AS expected_date
FROM purchase_orders po
JOIN vendors v ON v.id = po.vendor_id
WHERE po.status IN ('draft', 'sent', 'partially_received')
ORDER BY po.order_date DESC
LIMIT 200",
                answerTemplate: '{{count}} purchase orders are still open.',
                chartHint: 'none',
            ),
            new QuestionRule(
                key: 'purchase_orders_by_vendor',
                example: 'Open purchase orders per vendor',
                label: 'Open purchase orders per vendor',
                keywords: ['by vendor', 'per vendor', 'vendor wise', 'which vendor', 'supplier wise', 'per supplier'],
                tables: ['purchase_orders', 'vendors'],
                sql: "SELECT v.name AS vendor, COUNT(*) AS open_orders
FROM purchase_orders po
JOIN vendors v ON v.id = po.vendor_id
WHERE po.status IN ('draft', 'sent', 'partially_received') AND v.deleted_at IS NULL
GROUP BY v.name
ORDER BY open_orders DESC
LIMIT 100",
                answerTemplate: '{{count}} vendors have open purchase orders.',
                chartHint: 'bar',
            ),
            new QuestionRule(
                key: 'pending_receipts',
                example: 'Which purchase orders are awaiting material?',
                label: 'Purchase orders not yet fully received',
                keywords: ['pending receipt', 'not received', 'awaiting receipt', 'awaiting material', 'grn pending', 'yet to receive'],
                tables: ['purchase_orders', 'vendors', 'goods_receipt_notes'],
                sql: "SELECT po.id AS po_number, v.name AS vendor, po.status AS status, po.expected_date AS expected_date, COUNT(grn.id) AS receipts
FROM purchase_orders po
JOIN vendors v ON v.id = po.vendor_id
LEFT JOIN goods_receipt_notes grn ON grn.purchase_order_id = po.id
WHERE po.status IN ('sent', 'partially_received')
GROUP BY po.id, v.name, po.status, po.expected_date
ORDER BY po.expected_date ASC
LIMIT 200",
                answerTemplate: '{{count}} purchase orders are still awaiting material.',
                chartHint: 'none',
            ),
            new QuestionRule(
                key: 'supplier_bills_pending',
                example: 'Supplier bills still in draft',
                label: 'Supplier bills still in draft',
                keywords: ['supplier bill', 'pending bill', 'unpaid bill', 'bills pending', 'draft bill', 'bill'],
                tables: ['supplier_bills', 'vendors'],
                sql: "SELECT sb.bill_number AS bill_number, v.name AS vendor, sb.bill_date AS bill_date, sb.status AS status
FROM supplier_bills sb
JOIN vendors v ON v.id = sb.vendor_id
WHERE sb.status = 'draft'
ORDER BY sb.bill_date DESC
LIMIT 200",
                answerTemplate: '{{count}} supplier bills are still in draft.',
                chartHint: 'none',
            ),
        ];
    }

    /** @return list<QuestionRule> */
    private static function production(): array
    {
        return [
            new QuestionRule(
                key: 'production_by_machine',
                example: 'Output by machine',
                label: 'Output by machine, last 30 days',
                keywords: ['machine output', 'output by machine', 'production by machine', 'which machine'],
                hints: ['by machine', 'per machine', 'machine wise'],
                tables: ['shift_production_entries', 'work_centers'],
                sql: "SELECT wc.code AS machine, COUNT(*) AS batches, SUM(spe.quantity_produced) AS pieces
FROM shift_production_entries spe
JOIN work_centers wc ON wc.id = spe.work_center_id
WHERE spe.batch_status = 'completed' AND spe.production_date >= DATE_SUB('{{today}}', INTERVAL 30 DAY)
GROUP BY wc.code
ORDER BY pieces DESC
LIMIT 100",
                answerTemplate: '{{count}} machines produced in the last 30 days, {{sum.pieces}} pieces in total.',
                chartHint: 'bar',
            ),
            new QuestionRule(
                key: 'production_today',
                example: 'What was produced today?',
                label: "Today's production",
                keywords: ['produced today', 'production today', 'output today', 'today production', 'made today'],
                tables: ['shift_production_entries', 'work_centers', 'items'],
                sql: "SELECT wc.code AS machine, i.name AS item, spe.batch_number AS batch, spe.quantity_produced AS pieces, spe.batch_status AS status
FROM shift_production_entries spe
JOIN work_centers wc ON wc.id = spe.work_center_id
JOIN items i ON i.id = spe.item_id
WHERE spe.production_date = '{{today}}'
ORDER BY wc.code
LIMIT 200",
                answerTemplate: '{{count}} batches ran today, {{sum.pieces}} pieces in total.',
                chartHint: 'bar',
            ),
            new QuestionRule(
                key: 'production_by_day',
                example: 'Daily production for the last 30 days',
                label: 'Output by day, last 30 days',
                keywords: ['by day', 'per day', 'daily production', 'day wise', 'production trend', 'last 30 days'],
                tables: ['shift_production_entries'],
                sql: "SELECT spe.production_date AS date, SUM(spe.quantity_produced) AS pieces
FROM shift_production_entries spe
WHERE spe.batch_status = 'completed' AND spe.production_date >= DATE_SUB('{{today}}', INTERVAL 30 DAY)
GROUP BY spe.production_date
ORDER BY spe.production_date ASC
LIMIT 200",
                answerTemplate: 'Production ran on {{count}} days, {{sum.pieces}} pieces in total.',
                chartHint: 'line',
            ),
            new QuestionRule(
                key: 'rejection_by_machine',
                example: 'Rejection by machine',
                label: 'Rejection by machine, last 30 days',
                keywords: ['rejection', 'rejected', 'reject'],
                hints: ['by machine', 'per machine'],
                tables: ['shift_production_entries', 'work_centers'],
                sql: "SELECT wc.code AS machine, ROUND(SUM(spe.quantity_rejection_kg), 3) AS rejection_kg, COUNT(*) AS batches
FROM shift_production_entries spe
JOIN work_centers wc ON wc.id = spe.work_center_id
WHERE spe.batch_status = 'completed' AND spe.production_date >= DATE_SUB('{{today}}', INTERVAL 30 DAY)
GROUP BY wc.code
ORDER BY rejection_kg DESC
LIMIT 100",
                answerTemplate: '{{sum.rejection_kg}} kg of rejection across {{count}} machines in the last 30 days.',
                chartHint: 'bar',
            ),
            new QuestionRule(
                key: 'lumps_by_machine',
                example: 'Lumps by machine',
                label: 'Lumps by machine, last 30 days',
                keywords: ['lumps', 'lump'],
                hints: ['by machine', 'per machine'],
                tables: ['shift_scraps', 'shift_production_entries', 'work_centers'],
                sql: "SELECT wc.code AS machine, ROUND(SUM(ss.quantity_kg), 3) AS lumps_kg
FROM shift_scraps ss
JOIN shift_production_entries spe ON spe.id = ss.shift_production_entry_id
JOIN work_centers wc ON wc.id = spe.work_center_id
WHERE ss.type = 'lumps' AND spe.production_date >= DATE_SUB('{{today}}', INTERVAL 30 DAY)
GROUP BY wc.code
ORDER BY lumps_kg DESC
LIMIT 100",
                answerTemplate: '{{sum.lumps_kg}} kg of lumps across {{count}} machines in the last 30 days.',
                chartHint: 'bar',
            ),
            new QuestionRule(
                key: 'batches_awaiting_quality',
                example: 'Which batches are awaiting quality?',
                label: 'Batches waiting for a quality check',
                keywords: ['awaiting quality', 'pending quality', 'quality queue', 'not checked', 'waiting for quality'],
                tables: ['shift_production_entries', 'work_centers', 'items'],
                sql: "SELECT spe.batch_number AS batch, wc.code AS machine, i.name AS item, spe.production_date AS date, spe.quantity_produced AS pieces
FROM shift_production_entries spe
JOIN work_centers wc ON wc.id = spe.work_center_id
JOIN items i ON i.id = spe.item_id
WHERE spe.batch_status = 'completed' AND spe.status = 'pending'
ORDER BY spe.production_date ASC
LIMIT 200",
                answerTemplate: '{{count}} batches are waiting in the quality queue.',
                chartHint: 'none',
            ),
            new QuestionRule(
                key: 'batches_awaiting_approval',
                example: 'Which batches are awaiting approval?',
                label: 'Batches waiting for approval',
                keywords: ['awaiting approval', 'pending approval', 'not approved', 'waiting approval', 'to approve'],
                tables: ['shift_production_entries', 'work_centers'],
                sql: "SELECT spe.batch_number AS batch, wc.code AS machine, spe.production_date AS date, spe.status AS status
FROM shift_production_entries spe
JOIN work_centers wc ON wc.id = spe.work_center_id
WHERE spe.batch_status = 'completed' AND spe.status IN ('pending', 'pm_approved')
ORDER BY spe.production_date ASC
LIMIT 200",
                answerTemplate: '{{count}} batches are still short of a full approval.',
                chartHint: 'none',
            ),
        ];
    }

    /** @return list<QuestionRule> */
    private static function sales(): array
    {
        return [
            new QuestionRule(
                key: 'open_sales_orders',
                example: 'Show open sales orders',
                label: 'Open sales orders',
                keywords: ['open sales order', 'pending sales order', 'sales order', 'customer order', 'open order'],
                tables: ['sales_orders', 'customers'],
                sql: "SELECT so.id AS order_number, c.name AS customer, so.status AS status, so.order_date AS order_date, so.expected_date AS expected_date
FROM sales_orders so
JOIN customers c ON c.id = so.customer_id
WHERE so.status IN ('draft', 'confirmed', 'partially_delivered')
ORDER BY so.expected_date ASC
LIMIT 200",
                answerTemplate: '{{count}} sales orders are still open.',
                chartHint: 'none',
            ),
            new QuestionRule(
                key: 'sales_orders_by_customer',
                example: 'Open sales orders per customer',
                label: 'Open sales orders per customer',
                keywords: ['by customer', 'per customer', 'customer wise', 'which customer'],
                tables: ['sales_orders', 'customers'],
                sql: "SELECT c.name AS customer, COUNT(*) AS open_orders
FROM sales_orders so
JOIN customers c ON c.id = so.customer_id
WHERE so.status IN ('draft', 'confirmed', 'partially_delivered') AND c.deleted_at IS NULL
GROUP BY c.name
ORDER BY open_orders DESC
LIMIT 100",
                answerTemplate: '{{count}} customers have open orders.',
                chartHint: 'bar',
            ),
            new QuestionRule(
                key: 'pending_dispatch',
                example: 'Which orders are pending dispatch?',
                label: 'Orders confirmed but not fully delivered',
                keywords: ['pending dispatch', 'not delivered', 'awaiting delivery', 'to dispatch', 'yet to deliver', 'pending delivery'],
                tables: ['sales_orders', 'customers', 'deliveries'],
                sql: "SELECT so.id AS order_number, c.name AS customer, so.expected_date AS expected_date, COUNT(d.id) AS deliveries
FROM sales_orders so
JOIN customers c ON c.id = so.customer_id
LEFT JOIN deliveries d ON d.sales_order_id = so.id
WHERE so.status IN ('confirmed', 'partially_delivered')
GROUP BY so.id, c.name, so.expected_date
ORDER BY so.expected_date ASC
LIMIT 200",
                answerTemplate: '{{count}} orders are waiting to be dispatched.',
                chartHint: 'none',
            ),
            new QuestionRule(
                key: 'recent_deliveries',
                example: 'Deliveries in the last 30 days',
                label: 'Deliveries in the last 30 days',
                keywords: ['deliveries', 'dispatched', 'delivery', 'dispatch'],
                tables: ['deliveries', 'sales_orders', 'customers'],
                sql: "SELECT d.reference AS reference, c.name AS customer, d.delivered_date AS delivered_date
FROM deliveries d
JOIN sales_orders so ON so.id = d.sales_order_id
JOIN customers c ON c.id = so.customer_id
WHERE d.delivered_date >= DATE_SUB('{{today}}', INTERVAL 30 DAY)
ORDER BY d.delivered_date DESC
LIMIT 200",
                answerTemplate: '{{count}} deliveries went out in the last 30 days.',
                chartHint: 'none',
            ),
            new QuestionRule(
                key: 'recent_invoices',
                example: 'Invoices in the last 30 days',
                label: 'Invoices in the last 30 days',
                keywords: ['invoice', 'invoices', 'billed', 'billing'],
                tables: ['invoices', 'customers'],
                sql: "SELECT inv.id AS invoice_number, c.name AS customer, inv.invoice_date AS invoice_date, inv.due_date AS due_date, inv.status AS status
FROM invoices inv
JOIN customers c ON c.id = inv.customer_id
WHERE inv.invoice_date >= DATE_SUB('{{today}}', INTERVAL 30 DAY)
ORDER BY inv.invoice_date DESC
LIMIT 200",
                answerTemplate: '{{count}} invoices were raised in the last 30 days.',
                chartHint: 'none',
            ),
        ];
    }

    /** @return list<QuestionRule> */
    private static function attendance(): array
    {
        return [
            new QuestionRule(
                key: 'attendance_today',
                example: 'Attendance today',
                label: "Today's attendance",
                keywords: ['attendance today', 'present today', 'who is present', 'today attendance', 'attendance'],
                tables: ['attendances', 'employees'],
                sql: "SELECT a.status AS status, COUNT(*) AS people
FROM attendances a
JOIN employees e ON e.id = a.employee_id
WHERE a.date = '{{today}}' AND e.deleted_at IS NULL
GROUP BY a.status
ORDER BY people DESC
LIMIT 20",
                answerTemplate: "Today's attendance is recorded in {{count}} states, {{sum.people}} people in all.",
                chartHint: 'bar',
            ),
            new QuestionRule(
                key: 'absent_today',
                example: 'Who is absent today?',
                label: 'Absent today',
                keywords: ['absent', 'absentee', 'not present', 'who is absent', 'leave today'],
                tables: ['attendances', 'employees'],
                sql: "SELECT e.employee_code AS code, e.name AS employee, e.department AS department, a.status AS status
FROM attendances a
JOIN employees e ON e.id = a.employee_id
WHERE a.date = '{{today}}' AND a.status IN ('absent', 'on_leave') AND e.deleted_at IS NULL
ORDER BY e.name
LIMIT 200",
                answerTemplate: '{{count}} people are absent or on leave today.',
                chartHint: 'none',
            ),
            new QuestionRule(
                key: 'attendance_by_department',
                example: 'Attendance by department',
                label: 'Attendance by department, last 30 days',
                keywords: ['by department', 'department wise', 'per department', 'attendance summary'],
                tables: ['attendances', 'employees'],
                sql: "SELECT e.department AS department, a.status AS status, COUNT(*) AS days
FROM attendances a
JOIN employees e ON e.id = a.employee_id
WHERE a.date >= DATE_SUB('{{today}}', INTERVAL 30 DAY) AND e.deleted_at IS NULL
GROUP BY e.department, a.status
ORDER BY e.department, days DESC
LIMIT 200",
                answerTemplate: '{{count}} department and status combinations over the last 30 days.',
                chartHint: 'none',
            ),
        ];
    }
}
