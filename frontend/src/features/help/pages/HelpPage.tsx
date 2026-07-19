import { Alert, Collapse, Table, Tag, Typography } from 'antd';
import type { ReactNode } from 'react';

function StatusFlow({ statuses }: { statuses: string[] }) {
    return (
        <div style={{ display: 'flex', flexWrap: 'wrap', gap: 6, alignItems: 'center', margin: '8px 0' }}>
            {statuses.map((status, index) => (
                <span key={status} style={{ display: 'flex', alignItems: 'center', gap: 6 }}>
                    <Tag>{status}</Tag>
                    {index < statuses.length - 1 && <span style={{ color: '#999' }}>→</span>}
                </span>
            ))}
        </div>
    );
}

function Note({ children }: { children: ReactNode }) {
    return <Alert type="info" showIcon style={{ margin: '10px 0' }} message={children} />;
}

interface HelpPage {
    title: string;
    body: ReactNode;
}

interface HelpModule {
    key: string;
    title: string;
    intro?: ReactNode;
    pages: HelpPage[];
}

const modules: HelpModule[] = [
    {
        key: 'dashboard',
        title: 'Dashboard',
        pages: [
            {
                title: 'Dashboard',
                body: (
                    <Typography.Paragraph>
                        Your homepage after login. Every tile is a live count pulled from the module it names —
                        Open Work Orders, Low Stock Items, Pending Requisitions, and so on. Nothing on this page
                        can be edited — click through to the module named on a tile to act on it.
                    </Typography.Paragraph>
                ),
            },
        ],
    },
    {
        key: 'crm',
        title: 'CRM',
        intro: <Typography.Paragraph>Where a sale starts, before there's a customer or an order yet.</Typography.Paragraph>,
        pages: [
            {
                title: 'Leads',
                body: (
                    <Typography.Paragraph>
                        A first contact who hasn't bought anything yet. Fields: name, company, contact details,
                        where they came from, and a status. Converting a lead is what creates the matching
                        Customer record and starts an Opportunity for them.
                        <StatusFlow statuses={['New', 'Contacted', 'Qualified', 'Converted / Lost']} />
                    </Typography.Paragraph>
                ),
            },
            {
                title: 'Opportunities',
                body: (
                    <Typography.Paragraph>
                        A deal you're actively pursuing with a customer — has an estimated value, a probability
                        of closing, and a stage. This is where you build a Quotation from.
                        <StatusFlow statuses={['Prospecting', 'Qualification', 'Proposal', 'Negotiation', 'Won / Lost']} />
                    </Typography.Paragraph>
                ),
            },
            {
                title: 'Quotations',
                body: (
                    <>
                        <Typography.Paragraph>
                            A priced proposal you send to the customer, listing items and prices.
                            <StatusFlow statuses={['Draft', 'Sent', 'Accepted / Rejected / Expired']} />
                        </Typography.Paragraph>
                        <Note>
                            <b>The one button that matters most here: Accept.</b> Accepting a quotation
                            automatically creates a real Sales Order for it — you don't need to re-type anything
                            into Sales. That's the moment a deal becomes an order to fulfill.
                        </Note>
                    </>
                ),
            },
        ],
    },
    {
        key: 'inventory',
        title: 'Inventory',
        intro: (
            <Typography.Paragraph>
                The one place that tracks how much of everything you have, and where. Almost every other
                module changes numbers on this module's Stock screen as a side effect of something you do
                there — a goods receipt, a delivery, finishing a work order — so most of the time you won't
                need to touch Inventory directly at all.
            </Typography.Paragraph>
        ),
        pages: [
            {
                title: 'Items',
                body: (
                    <>
                        <Typography.Paragraph>
                            Your product/material catalog. Each item has a SKU, name, unit of measure, an
                            HSN/SAC code (for GST), a reorder level, and a <b>Tracking Type</b>.
                        </Typography.Paragraph>
                        <Table
                            size="small"
                            pagination={false}
                            style={{ marginBottom: 12 }}
                            dataSource={[
                                {
                                    key: 'none',
                                    type: 'None',
                                    meaning:
                                        'Ordinary stock. Any unit is interchangeable with any other — you just track a quantity.',
                                },
                                {
                                    key: 'batch',
                                    type: 'Batch / Lot',
                                    meaning:
                                        "You can tag a receipt with a batch number (useful for expiry dates or recalls). Create the batch on the Batches page first — it won't show up in the picker otherwise.",
                                },
                                {
                                    key: 'serial',
                                    type: 'Serial Number',
                                    meaning:
                                        "Each individual unit is tracked separately. Register the serial on the Serial Numbers page first — an unregistered serial won't appear in the Receive Stock form.",
                                },
                            ]}
                            columns={[
                                { title: 'Tracking Type', dataIndex: 'type', width: 140 },
                                { title: 'What it means day to day', dataIndex: 'meaning' },
                            ]}
                        />
                    </>
                ),
            },
            {
                title: 'Warehouses',
                body: (
                    <Typography.Paragraph>
                        Your physical (or logical) storage locations — Raw Material Store, Work-in-Progress,
                        Finished Goods Store, and so on. Every stock movement happens at a specific warehouse.
                    </Typography.Paragraph>
                ),
            },
            {
                title: 'Stock',
                body: (
                    <>
                        <Typography.Paragraph>
                            Shows how much of each item you have, broken down <b>by warehouse</b>.
                        </Typography.Paragraph>
                        <Note>
                            <b>Why does the same item show up more than once?</b> Because this list shows one line
                            per item-and-warehouse combination, not one line per item. If &quot;HDPE Resin&quot;
                            appears three times, that's three different physical locations holding stock, not
                            duplicated data. A line can even show a quantity of 0 — it stays on the list rather
                            than disappearing.
                        </Note>
                        <Typography.Paragraph style={{ marginTop: 12 }}>
                            The three buttons at the top of this page:
                        </Typography.Paragraph>
                        <Table
                            size="small"
                            pagination={false}
                            style={{ marginBottom: 12 }}
                            dataSource={[
                                {
                                    key: 'receive',
                                    button: 'Receive Stock',
                                    effect: 'Record stock coming in (e.g. an opening balance, or a correction). Increases the quantity at the chosen warehouse.',
                                },
                                {
                                    key: 'issue',
                                    button: 'Issue Stock',
                                    effect: "Record stock going out for a reason that isn't a delivery or a work order. You can't issue more than you currently have.",
                                },
                                {
                                    key: 'transfer',
                                    button: 'Transfer Stock',
                                    effect: 'Move stock from one warehouse to another in one step.',
                                },
                            ]}
                            columns={[
                                { title: 'Button', dataIndex: 'button', width: 150 },
                                { title: 'Effect', dataIndex: 'effect' },
                            ]}
                        />
                        <Note>
                            <b>These three buttons are for manual corrections and one-off adjustments.</b> In
                            normal day-to-day use you shouldn't need them much — the real stock movement happens
                            automatically elsewhere: receiving a Goods Receipt (Procurement) brings stock in,
                            confirming a Delivery (Sales) takes stock out, and releasing/completing a Work Order
                            (Production) issues materials and receives finished goods automatically.
                        </Note>
                    </>
                ),
            },
            {
                title: 'Batches',
                body: (
                    <Typography.Paragraph>
                        Where you create batch/lot numbers ahead of time for items tracked that way. Click{' '}
                        <b>View Ledger</b> on any batch to see everywhere it currently sits and its full movement
                        history.
                    </Typography.Paragraph>
                ),
            },
            {
                title: 'Serial Numbers',
                body: (
                    <Typography.Paragraph>
                        Where you register individual serial numbers ahead of time for items tracked that way. A
                        serial starts as &quot;registered&quot; and only becomes &quot;in stock&quot; once you
                        actually receive it on the Stock page — that's why a freshly registered serial won't show
                        up as available to issue or transfer until after it's been received first. Click{' '}
                        <b>View History</b> on any serial to see its full movement trail.
                    </Typography.Paragraph>
                ),
            },
        ],
    },
    {
        key: 'production',
        title: 'Production',
        intro: <Typography.Paragraph>Turns raw materials into finished goods.</Typography.Paragraph>,
        pages: [
            {
                title: 'Work Centers',
                body: (
                    <Typography.Paragraph>
                        A machine or station on your shop floor, with a daily capacity. Used by Routings and by
                        Capacity Planning.
                    </Typography.Paragraph>
                ),
            },
            {
                title: 'Bills of Material (BOM)',
                body: (
                    <Typography.Paragraph>
                        The recipe for an item — which components, and how much of each, go into making one
                        unit. When you release a Work Order, the system automatically uses the item's active BOM
                        to figure out exactly what materials to pull from stock.
                    </Typography.Paragraph>
                ),
            },
            {
                title: 'Routings',
                body: (
                    <Typography.Paragraph>
                        The sequence of operations (and which Work Center each runs on) needed to make an item.
                        Optional — a Work Order only strictly needs a BOM, not a Routing.
                    </Typography.Paragraph>
                ),
            },
            {
                title: 'Work Orders',
                body: (
                    <>
                        <Typography.Paragraph>
                            The main &quot;make this&quot; document. Fields: what item to make, from which
                            BOM/Routing, at which warehouse, and how many units.
                            <StatusFlow statuses={['Draft', 'Released', 'Completed']} />
                        </Typography.Paragraph>
                        <ul>
                            <li>
                                <b>Release</b> — pulls the required materials out of stock according to the BOM,
                                scaled to the quantity you're planning to make.
                            </li>
                            <li>
                                <b>Complete</b> — records how many units actually came out (which doesn't have to
                                match what you planned) and adds that quantity of the finished item into stock.
                                You can also log any scrap here, with a reason.
                            </li>
                        </ul>
                    </>
                ),
            },
            {
                title: 'Subcontract Orders',
                body: (
                    <Typography.Paragraph>
                        For job work — you send materials out to a vendor for outside processing, then receive
                        the finished item back. <b>Send Materials</b> issues stock out to the vendor;{' '}
                        <b>Receive</b> brings the finished item back in, with a cost that includes both your
                        materials and the vendor's service fee.
                    </Typography.Paragraph>
                ),
            },
            {
                title: 'Scrap Reasons',
                body: (
                    <Typography.Paragraph>
                        A simple list of standard reasons (e.g. &quot;short shot&quot;, &quot;contamination&quot;)
                        you can pick from when logging scrap on a Work Order — keeps scrap categorized instead of
                        just being a number.
                    </Typography.Paragraph>
                ),
            },
            {
                title: 'Rework Orders',
                body: (
                    <Typography.Paragraph>
                        For recovering defective output instead of throwing it away. Release takes the defective
                        units out of stock; Complete brings the recovered good units back in, plus whatever labor
                        cost you record.
                    </Typography.Paragraph>
                ),
            },
            {
                title: 'MRP',
                body: (
                    <Typography.Paragraph>
                        A planning report — tells you what's short based on your BOMs, current stock, and open
                        orders. Read-only; it doesn't create anything by itself.
                    </Typography.Paragraph>
                ),
            },
            {
                title: 'Capacity Planning',
                body: (
                    <Typography.Paragraph>
                        A report showing how loaded each Work Center is based on open Work Orders and their
                        Routings, compared against its daily capacity.
                    </Typography.Paragraph>
                ),
            },
        ],
    },
    {
        key: 'procurement',
        title: 'Procurement',
        intro: <Typography.Paragraph>Buying materials — from &quot;we need this&quot; to &quot;it's on the shelf.&quot;</Typography.Paragraph>,
        pages: [
            {
                title: 'Vendors',
                body: <Typography.Paragraph>Your supplier list, including GSTIN for GST purposes.</Typography.Paragraph>,
            },
            {
                title: 'Purchase Requisitions',
                body: (
                    <Typography.Paragraph>
                        An internal request to buy something, before any vendor commitment.
                        <StatusFlow statuses={['Draft', 'Approved / Rejected']} />
                        Approving one doesn't automatically create a Purchase Order — it just makes the
                        requisition available to reference when you create one.
                    </Typography.Paragraph>
                ),
            },
            {
                title: 'Purchase Orders',
                body: (
                    <Typography.Paragraph>
                        Your commitment to a specific vendor for specific items and quantities.
                        <StatusFlow statuses={['Draft', 'Sent', 'Partially Received', 'Closed / Cancelled']} />
                    </Typography.Paragraph>
                ),
            },
            {
                title: 'Goods Receipts',
                body: (
                    <Typography.Paragraph>
                        Where you record what actually arrived against a Purchase Order —{' '}
                        <b>this is the step that adds stock</b>, not the Purchase Order itself. You can receive a
                        PO across several separate receipts if it arrives in parts, but you can't receive more
                        than was ordered. Once a line is received, it becomes available for inspection over in
                        Quality.
                    </Typography.Paragraph>
                ),
            },
        ],
    },
    {
        key: 'sales',
        title: 'Sales',
        intro: <Typography.Paragraph>Fulfilling and billing customer orders.</Typography.Paragraph>,
        pages: [
            {
                title: 'Customers',
                body: <Typography.Paragraph>Your customer master list, including GSTIN for GST purposes.</Typography.Paragraph>,
            },
            {
                title: 'Sales Orders',
                body: (
                    <Typography.Paragraph>
                        A confirmed order from a customer.
                        <StatusFlow statuses={['Draft', 'Confirmed', 'Partially Delivered', 'Completed / Cancelled']} />
                        Confirming an order doesn't move any stock by itself — that only happens at delivery.
                    </Typography.Paragraph>
                ),
            },
            {
                title: 'Deliveries',
                body: (
                    <Typography.Paragraph>
                        Where you record what actually shipped against a Sales Order —{' '}
                        <b>this is the step that removes stock</b>, not the Sales Order itself. You can't deliver
                        more than was ordered, and you can deliver an order across several shipments if needed.
                    </Typography.Paragraph>
                ),
            },
            {
                title: 'Invoices',
                body: (
                    <>
                        <Typography.Paragraph>
                            Billing, independent of whether goods have shipped.
                            <StatusFlow statuses={['Draft', 'Issued', 'Paid']} />
                        </Typography.Paragraph>
                        <Note>
                            <b>Issuing an invoice triggers three things at once</b>, none of which need you to do
                            anything extra: the invoice becomes available for GST reporting in Compliance, it's
                            counted in Finance's outstanding receivables, and — if your company syncs to Tally —
                            it's automatically queued for export.
                        </Note>
                    </>
                ),
            },
        ],
    },
    {
        key: 'finance',
        title: 'Finance',
        intro: <Typography.Paragraph>Your general ledger.</Typography.Paragraph>,
        pages: [
            {
                title: 'Chart of Accounts',
                body: (
                    <Typography.Paragraph>
                        Your list of GL accounts (asset, liability, equity, income, expense) — every journal
                        entry line debits or credits one of these.
                    </Typography.Paragraph>
                ),
            },
            {
                title: 'Journal Entries',
                body: (
                    <Typography.Paragraph>
                        Manual double-entry bookkeeping. The total of all debit amounts must equal the total of
                        all credit amounts before you can even save one.
                        <StatusFlow statuses={['Draft', 'Posted']} />
                        Posting is what makes it count in Financial Reports, and — same as an invoice — it's
                        automatically queued for Tally if your company uses that.
                    </Typography.Paragraph>
                ),
            },
            {
                title: 'Reports',
                body: (
                    <Typography.Paragraph>
                        Financial reports built from your <b>posted</b> journal entries only — a draft entry
                        doesn't move any number here.
                    </Typography.Paragraph>
                ),
            },
        ],
    },
    {
        key: 'quality',
        title: 'Quality',
        pages: [
            {
                title: 'Incoming Inspections',
                body: (
                    <Typography.Paragraph>
                        Recorded against a specific line on a Goods Receipt. You enter how many units you
                        inspected, accepted, and rejected (accepted + rejected must add up to inspected) — the
                        app works out whether that's a Pass, Fail, or Partial result for you.
                    </Typography.Paragraph>
                ),
            },
            {
                title: 'Non-Conformance Reports (NCR)',
                body: (
                    <Typography.Paragraph>
                        A record of something that didn't meet spec — can be raised from a failed inspection, or
                        on its own against any item.
                        <StatusFlow statuses={['Open', 'Closed']} />
                    </Typography.Paragraph>
                ),
            },
            {
                title: 'CAPA (Corrective / Preventive Action)',
                body: (
                    <Typography.Paragraph>
                        The follow-up action plan for a problem — root cause, corrective action, preventive
                        action — often linked back to an NCR.
                        <StatusFlow statuses={['Open', 'In Progress', 'Closed']} />
                    </Typography.Paragraph>
                ),
            },
            {
                title: 'Measuring Instruments',
                body: (
                    <Typography.Paragraph>
                        Your calibration schedule for gauges/instruments. Expand any row to see its past
                        calibration history.
                    </Typography.Paragraph>
                ),
            },
            {
                title: 'SPC Characteristics / SPC Chart',
                body: (
                    <Typography.Paragraph>
                        Define a measurement you want to track for an item (with target and spec limits), then
                        view recorded measurements plotted against those limits on the chart page.
                    </Typography.Paragraph>
                ),
            },
        ],
    },
    {
        key: 'compliance',
        title: 'Compliance',
        pages: [
            {
                title: 'GST Rates / GST Registrations',
                body: (
                    <Typography.Paragraph>
                        Your GST rate table and your company's own GST registrations, by state. Reference data —
                        set it up once, update as needed.
                    </Typography.Paragraph>
                ),
            },
            {
                title: 'GST Reports',
                body: (
                    <Typography.Paragraph>
                        Reports built from your <b>issued</b> invoices, working out the GST breakdown for each
                        one automatically.
                    </Typography.Paragraph>
                ),
            },
        ],
    },
    {
        key: 'hrms',
        title: 'HRMS',
        pages: [
            {
                title: 'Employees',
                body: (
                    <Typography.Paragraph>
                        Your staff master list — this is what every leave request, attendance record, salary
                        structure, and payslip is tied to.
                    </Typography.Paragraph>
                ),
            },
            {
                title: 'Leave Types / Leave Balances',
                body: (
                    <Typography.Paragraph>
                        Leave Types is your catalog (Casual, Sick, etc.) with a default number of days. Leave
                        Balances is how many days a specific employee has for a specific type in a specific year.
                    </Typography.Paragraph>
                ),
            },
            {
                title: 'Leave Requests',
                body: (
                    <Typography.Paragraph>
                        An employee's request for time off.
                        <StatusFlow statuses={['Pending', 'Approved / Rejected']} />
                        Approving a request automatically deducts those days from the employee's leave balance —
                        if there aren't enough days left, approval will tell you.
                    </Typography.Paragraph>
                ),
            },
            {
                title: 'Attendance',
                body: (
                    <Typography.Paragraph>
                        A simple daily log per employee — Present, Absent, Half Day, or On Leave.
                    </Typography.Paragraph>
                ),
            },
        ],
    },
    {
        key: 'payroll',
        title: 'Payroll',
        pages: [
            {
                title: 'Salary Components',
                body: (
                    <Typography.Paragraph>
                        Building blocks of pay — earnings or deductions, each either a fixed amount or a
                        percentage of Basic pay.
                    </Typography.Paragraph>
                ),
            },
            {
                title: 'Salary Structures',
                body: (
                    <Typography.Paragraph>
                        What pay components apply to a specific employee, and at what amount (or left to resolve
                        as a percentage).
                    </Typography.Paragraph>
                ),
            },
            {
                title: 'Payroll Runs',
                body: (
                    <>
                        <Typography.Paragraph>
                            One per month.
                            <StatusFlow statuses={['Draft', 'Processed', 'Paid']} />
                        </Typography.Paragraph>
                        <ul>
                            <li>
                                <b>Process</b> — calculates and generates a payslip for every employee who has a
                                salary structure set up. Anyone missing a salary structure is skipped, and you're
                                told exactly who by name so nothing pays out silently wrong.
                            </li>
                            <li>
                                <b>Mark Paid</b> — marks the run as paid once you've actually paid everyone.
                            </li>
                        </ul>
                    </>
                ),
            },
            {
                title: 'Payslips',
                body: (
                    <Typography.Paragraph>
                        The generated payslips from a processed run — expand a row to see the individual
                        earning/deduction lines that made up that employee's pay.
                    </Typography.Paragraph>
                ),
            },
        ],
    },
    {
        key: 'maintenance',
        title: 'Maintenance',
        pages: [
            {
                title: 'Assets',
                body: <Typography.Paragraph>Your equipment list — machines, vehicles, anything you maintain.</Typography.Paragraph>,
            },
            {
                title: 'Schedules',
                body: (
                    <Typography.Paragraph>
                        Recurring preventive maintenance plans — how often, and when the next one is due. The{' '}
                        <b>Generate Due Work Orders</b> button scans all your schedules and automatically creates
                        a Work Order for anything that's currently due.
                    </Typography.Paragraph>
                ),
            },
            {
                title: 'Work Orders',
                body: (
                    <Typography.Paragraph>
                        A maintenance job, either corrective (something broke) or preventive (from a schedule).
                        <StatusFlow statuses={['Open', 'In Progress', 'Completed / Cancelled']} />
                        Adding a part to a job pulls it out of stock and adds it to the job's cost, same as
                        everywhere else stock is issued.
                    </Typography.Paragraph>
                ),
            },
            {
                title: 'Reliability Report',
                body: (
                    <Typography.Paragraph>
                        A report summarizing completed maintenance work by asset — how much it's costing you and
                        how often it breaks down.
                    </Typography.Paragraph>
                ),
            },
        ],
    },
    {
        key: 'tally-sync',
        title: 'Tally Sync',
        pages: [
            {
                title: 'Tally Sync',
                body: (
                    <Typography.Paragraph>
                        If your company exports accounting data to Tally, this page shows what's queued.{' '}
                        <b>You don't create anything here</b> — entries appear automatically whenever a Sales
                        Invoice is issued or a Journal Entry is posted.
                        <StatusFlow statuses={['Pending', 'Synced / Failed']} />
                        If something fails, <b>Retry</b> puts it back in the queue. Expand a row to see exactly
                        what will be sent.
                    </Typography.Paragraph>
                ),
            },
        ],
    },
    {
        key: 'administration',
        title: 'Administration',
        pages: [
            {
                title: 'Users',
                body: <Typography.Paragraph>Accounts and who has access. You can't deactivate your own account.</Typography.Paragraph>,
            },
            {
                title: 'Roles',
                body: (
                    <Typography.Paragraph>
                        Named bundles of permissions you assign to users. A role currently assigned to someone
                        can't be deleted.
                    </Typography.Paragraph>
                ),
            },
        ],
    },
];

