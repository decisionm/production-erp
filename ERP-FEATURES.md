# Manufacturing ERP — Feature Brainstorm

A comprehensive feature checklist for a Manufacturing ERP covering Production, Inventory, Procurement, Sales/CRM, Finance, HRMS, Payroll, Quality, and Maintenance. Use this as a starting point to scope MVP vs. later phases.

---

## 0. Foundation / Platform

- [ ] Multi-company, multi-plant, multi-warehouse support
- [ ] Multi-currency, multi-language, multi-timezone
- [ ] Role-based access control (RBAC) / permission groups
- [ ] User management, SSO (SAML/OAuth), 2FA
- [ ] Configurable approval workflows (multi-level)
- [ ] Audit trail / change history on all records
- [ ] Document management (attach files, versioning)
- [ ] Notification engine (email, SMS, in-app, push)
- [ ] Master data management (items, customers, vendors, GL accounts, UOM)
- [ ] Custom fields / form builder
- [ ] Numbering series / document sequencing per company
- [ ] Import/export (CSV, Excel) & bulk data tools
- [ ] REST/GraphQL API + webhooks for integrations
- [ ] Mobile app / responsive web access
- [ ] Barcode & QR code generation/scanning
- [ ] Print formats (invoices, labels, work orders)
- [ ] System settings, holiday calendar, fiscal year config
- [ ] Data backup, archival, disaster recovery
- [ ] Activity/task management & internal chat/comments on records

---

## 1. Production / Manufacturing

- [ ] Bill of Materials (BOM) — single & multi-level
- [ ] BOM versioning & engineering change orders (ECO)
- [ ] Routing / operations sequence per item
- [ ] Work center / machine master with capacity
- [ ] Material Requirements Planning (MRP)
- [ ] Master Production Schedule (MPS)
- [ ] Production/work order creation (manual & auto from sales order)
- [ ] Job cards / shop floor operation tracking
- [ ] Capacity planning & finite/infinite scheduling
- [ ] Subcontracting (send raw material, receive finished goods)
- [ ] Batch/lot production tracking
- [ ] Scrap, rework, and rejection tracking
- [ ] Downtime tracking & reason codes
- [ ] Shop floor data collection (SFDC) — terminals/tablets
- [ ] Production costing (actual vs. standard)
- [ ] By-product / co-product handling
- [ ] Kanban / pull-based production support
- [ ] Overall Equipment Effectiveness (OEE) tracking
- [ ] Production dashboards (plan vs. actual)

---

## 2. Inventory & Warehouse Management

- [ ] Multi-warehouse, bin/location-level stock
- [ ] Stock receipt, issue, transfer, adjustment
- [ ] Serial number & batch/lot tracking with expiry
- [ ] Reorder level, safety stock, reorder quantity automation
- [ ] Cycle counting & physical stock verification
- [ ] Stock valuation methods (FIFO, LIFO, moving average, standard cost)
- [ ] Stock aging & slow/non-moving analysis
- [ ] Goods Receipt Note (GRN) & Goods Issue Note
- [ ] Inter-warehouse/inter-company stock transfer
- [ ] Packing, kitting, and unit conversion (UOM)
- [ ] Return material authorization (RMA) — inbound/outbound
- [ ] Quarantine/hold stock for QC
- [ ] Bin/rack optimization & putaway rules
- [ ] Pick-pack-ship workflow
- [ ] Stock reservation against sales/production orders
- [ ] Landed cost allocation (freight, duty, insurance)

---

## 3. Procurement / Purchasing

- [ ] Purchase requisition (with approval workflow)
- [ ] Request for Quotation (RFQ) & vendor comparison
- [ ] Purchase order creation & amendments
- [ ] Vendor master & vendor categorization
- [ ] Vendor evaluation / scorecards (quality, delivery, price)
- [ ] Blanket/contract purchase orders
- [ ] Purchase order tracking (open, partial, closed)
- [ ] Three-way matching (PO, GRN, Invoice)
- [ ] Import purchase & customs documentation
- [ ] Vendor portal (share POs, upload invoices, track payments)
- [ ] Price list / vendor rate contracts
- [ ] Purchase return / debit note

