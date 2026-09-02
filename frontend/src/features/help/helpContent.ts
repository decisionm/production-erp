/**
 * The words the Help page shows for each screen, keyed by ROUTE — the same
 * key the sidebar uses, so HelpPage.render.test.tsx can hold the two lists
 * against each other. Every line here is taken from the page's own docblock,
 * titles and button labels (02-Sep-2026); a route with no entry renders its
 * heading only, and the test refuses it. Add the entry in the same change
 * that adds the menu item.
 */
export interface HelpEntry {
    /** One sentence: what a person does on this screen. */
    what: string;
    /** The main actions, as the buttons name them. */
    actions?: string[];
    /** The screen's own key rule, when it states one. */
    rule?: string;
}

export const HELP_BY_ROUTE: Record<string, HelpEntry> = {
    '/': {
        what: 'See where the factory stands right now: machines running, papers waiting on someone, stock coming in, the order book.',
        rule: 'Reads only. Nothing on the dashboard writes anything.',
    },
    '/exports': {
        what: 'Download a server-run export of a list or report with the filters you choose.',
        actions: ['Download'],
        rule: 'Every card is a server export of the full result, never the rows a browser has on screen.',
    },

    // Procurement
    '/procurement/vendors': {
        what: 'Keep the single vendor master, and review the vendor records Tally proposes for it.',
        actions: ['New Vendor', 'Edit', 'Archive', 'Reactivate', 'Tally review'],
        rule: 'Tally proposes, this master decides. The review is a tab, not a second master.',
    },
    '/procurement/purchase-requisitions': {
        what: 'Raise a requisition to buy materials, approve or reject the waiting ones, and turn approved ones into orders.',
        actions: ['New Requisition', 'View', 'Approve', 'Reject', 'Raise PO', 'Remove'],
        rule: 'A requisition carries no money. Rates are typed on the Purchase Orders page.',
    },
    '/procurement/purchase-orders': {
        what: 'Raise purchase orders and run their lifecycle, with where each one stands with Tally on the row.',
        actions: ['New Purchase Order', 'Send', 'Amend', 'Close', 'Cancel order', 'View'],
        rule: 'Only the lifecycle actions the server allows for a row are offered.',
    },
    '/procurement/goods-receipts': {
        what: 'Record what a delivery actually brought in against a purchase order, scanning bags and supplier lots.',
        actions: ['New Goods Receipt', 'View', 'Carry on', 'Discard and generate'],
        rule: 'A receipt in progress survives a refresh. Every arrival then waits for incoming QA before the Store may issue it.',
    },
    '/procurement/supplier-bills': {
        what: 'Record the supplier’s invoice as printed, with its number, date and figures, against orders and receipts.',
        actions: ['New Bill', 'View', 'Edit', 'Save draft', 'Cancel bill'],
        rule: 'Taxes and rounding are typed, never computed, and nothing here posts to Tally.',
    },

    // Inventory
    '/inventory/find': {
        what: 'Type or scan any number the factory writes on something and be taken to the record.',
        actions: ['Search'],
        rule: 'It jumps only when the match is exact and the only one; otherwise it lists the candidates.',
    },
    '/inventory/items': {
        what: 'Maintain the item catalogue: SKU, name, category, unit, tracking, reorder level, and its health warnings.',
        actions: ['New Item', 'Edit', 'Archive', 'Reactivate'],
    },
    '/inventory/stock': {
        what: 'Read on-hand, QA hold, reserved and free-to-issue balances per item and warehouse, search them, and move stock.',
        actions: ['Receive Stock', 'Issue Stock', 'Transfer Stock'],
        rule: 'Free to issue is on hand less the incoming-QC hold and less what is reserved for customers.',
    },
    '/inventory/barcode-labels': {
        what: 'Reprint a bag’s label by barcode, or look up which receipt a lot arrived on.',
        actions: ['Bags', 'Receipts & lots'],
        rule: 'Neither tab receives material, moves stock or posts to Tally.',
    },
    '/inventory/store-production': {
        what: 'Hand material out to the floor and take it back: the issue queue, the returns, and the movement history, in one workspace.',
        actions: ['Issue to production', 'Returns from production', 'Movement history'],
        rule: 'Issuing is not consuming. Issued material is still stock, in Production/WIP, until a batch consumes it or the Store takes it back.',
    },
    '/inventory/stock-movements': {
        what: 'Read every stock movement in the factory, newest first, narrowed by item, warehouse, purpose or reference.',
        rule: 'It writes nothing.',
    },
    '/inventory/fulfilment': {
        what: 'Work the Store’s queue of order lines waiting on stock and decide what is reserved for whom.',
        actions: ['Reserve', 'Send to production', 'Release', 'Re-point'],
        rule: 'Nothing here moves stock or starts a batch. Covered lines are hidden by default.',
    },
    '/inventory/planning': {
        what: 'See when the factory could have each open production request, with today’s targets above it.',
        rule: 'No date is stored. A row that cannot be dated names the reason.',
    },
    '/inventory/warehouses': {
        what: 'Maintain the warehouses and stores that stock is held in.',
        actions: ['New Warehouse', 'Edit', 'Archive', 'Reactivate'],
    },

    // Production
    '/production/queue': {
        what: 'Read the floor’s worklist grouped by product, set its order, and start or cancel a job.',
        actions: ['Move up', 'Move down', 'Start', 'Cancel'],
        rule: 'No ETA is stored here; the order is the floor’s own.',
    },
    '/production/shift-production': {
        what: 'The Shift Floor: start a batch on a machine, log the shift’s events, complete it, and correct today’s entries.',
        actions: ['Start Batch', 'Load Material', 'Mold Change', 'Complete Batch', 'Log Power Interruption', 'Log Stock Count'],
        rule: 'Carton labels print here at completion. A batch consumes from Production/WIP.',
    },
    '/production/material-requests': {
        what: 'Ask the Store for material, then watch what was issued, what is outstanding and what went back.',
        actions: ['New request', 'Send to store', 'Cancel request'],
        rule: 'Issued is not consumed. The four quantities are named separately, never as one “done” number.',
    },
    '/production/approve-production': {
        what: 'Approve completed batches up the chain, Plant Manager then Accountant, or reject one.',
        actions: ['View', 'Approve', 'Approve & Post', 'Reject'],
        rule: 'The Accountant’s approval is final and is what reaches Tally. One consolidated Stock Journal per date and shift.',
    },
    '/production/live-monitor': {
        what: 'Watch the live shift: which machines are running, what waits for a signature, what failed.',
        rule: 'Not the management dashboard: no date range, no drill-downs, and it writes nothing.',
    },
    '/production/carton-trace': {
        what: 'Scan a carton and read its completion time, shift, day-bin lot attribution and batch costing rate.',
        actions: ['Search'],
        rule: 'Lot attribution is a calculated basis, never “this batch used this bag”. Rates are for Owner, Plant Manager and Accounts.',
    },
    '/production/configuration': {
        what: 'Set the factory up in one place: the masters and rules the floor picks from daily.',
        actions: [
            'Product Standards',
            'Machines & Capabilities',
            'Molds',
            'Packing Materials',
            'Downtime Reasons',
            'Scrap Reasons',
            'Shifts',
            'Factory Rules',
            'Import from Workbook',
        ],
        rule: 'Factory Rules marks each setting “In use” or “Not in use”: a “Not in use” row is a recorded workbook value that no screen reads yet.',
    },
    '/production/boms': {
        what: 'Define which components and quantities make one unit of a product.',
        actions: ['New BOM', 'Remove'],
    },
    '/production/shift-summary': {
        what: 'Record the supervisor’s inputs for a shift or whole day and read the computed KPI report.',
        actions: ['Save', 'Shift / Whole Day'],
    },
    '/production/reports': {
        what: 'Read production, reconciliation and traceability reports for a date range and download them.',
        actions: ['Production', 'Reconciliation', 'Traceability', 'Download CSV'],
        rule: 'Download CSV is a server export of the same query, never the rendered rows.',
    },

    // Sales
    '/sales/customers': {
        what: 'Maintain the customer master.',
        actions: ['New Customer', 'Edit', 'Archive', 'Reactivate'],
    },
    '/sales/sales-orders': {
        what: 'Raise sales orders, confirm them, and open one to read its lines, costs and margins.',
        actions: ['New Sales Order', 'View', 'Confirm', 'Remove'],
        rule: 'The ERP does not send sales orders to Tally. Tally raises the invoice; the ERP reads it back.',
    },
    '/sales/deliveries': {
        what: 'Record what was dispatched against a sales order, with each note’s carton count.',
        actions: ['New Delivery', 'View'],
        rule: 'The Store performs the final dispatch, after internal Quality approval. Sales does not dispatch.',
    },
    '/sales/invoices': {
        what: 'Raise invoices against sales orders and issue the drafts.',
        actions: ['New Invoice', 'View', 'Issue'],
        rule: 'The ERP does not send an invoice to Tally. Tally raises the sales invoice and e-invoice, and the ERP reads it back.',
    },
    '/sales/fulfilment-control': {
        what: 'Read one shared board of every live order line: status, blocker, who acts next, stock, dates.',
        rule: 'A read only. A field that is not built yet prints why, never a blank.',
    },

    // Quality
    '/quality/production-qc': {
        what: 'Check each completed batch, oldest first: reviewed, OK and rejected pieces, or send it back to production.',
        actions: ['Check', 'Submit check', 'Return to production', 'Cancel'],
        rule: 'Rejected pieces are scrap and are never reworked. Counts are whole pieces.',
    },
    '/quality/returned-material': {
        what: 'Decide what happens to material the Store marked damaged on its way back from production.',
        actions: ['Confirm damage', 'Release'],
        rule: 'Confirmed damage is scrapped and there is no undo. A good return never comes through here.',
    },
    '/quality/incoming-inspections': {
        what: 'Record the inspection of goods received from a supplier and its result, and find past inspections by item or receipt.',
        actions: ['New Inspection', 'View'],
    },
    '/quality/ncrs': {
        what: 'Raise non-conformance reports and close them.',
        actions: ['New NCR', 'View', 'Close'],
    },
    '/quality/capas': {
        what: 'Raise corrective and preventive actions, edit their action lists and take them to closed.',
        actions: ['New CAPA', 'Edit Actions', 'Start', 'Close'],
    },
    '/quality/instruments': {
        what: 'Keep the register of measuring instruments and record each calibration.',
        actions: ['New Instrument', 'Record Calibration'],
    },
    '/quality/spc-characteristics': {
        what: 'Define the characteristics measured for SPC and open the control chart for one.',
        actions: ['New Characteristic', 'View Chart'],
    },

    // Compliance
    '/compliance/gst-rates': {
        what: 'Maintain the GST rates the factory bills at.',
        actions: ['New Rate', 'Edit'],
    },
    '/compliance/gst-registrations': {
        what: 'Maintain the GST registrations the factory holds.',
        actions: ['New Registration', 'Edit'],
    },
    '/compliance/gst-reports': {
        what: 'Read GSTR-1 and the invoice breakdown for a period.',
        actions: ['GSTR-1', 'Invoice Breakdown'],
    },

    // HRMS
    '/hrms/employees': {
        what: 'Maintain employee records; these are the operators and supervisors the Shift Floor names.',
        actions: ['New Employee', 'Edit', 'Archive', 'Reactivate'],
    },
    '/hrms/leave-types': {
        what: 'Maintain the kinds of leave the factory grants.',
        actions: ['New Leave Type'],
    },
    '/hrms/leave-balances': {
        what: 'Allocate and read each employee’s leave balance.',
        actions: ['Allocate Balance'],
    },
    '/hrms/leave-requests': {
        what: 'Raise leave requests and approve or reject the pending ones.',
        actions: ['New Leave Request', 'View', 'Approve', 'Reject'],
    },
    '/hrms/attendance': {
        what: 'Mark and read daily attendance for employees.',
        actions: ['Mark Attendance'],
    },
    '/hrms/attendance-imports': {
        what: 'Upload the Pooja punch report, correct the days it could not decide, apply the month to attendance, and download the month sheet.',
        actions: ['Upload punch report', 'Correct', 'Apply', 'Download month sheet'],
    },

    // Payroll
    '/payroll/salary-components': {
        what: 'Maintain the earning and deduction components salaries are built from.',
        actions: ['New Component'],
    },
    '/payroll/salary-structures': {
        what: 'Build a salary structure out of components and read the ones already defined.',
        actions: ['New Structure', 'View', 'Remove'],
    },
    '/payroll/runs': {
        what: 'Create a monthly payroll run, process it, mark it paid, and open its payslips.',
        actions: ['New Payroll Run', 'Process', 'Mark Paid', 'View Payslips'],
    },
    '/payroll/payslips': {
        what: 'Read the payslips a payroll run produced, each with its earnings and deductions.',
    },

    // Finance
    '/finance/client-outstanding': {
        what: 'See what every client owes, how long they have owed it, and what is still to ship.',
        actions: ['All clients', 'Overdue only', 'Has pending orders'],
        rule: 'Every number is Tally’s, and only as current as the last pull by the Tally Sync Agent.',
    },
    '/finance/chart-of-accounts': {
        what: 'Maintain the ledger accounts the books are kept in.',
        actions: ['New Account'],
    },
    '/finance/journal-entries': {
        what: 'Post a balanced journal entry and read the ones already posted.',
        actions: ['New Journal Entry', 'Remove'],
    },
    '/finance/reports': {
        what: 'Read the books’ reports for a period.',
        actions: ['Trial Balance', 'Profit & Loss', 'Balance Sheet', 'Receivables (AR)'],
    },

    // Maintenance
    '/maintenance/assets': {
        what: 'Maintain the register of machines and equipment that maintenance is done on.',
        actions: ['New Asset', 'Edit'],
    },
    '/maintenance/schedules': {
        what: 'Set preventive maintenance schedules and raise the work orders that are due.',
        actions: ['New Schedule', 'Generate Due Work Orders'],
    },
    '/maintenance/work-orders': {
        what: 'Report a maintenance job, add the parts used, and complete it.',
        actions: ['Report Work Order', 'View', 'Add Part', 'Complete'],
    },
    '/maintenance/reliability': {
        what: 'Read MTBF and MTTR per asset.',
    },

    // Tally Sync
    '/tally-sync': {
        what: 'Watch the queue of vouchers going to Tally and deal with the ones that failed.',
        actions: ['Refresh', 'Sync Now', 'Retry', 'Resync', 'View'],
        rule: 'Vouchers carry only approved entries and are released after the shift has ended.',
    },
    '/tally-sync/agent-tokens': {
        what: 'Issue and revoke the tokens the Tally Sync Agent on the factory PC signs in with.',
        actions: ['Generate Token', 'Revoke'],
    },
    '/tally-sync/settings': {
        what: 'Set the Tally company and the ledger mappings vouchers are posted with.',
        actions: ['Save', 'Save Mappings'],
    },

    // Administration
    '/administration/users': {
        what: 'Create users, edit them and reset their passwords.',
        actions: ['New User', 'Edit', 'Reset Password'],
    },
    '/administration/roles': {
        what: 'Create roles and set what each of them may do.',
        actions: ['New Role', 'Edit'],
    },
};