const faqs: { question: string; answer: string }[] = [
    {
        question: "Why can't I find this item / customer / vendor in a dropdown?",
        answer: "Check whether it's marked inactive on its own page — inactive records disappear from pickers on new documents but stay visible in old ones.",
    },
    {
        question: 'Why is my Serial Number / Batch not showing up when I try to receive stock?',
        answer: "For a serial number, make sure you registered it first on the Serial Numbers page — an unregistered one won't appear. For a batch, create it first on the Batches page.",
    },
    {
        question: "I confirmed a Sales Order but stock didn't change — is that a bug?",
        answer: 'No — confirming an order doesn’t move stock. Stock only moves when you record the Delivery.',
    },
    {
        question: 'Why does the same item appear multiple times on the Stock page?',
        answer: "Because it's listed once per warehouse, not once overall — see the Inventory → Stock section above.",
    },
];

export default function HelpPage() {
    return (
        <>
            <Typography.Title level={3} style={{ marginBottom: 4 }}>
                Help
            </Typography.Title>
            <Typography.Paragraph type="secondary" style={{ maxWidth: 760 }}>
                What each screen is for, what the buttons do, and where the numbers on a page actually come
                from. Sections follow the same order as the menu on the left.
            </Typography.Paragraph>

            <Collapse
                accordion
                style={{ marginTop: 16 }}
                items={modules.map((mod) => ({
                    key: mod.key,
                    label: mod.title,
                    children: (
                        <>
                            {mod.intro}
                            {mod.pages.map((page) => (
                                <div key={page.title} style={{ marginBottom: 20 }}>
                                    <Typography.Title level={5} style={{ marginBottom: 4 }}>
                                        {page.title}
                                    </Typography.Title>
                                    {page.body}
                                </div>
                            ))}
                        </>
                    ),
                }))}
            />

            <Typography.Title level={4} style={{ marginTop: 32 }}>
                Quick answers to common questions
            </Typography.Title>
            {faqs.map((faq) => (
                <div key={faq.question} style={{ marginBottom: 14 }}>
                    <Typography.Text strong>{faq.question}</Typography.Text>
                    <Typography.Paragraph style={{ marginTop: 2, marginBottom: 0 }}>{faq.answer}</Typography.Paragraph>
                </div>
            ))}
        </>
    );
}