---

## 4. Sales & CRM

- [ ] Lead capture & qualification
- [ ] Opportunity/pipeline management (stages, probability)
- [ ] Quotation/estimate generation
- [ ] Sales order management
- [ ] Customer master & credit limit management
- [ ] Contract & AMC (annual maintenance contract) management
- [ ] Price lists, discounts, promotions
- [ ] Order-to-cash workflow (SO → delivery → invoice → payment)
- [ ] Customer portal (order status, invoices, tickets)
- [ ] After-sales service / complaint & ticket management
- [ ] Warranty tracking
- [ ] Marketing campaign management & email/SMS blasts
- [ ] Sales forecasting & target vs. achievement
- [ ] Sales commission calculation
- [ ] Dealer/distributor management
- [ ] Return/exchange & credit note management

---

## 5. Finance & Accounting

- [ ] General ledger & chart of accounts
- [ ] Accounts payable (vendor invoices, payments)
- [ ] Accounts receivable (customer invoices, collections)
- [ ] Bank & cash management, reconciliation
- [ ] Fixed asset management & depreciation
- [ ] Cost center & profit center accounting
- [ ] Standard costing, job costing, activity-based costing
- [ ] Budgeting & budget vs. actual analysis
- [ ] Tax management (GST/VAT/withholding tax, multi-jurisdiction)
- [ ] Multi-currency revaluation
- [ ] Financial statements (P&L, balance sheet, cash flow)
- [ ] Inter-company transactions & consolidation
- [ ] Letter of credit / bank guarantee tracking
- [ ] Statutory compliance & e-invoicing/e-way bill
- [ ] Audit reports & financial dashboards

---

## 5a. India Statutory & Tax Compliance

- [ ] GST registration handling (CGST/SGST/IGST/UTGST, multi-state GSTIN)
- [ ] HSN/SAC code mapping on items & services
- [ ] GST-compliant invoicing (tax breakup, reverse charge flag)
- [ ] e-Invoicing — IRN generation & QR code (mandatory above turnover threshold)
- [ ] e-Way Bill generation & tracking for goods movement
- [ ] GSTR-1, GSTR-3B, GSTR-2B reconciliation & filing-ready reports
- [ ] Input Tax Credit (ITC) matching & reversal tracking
- [ ] TDS (Tax Deducted at Source) & TCS on purchases/sales — sections, rates, Form 26Q/27EQ
- [ ] TDS on salary (Form 24Q, Form 16 generation)
- [ ] PF (Provident Fund) — EPFO contribution & ECR file generation
- [ ] ESI (Employee State Insurance) contribution & returns
- [ ] Professional Tax (PT) — state-wise slabs
- [ ] Labour Welfare Fund (LWF) — state-wise
- [ ] Income tax regime selection (old/new) for employees, investment declarations (Form 12BB)
- [ ] MSME/Udyam vendor flagging & MSME Samadhaan (delayed payment) compliance
- [ ] Customs duty / import documentation (Bill of Entry, IGST on imports)
- [ ] Composition scheme handling (if applicable to smaller manufacturers)
- [ ] Digital signature (DSC) support for statutory filings where required
- [ ] State-wise compliance calendar & due-date reminders

---

## 6. HRMS

- [ ] Employee master data & organization chart
- [ ] Recruitment & applicant tracking (ATS)
- [ ] Onboarding/offboarding workflows
- [ ] Attendance & time tracking (biometric/geo-fencing integration)
- [ ] Shift management & roster planning
- [ ] Leave management (types, balances, approvals)
- [ ] Performance management (goals, appraisals, 360° feedback)
- [ ] Training & development / certification tracking
- [ ] Employee self-service portal
- [ ] Grievance/disciplinary case management
- [ ] Asset issuance to employees (laptops, tools, PPE)
- [ ] Exit management & clearance workflow
- [ ] Organization document repository (policies, letters)
- [ ] HR analytics (attrition, headcount, diversity)

---

## 7. Payroll