/**
 * The questions the floor and the office actually ask, answered from the
 * decision records in docs/factory (each line names its record).
 */
export const HELP_FAQ: { question: string; answer: string }[] = [
    {
        question: 'I issued material to production. Why has stock not been consumed?',
        answer: 'Issuing is not consuming. Issued material is still the factory’s stock, in Production/WIP, until a batch completes against it or the Store takes it back. (DEC-20260831-005, DEC-20260831-009)',
    },
    {
        question: 'A bag arrived this morning. Why can the Store not issue it yet?',
        answer: 'Every arrival waits for incoming QA before the Store may issue it. Release it on Incoming Inspections first. (DEC-20260831-011)',
    },
    {
        question: 'Material came back from the floor damaged. Where does it go?',
        answer: 'A good return goes back to usable stock. A damaged return goes to Quality, and only Quality can send it to scrap. (DEC-20260901-003)',
    },
    {
        question: 'A batch was completed hours ago. Why is it not in Tally?',
        answer: 'Tally receives one consolidated Stock Journal per production date and shift, containing only approved entries, released after the shift has ended. Approve it on Approve Production and watch the Tally Sync queue. (DEC-20260807-010, -011, -012)',
    },
    {
        question: 'Who dispatches a customer’s order?',
        answer: 'The Store performs the final dispatch, after the stock is fully held against the order and internal Quality has approved. Sales does not dispatch, and there is no separate customer-approval step. (DEC-20260831-006, DEC-20260901-005)',
    },
    {
        question: 'A Factory Rule says “Not in use”. What does changing it do?',
        answer: 'Nothing on the floor yet. That row is a value recorded from the workbook; no screen or rule reads it. Whether the software should enforce it is an open owner question (Q93).',
    },
];