- [ ] Salary structure & compensation components (CTC breakup)
- [ ] Payroll processing (monthly/bi-weekly runs)
- [ ] Statutory compliance (PF, ESI, PT, TDS / country-specific equivalents)
- [ ] Payslip generation & distribution
- [ ] Loans, advances, and reimbursements
- [ ] Overtime & incentive calculation
- [ ] Full & final settlement
- [ ] Tax declaration & investment proof management
- [ ] Bank file generation for salary disbursement
- [ ] Payroll reports & statutory filings

---

## 8. Quality Management (QMS)

- [ ] Incoming quality inspection (raw material)
- [ ] In-process quality checks
- [ ] Final/outgoing quality inspection
- [ ] Quality control plans & inspection templates per item
- [ ] Non-conformance report (NCR) management
- [ ] Corrective and Preventive Action (CAPA)
- [ ] Calibration management for measuring instruments
- [ ] Certificate of Analysis (CoA) / Certificate of Conformance
- [ ] Supplier quality management
- [ ] Compliance tracking (ISO 9001, IATF 16949, etc.)
- [ ] SPC (Statistical Process Control) charts

---

## 9. Maintenance (EAM / CMMS)

- [ ] Asset/equipment master with maintenance history
- [ ] Preventive maintenance scheduling
- [ ] Breakdown/corrective maintenance work orders
- [ ] Spare parts inventory linked to assets
- [ ] Maintenance cost tracking
- [ ] Mean Time Between Failures (MTBF) / Mean Time To Repair (MTTR)
- [ ] Technician scheduling & mobile work orders
- [ ] Asset depreciation link with finance module

---

## 10. Supply Chain / Logistics

- [ ] Demand forecasting & planning
- [ ] Distribution requirement planning (DRP)
- [ ] Transportation management (route, carrier, freight)
- [ ] Shipment tracking & delivery scheduling
- [ ] Import/export documentation & customs compliance
- [ ] Third-party logistics (3PL) integration

---

## 11. Analytics, BI & Reporting

- [ ] Role-based dashboards (executive, plant manager, floor supervisor)
- [ ] Real-time KPIs (OTIF, inventory turns, OEE, DSO/DPO)
- [ ] Custom report builder
- [ ] Drill-down / self-service BI
- [ ] Scheduled report emailing
- [ ] Data export to BI tools (Power BI, Tableau)
- [ ] Predictive analytics (demand, maintenance, quality trends)

---

## 12. Integrations

- [ ] E-commerce / marketplace integration
- [ ] Payment gateway integration (Razorpay/PayU/Cashfree for Indian market)
- [ ] IoT/machine data integration (PLC/SCADA)
- [ ] Government e-invoicing/GST/e-way bill portals (NIC APIs or GSPs like ClearTax, Vayana)
- [ ] Courier/logistics API integration (Delhivery, Shiprocket, etc.)
- [ ] SMS/WhatsApp/email gateways (MSG91, Gupshup, WhatsApp Business API)

### Tally Integration (detail)

Many Indian manufacturers already run accounting in Tally (Tally Prime / Tally.ERP 9) and won't migrate off it immediately — plan for coexistence, not just one-time migration.

- [ ] Decide sync direction: ERP → Tally (push vouchers only), Tally → ERP (pull existing masters), or two-way
- [ ] Voucher sync: sales invoice, purchase invoice, payment, receipt, journal, credit/debit note
- [ ] Master sync: ledgers, stock items, godowns/warehouses, cost centers
- [ ] Use Tally's XML HTTP API (port 9000) for local network sync, or ODBC for read queries
- [ ] Handle Tally being desktop-installed on-prem while ERP is cloud-hosted — needs a lightweight local sync agent/connector (scheduled service) since Tally has no public cloud API
- [ ] Conflict resolution rules (what happens if a voucher is edited in both systems)
- [ ] GST return data cross-check between ERP-generated and Tally-generated numbers
- [ ] Fallback: scheduled XML/Excel export-import if real-time sync isn't feasible early on

---

## 13. Technical Architecture (React + Laravel + MySQL, SaaS)

### Stack fit
- React (SPA/Next.js) frontend, Laravel API backend, MySQL — solid, well-supported combination with a huge Indian dev talent pool. Fine for this scope.

### Multi-tenancy model (pick one)
- [ ] **Shared DB, shared schema** — single MySQL DB, every table has `tenant_id`, enforced via Laravel global scopes/middleware. Cheapest, simplest to host, weakest data isolation — a scoping bug leaks data across tenants.
- [ ] **Shared DB, DB-per-tenant** — one MySQL server, separate database per tenant (Laravel supports dynamic connections). Better isolation, harder migrations (must run per tenant).
- [ ] **Fully isolated (DB + app instance per tenant)** — strongest isolation, most expensive/complex, usually only for large enterprise customers.
- Recommendation for an ERP holding financial/payroll/GST data: shared-schema with `tenant_id` is workable for SMB customers, but plan the abstraction so you can move a customer to DB-per-tenant later (compliance-sensitive customers often demand it).

### SaaS platform mechanics to design for
- [ ] Tenant provisioning/onboarding (subdomain or custom domain per tenant)
- [ ] Subscription/billing & plan-based feature gating (modules enabled per plan)
- [ ] Per-tenant usage limits (users, storage, API calls)
- [ ] Central admin panel (super-admin) separate from tenant admin
- [ ] Tenant-level data export/backup & right-to-be-forgotten (data deletion on churn)
- [ ] Queued/background jobs: GST filing prep, payroll runs, report generation, Tally sync, email/SMS sending
- [ ] Scheduled jobs (cron): recurring invoices, reminders, PF/ESI due-date alerts

### ⚠️ Shared hosting — flagging a real constraint
Shared hosting (typical cPanel-style plans) is a poor fit for a **multi-tenant SaaS Laravel app** at anything past a pilot, because:
- No persistent queue workers (Laravel queues need a long-running process — `php artisan queue:work` — shared hosts kill long processes)
- No Redis/Memcached usually available (needed for queues, caching, session isolation across tenants)
- Cron granularity is often limited to 5-min minimum, sometimes fewer allowed jobs
- No WebSockets support (real-time notifications, live dashboards won't work)
- No wildcard subdomain + SSL automation in most budget shared plans (blocks `tenant.yourapp.com` pattern)
- Shared CPU/memory — one tenant's heavy MRP run or payroll batch can degrade everyone
- No shell/SSH access on cheaper tiers — makes deployment, migrations, and the Tally sync agent hard to manage
- MySQL connection limits are often capped low, a problem once you have concurrent tenants

**Recommendation:** use a low-cost VPS (DigitalOcean, Hetzner, AWS Lightsail, or Indian providers like Hostinger VPS/E2E Networks) instead — cost difference vs. shared hosting is small (₹500–1500/mo range) but unlocks queues, Redis, cron, SSH, and wildcard SSL, all of which this app needs from day one. Shared hosting can work only for a single-tenant pilot/demo with no background jobs.

---

## Suggested Phasing

| Phase | Modules |
|---|---|
| **MVP** | Foundation, Inventory, basic Production (BOM + Work Orders), Sales Orders, Purchasing, basic Finance |
| **Phase 2** | Full Production (MRP, routing, capacity), CRM, Quality (QMS), HRMS core |
| **Phase 3** | Payroll, Maintenance (CMMS), Advanced Finance (costing, budgeting) |
| **Phase 4** | Supply Chain/Logistics, Advanced Analytics/BI, IoT integration, Vendor/Customer portals |

---

## Open Questions for Brainstorming

- What industry vertical within manufacturing (discrete, process, batch, job-shop)?
- Target company size (SMB vs. enterprise) — affects complexity of MRP/costing?
- Single plant or multi-plant, single state or multi-state GST from day one?
- Build vs. extend an open-source ERP (Odoo, ERPNext — ERPNext already has strong India GST support) vs. fully custom Laravel build?
- Is Tally sync a hard requirement for launch, or can early customers start natively in the ERP and drop Tally later?
- Confirm hosting: are you set on shared hosting, or open to a budget VPS given the queue/cron/Redis limitations above?
- GST filing: build native GSTR prep, or integrate a GST Suvidha Provider (GSP) API (ClearTax, Vayana, MasterGST) instead of talking to the NIC APIs directly?
- Payroll compliance: handle PF/ESI/PT in-house, or integrate a payroll compliance API/service?
