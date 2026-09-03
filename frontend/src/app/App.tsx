import { Component, lazy, Suspense, type ComponentType, type PropsWithChildren } from 'react';
import { BrowserRouter, Navigate, Route, Routes, useLocation } from 'react-router-dom';
import { Alert, Button, Spin } from 'antd';
import AppLayout from './AppLayout';
import ProtectedRoute from './ProtectedRoute';

type PageModule = { default: ComponentType };

export function RouteChunkFallback() {
    return (
        <Alert
            type="error"
            showIcon
            title="This page could not be loaded"
            description="The app may have been updated while this tab was open, or the connection was interrupted. Reload to fetch the current page files."
            action={<Button onClick={() => window.location.reload()}>Reload page</Button>}
        />
    );
}

class RouteChunkBoundary extends Component<PropsWithChildren, { failed: boolean }> {
    state = { failed: false };

    static getDerivedStateFromError() {
        return { failed: true };
    }

    render() {
        if (this.state.failed) {
            return <RouteChunkFallback />;
        }

        return this.props.children;
    }
}

/**
 * Load only the page the reader opened. The previous static imports pulled
 * every module into the first download, even though one person uses one route
 * at a time. A normal function wrapper keeps the route table inspectable by
 * App.routes.test.tsx while React.lazy supplies the split chunk underneath.
 */
function lazyPage(load: () => Promise<PageModule>) {
    const Page = lazy(load);

    return function LazyRoutePage() {
        return (
            <RouteChunkBoundary>
                <Suspense
                    fallback={
                        <div
                            role="status"
                            aria-live="polite"
                            style={{ minHeight: 180, display: 'grid', placeItems: 'center' }}
                        >
                            <Spin size="large" description="Loading page…" />
                        </div>
                    }
                >
                    <Page />
                </Suspense>
            </RouteChunkBoundary>
        );
    };
}

const RolesPage = lazyPage(() => import('@/features/access/pages/RolesPage'));
const UsersPage = lazyPage(() => import('@/features/access/pages/UsersPage'));
const ChangePasswordPage = lazyPage(() => import('@/features/auth/pages/ChangePasswordPage'));
const LoginPage = lazyPage(() => import('@/features/auth/pages/LoginPage'));
const DashboardPage = lazyPage(() => import('@/features/dashboard/pages/DashboardPage'));
const ExportCenterPage = lazyPage(() => import('@/features/exports/pages/ExportCenterPage'));
const GstRatesPage = lazyPage(() => import('@/features/compliance/pages/GstRatesPage'));
const GstRegistrationsPage = lazyPage(() => import('@/features/compliance/pages/GstRegistrationsPage'));
const GstReportsPage = lazyPage(() => import('@/features/compliance/pages/GstReportsPage'));
const HelpPage = lazyPage(() => import('@/features/help/pages/HelpPage'));
const SettingsPage = lazyPage(() => import('@/features/settings/pages/SettingsPage'));
const AskErpPage = lazyPage(() => import('@/features/ask-erp/pages/AskErpPage'));
const AttendancePage = lazyPage(() => import('@/features/hrms/pages/AttendancePage'));
// Needs no HRMS permission: the read behind it answers only for whoever is
// logged in. See MyAttendanceCard.
const MyAttendancePage = lazyPage(() => import('@/features/hrms/pages/MyAttendancePage'));
const AttendanceImportsPage = lazyPage(() => import('@/features/hrms/pages/AttendanceImportsPage'));
const AttendanceImportPage = lazyPage(() => import('@/features/hrms/pages/AttendanceImportPage'));
const EmployeesPage = lazyPage(() => import('@/features/hrms/pages/EmployeesPage'));
const LeaveBalancesPage = lazyPage(() => import('@/features/hrms/pages/LeaveBalancesPage'));
const LeaveRequestsPage = lazyPage(() => import('@/features/hrms/pages/LeaveRequestsPage'));
const LeaveTypesPage = lazyPage(() => import('@/features/hrms/pages/LeaveTypesPage'));
const AssetsPage = lazyPage(() => import('@/features/maintenance/pages/AssetsPage'));
const ReliabilityReportPage = lazyPage(() => import('@/features/maintenance/pages/ReliabilityReportPage'));
const SchedulesPage = lazyPage(() => import('@/features/maintenance/pages/SchedulesPage'));
const MaintenanceWorkOrdersPage = lazyPage(() => import('@/features/maintenance/pages/WorkOrdersPage'));
const ApproveProductionPage = lazyPage(() => import('@/features/production/pages/ApproveProductionPage'));
const BomsPage = lazyPage(() => import('@/features/production/pages/BomsPage'));
const CapacityPlanPage = lazyPage(() => import('@/features/production/pages/CapacityPlanPage'));
const CartonTracePage = lazyPage(() => import('@/features/production/pages/CartonTracePage'));
const FactoryDayBinPage = lazyPage(() => import('@/features/production/pages/FactoryDayBinPage'));
const MaterialRequestsPage = lazyPage(() => import('@/features/material-flow/pages/MaterialRequestsPage'));
const StoreProductionPage = lazyPage(() => import('@/features/material-flow/pages/StoreProductionPage'));
const MrpPage = lazyPage(() => import('@/features/production/pages/MrpPage'));
const ProductionQueuePage = lazyPage(() => import('@/features/production/pages/ProductionQueuePage'));
const ProductionReportsPage = lazyPage(() => import('@/features/production/pages/ReportsPage'));
const ReworkOrdersPage = lazyPage(() => import('@/features/production/pages/ReworkOrdersPage'));
const RoutingsPage = lazyPage(() => import('@/features/production/pages/RoutingsPage'));
const ProductionConfigurationPage = lazyPage(() => import('@/features/production/pages/ProductionConfigurationPage'));
const LiveMonitorPage = lazyPage(() => import('@/features/production/pages/LiveMonitorPage'));
const ShiftProductionEntryPage = lazyPage(() => import('@/features/production/pages/ShiftProductionEntryPage'));
const ShiftSummaryPage = lazyPage(() => import('@/features/production/pages/ShiftSummaryPage'));
const SubcontractOrdersPage = lazyPage(() => import('@/features/production/pages/SubcontractOrdersPage'));
const WorkOrdersPage = lazyPage(() => import('@/features/production/pages/WorkOrdersPage'));
const PayrollRunsPage = lazyPage(() => import('@/features/payroll/pages/PayrollRunsPage'));
const PayslipsPage = lazyPage(() => import('@/features/payroll/pages/PayslipsPage'));
const SalaryComponentsPage = lazyPage(() => import('@/features/payroll/pages/SalaryComponentsPage'));
const SalaryStructuresPage = lazyPage(() => import('@/features/payroll/pages/SalaryStructuresPage'));
const LeadsPage = lazyPage(() => import('@/features/crm/pages/LeadsPage'));
const OpportunitiesPage = lazyPage(() => import('@/features/crm/pages/OpportunitiesPage'));
const QuotationsPage = lazyPage(() => import('@/features/crm/pages/QuotationsPage'));
const ChartOfAccountsPage = lazyPage(() => import('@/features/finance/pages/ChartOfAccountsPage'));
const ClientOutstandingPage = lazyPage(() => import('@/features/finance/pages/ClientOutstandingPage'));
const JournalEntriesPage = lazyPage(() => import('@/features/finance/pages/JournalEntriesPage'));
const ReportsPage = lazyPage(() => import('@/features/finance/pages/ReportsPage'));
const CapasPage = lazyPage(() => import('@/features/quality/pages/CapasPage'));
const IncomingInspectionsPage = lazyPage(() => import('@/features/quality/pages/IncomingInspectionsPage'));
const InstrumentsPage = lazyPage(() => import('@/features/quality/pages/InstrumentsPage'));
const NonConformanceReportsPage = lazyPage(() => import('@/features/quality/pages/NonConformanceReportsPage'));
const ProductionQcPage = lazyPage(() => import('@/features/quality/pages/ProductionQcPage'));
const ReturnedMaterialHoldsPage = lazyPage(() => import('@/features/quality/pages/ReturnedMaterialHoldsPage'));
const SpcChartPage = lazyPage(() => import('@/features/quality/pages/SpcChartPage'));
const SpcCharacteristicsPage = lazyPage(() => import('@/features/quality/pages/SpcCharacteristicsPage'));
const BarcodeLabelsPage = lazyPage(() => import('@/features/inventory/pages/BarcodeLabelsPage'));
const BatchesPage = lazyPage(() => import('@/features/inventory/pages/BatchesPage'));
const FactoryLookupPage = lazyPage(() => import('@/features/inventory/pages/FactoryLookupPage'));
const ItemDetailPage = lazyPage(() => import('@/features/inventory/pages/ItemDetailPage'));
const ItemsPage = lazyPage(() => import('@/features/inventory/pages/ItemsPage'));
const MaterialLotsPage = lazyPage(() => import('@/features/inventory/pages/MaterialLotsPage'));
const SerialNumbersPage = lazyPage(() => import('@/features/inventory/pages/SerialNumbersPage'));
const PlanningDashboardPage = lazyPage(() => import('@/features/inventory/pages/PlanningDashboardPage'));
const StockMovementsPage = lazyPage(() => import('@/features/inventory/pages/StockMovementsPage'));
const StockPage = lazyPage(() => import('@/features/inventory/pages/StockPage'));
const StoreFulfilmentPage = lazyPage(() => import('@/features/inventory/pages/StoreFulfilmentPage'));
const WarehousesPage = lazyPage(() => import('@/features/inventory/pages/WarehousesPage'));
const GoodsReceiptsPage = lazyPage(() => import('@/features/procurement/pages/GoodsReceiptsPage'));
const SupplierBillsPage = lazyPage(() => import('@/features/procurement/pages/SupplierBillsPage'));
const PurchaseOrdersPage = lazyPage(() => import('@/features/procurement/pages/PurchaseOrdersPage'));
const PurchaseRequisitionsPage = lazyPage(() => import('@/features/procurement/pages/PurchaseRequisitionsPage'));
const VendorsPage = lazyPage(() => import('@/features/procurement/pages/VendorsPage'));
const CustomersPage = lazyPage(() => import('@/features/sales/pages/CustomersPage'));
const DeliveriesPage = lazyPage(() => import('@/features/sales/pages/DeliveriesPage'));
const FulfilmentControlPage = lazyPage(() => import('@/features/sales/pages/FulfilmentControlPage'));
const InvoicesPage = lazyPage(() => import('@/features/sales/pages/InvoicesPage'));
const SalesOrdersPage = lazyPage(() => import('@/features/sales/pages/SalesOrdersPage'));
const AgentTokensPage = lazyPage(() => import('@/features/tally-sync/pages/AgentTokensPage'));
const TallySettingsPage = lazyPage(() => import('@/features/tally-sync/pages/TallySettingsPage'));
const TallySyncPage = lazyPage(() => import('@/features/tally-sync/pages/TallySyncPage'));

export default function App() {
    return (
        <BrowserRouter>
            <Routes>
                <Route path="/login" element={<LoginPage />} />
                <Route
                    path="/*"
                    element={
                        <ProtectedRoute>
                            <AppLayout>
                                <Routes>
                                    <Route path="/" element={<DashboardPage />} />
                                    <Route path="/account/change-password" element={<ChangePasswordPage />} />
                                    <Route path="/ask-erp" element={<AskErpPage />} />
                                    <Route path="/crm/leads" element={<LeadsPage />} />
                                    <Route path="/crm/opportunities" element={<OpportunitiesPage />} />
                                    <Route path="/crm/quotations" element={<QuotationsPage />} />
                                    {/* WHAT IS THIS NUMBER? One box over every
                                        identifier space the factory writes on
                                        something. Addressable (?q=) so a
                                        scanner wedge can land straight on an
                                        answer. */}
                                    <Route path="/inventory/find" element={<FactoryLookupPage />} />
                                    <Route path="/inventory/items" element={<ItemsPage />} />
                                    <Route path="/inventory/items/:id" element={<ItemDetailPage />} />
                                    <Route path="/inventory/warehouses" element={<WarehousesPage />} />
                                    <Route path="/inventory/stock" element={<StockPage />} />
                                    {/* The ledger, first-class. The Stock page's
                                        per-row drawer and the item detail tabs
                                        read the same endpoint per item; this is
                                        the whole factory's movements, paged by
                                        the server. It writes nothing. */}
                                    <Route path="/inventory/stock-movements" element={<StockMovementsPage />} />
                                    <Route path="/inventory/material-lots" element={<MaterialLotsPage />} />
                                    {/* The label bench: bags by barcode, and a
                                        reprint of the identity each bag was born
                                        with. Distinct from the lot register
                                        above, which is per RECEIPT and carries
                                        the GRN provenance. */}
                                    <Route path="/inventory/barcode-labels" element={<BarcodeLabelsPage />} />
                                    <Route path="/inventory/batches" element={<BatchesPage />} />
                                    <Route path="/inventory/serial-numbers" element={<SerialNumbersPage />} />
                                    {/* The STORE's half of the Phase 7.5 material
                                        flow: the queue of what production has asked
                                        for, fulfilled here. It sits under /inventory
                                        because the store is its reader — the floor's
                                        half is /production/material-requests. */}
                                    <Route path="/inventory/store-production" element={<StoreProductionPage />} />
                                    {/* Both halves were their own URL until they
                                        became tabs. Kept alive and query-preserving
                                        for the same reason the four production
                                        configuration URLs are — see
                                        StoreProductionRedirect. */}
                                    <Route
                                        path="/inventory/store-issue-queue"
                                        element={<StoreProductionRedirect tab="issues" />}
                                    />
                                    <Route
                                        path="/inventory/production-returns"
                                        element={<StoreProductionRedirect tab="returns" />}
                                    />
                                    {/* SALES ORDER FULFILMENT, the store's half:
                                        the queue of order lines waiting on stock,
                                        and the ETA dashboard behind what the store
                                        has sent to the floor. Under /inventory for
                                        the same reason the store issue queue is —
                                        the STORE is the reader, and holding stock
                                        back from a customer is the store's act.
                                        Neither route moves stock (invariant 1) and
                                        neither touches a batch (invariant 2). */}
                                    <Route path="/inventory/fulfilment" element={<StoreFulfilmentPage />} />
                                    <Route path="/inventory/planning" element={<PlanningDashboardPage />} />
                                    {/* Work Centers is retired as a screen, not as a
                                        record: the WorkCenter rows it edited are the
                                        machine master, now shown in full on Machine
                                        Setup. The URL is kept so old bookmarks and
                                        anything printed on a wall still land
                                        somewhere true. */}
                                    <Route
                                        path="/production/work-centers"
                                        element={<Navigate to="/production/configuration?tab=machines" replace />}
                                    />
                                    <Route path="/production/configuration" element={<ProductionConfigurationPage />} />
                                    <Route path="/production/boms" element={<BomsPage />} />
                                    <Route path="/production/routings" element={<RoutingsPage />} />
                                    <Route path="/production/work-orders" element={<WorkOrdersPage />} />
                                    <Route path="/production/mrp" element={<MrpPage />} />
                                    <Route path="/production/capacity" element={<CapacityPlanPage />} />
                                    <Route path="/production/subcontract-orders" element={<SubcontractOrdersPage />} />
                                    {/* Scrap Reasons, Molds and Shifts are TABS of
                                        Production Configuration now, not pages of their
                                        own. All three URLs are kept and all three keep
                                        their query string — see
                                        ProductionConfigurationRedirect below for why that
                                        matters even where no caller is known to send one
                                        today. */}
                                    <Route
                                        path="/production/scrap-reasons"
                                        element={<ProductionConfigurationRedirect tab="scrap" />}
                                    />
                                    <Route path="/production/molds" element={<ProductionConfigurationRedirect tab="molds" />} />
                                    <Route path="/production/shifts" element={<ProductionConfigurationRedirect tab="shifts" />} />
                                    <Route path="/production/shift-production" element={<ShiftProductionEntryPage />} />
                                    <Route path="/production/live-monitor" element={<LiveMonitorPage />} />
                                    {/* The internal carton trace tier (DEC-20260810-001).
                                        The route itself is open like every other — the
                                        SERVER's carton-trace permission is the gate (the
                                        page 403s in place); the menu entry is hidden by
                                        the same permission in AppLayout. */}
                                    <Route path="/production/carton-trace" element={<CartonTracePage />} />
                                    {/* Product Standards is a TAB of Production
                                        Configuration now, not a page of its own. The old
                                        URL is kept because real links carry state to it:
                                        the blocked-Start-Batch return trip and the Tally
                                        sync failure deep-links both arrive here with a
                                        query string that MUST survive the hop, which is
                                        why this is a component and not the plain
                                        <Navigate> used for Work Centers above. */}
                                    <Route
                                        path="/production/standards"
                                        element={<ProductionConfigurationRedirect tab="products" />}
                                    />
                                    {/* The central factory day bin (a warehouse). The
                                        per-machine bag-level bin bay below it is the
                                        optional detail, not the main path. */}
                                    <Route path="/production/day-bin" element={<FactoryDayBinPage />} />
                                    {/* Store -> Production material flow (Phase 7.5).
                                        Two screens, one flow: production asks, the
                                        store issues. They are separate URLs because
                                        they are separate readers with separate
                                        standing, and both name the three states in
                                        full — issued to production is NOT consumed. */}
                                    <Route path="/production/material-requests" element={<MaterialRequestsPage />} />
                                    {/* The FLOOR's half of the fulfilment chain —
                                        the prioritized worklist the store raised.
                                        The API behind it is OR-gated
                                        (production OR inventory), so a storekeeper
                                        reaching this URL sees the queue without the
                                        floor's controls; the page reads the two
                                        permissions separately. */}
                                    <Route path="/production/queue" element={<ProductionQueuePage />} />
                                    <Route path="/production/shift-summary" element={<ShiftSummaryPage />} />
                                    <Route path="/production/approve-production" element={<ApproveProductionPage />} />
                                    <Route path="/production/reports" element={<ProductionReportsPage />} />
                                    <Route path="/production/rework-orders" element={<ReworkOrdersPage />} />
                                    <Route path="/procurement/vendors" element={<VendorsPage />} />
                                    {/*
                                      The review is a tab of the vendor master now. The old
                                      path is kept as a redirect so existing bookmarks still
                                      land on the thing they named.
                                    */}
                                    <Route
                                        path="/procurement/tally-vendor-review"
                                        element={<Navigate to="/procurement/vendors?tab=tally-review" replace />}
                                    />
                                    <Route path="/procurement/purchase-requisitions" element={<PurchaseRequisitionsPage />} />
                                    <Route path="/procurement/purchase-orders" element={<PurchaseOrdersPage />} />
                                    <Route path="/procurement/goods-receipts" element={<GoodsReceiptsPage />} />
                                    <Route path="/procurement/supplier-bills" element={<SupplierBillsPage />} />
                                    <Route path="/sales/customers" element={<CustomersPage />} />
                                    <Route path="/sales/sales-orders" element={<SalesOrdersPage />} />
                                    <Route path="/sales/deliveries" element={<DeliveriesPage />} />
                                    <Route path="/sales/invoices" element={<InvoicesPage />} />
                                    <Route path="/sales/fulfilment-control" element={<FulfilmentControlPage />} />
                                    <Route path="/finance/client-outstanding" element={<ClientOutstandingPage />} />
                                    <Route path="/finance/chart-of-accounts" element={<ChartOfAccountsPage />} />
                                    <Route path="/finance/journal-entries" element={<JournalEntriesPage />} />
                                    <Route path="/finance/reports" element={<ReportsPage />} />
                                    <Route path="/quality/production-qc" element={<ProductionQcPage />} />
                                    {/* DAMAGED MATERIAL BACK FROM PRODUCTION
                                        (DEC-20260901-003). The store marks a
                                        return damaged and the server holds it
                                        out of issuable stock; this is where
                                        Quality says whether it becomes Scrap
                                        or goes back to a store. Under /quality
                                        because deciding that is this desk's
                                        act — the API is gated on
                                        module:quality, not inventory. */}
                                    <Route path="/quality/returned-material" element={<ReturnedMaterialHoldsPage />} />
                                    <Route path="/quality/incoming-inspections" element={<IncomingInspectionsPage />} />
                                    <Route path="/quality/ncrs" element={<NonConformanceReportsPage />} />
                                    <Route path="/quality/capas" element={<CapasPage />} />
                                    <Route path="/quality/instruments" element={<InstrumentsPage />} />
                                    <Route path="/quality/spc-characteristics" element={<SpcCharacteristicsPage />} />
                                    <Route path="/quality/spc/:id" element={<SpcChartPage />} />
                                    <Route path="/compliance/gst-rates" element={<GstRatesPage />} />
                                    <Route path="/compliance/gst-registrations" element={<GstRegistrationsPage />} />
                                    <Route path="/compliance/gst-reports" element={<GstReportsPage />} />
                                    <Route path="/hrms/employees" element={<EmployeesPage />} />
                                    <Route path="/hrms/leave-types" element={<LeaveTypesPage />} />
                                    <Route path="/hrms/leave-balances" element={<LeaveBalancesPage />} />
                                    <Route path="/hrms/leave-requests" element={<LeaveRequestsPage />} />
                                    <Route path="/my-attendance" element={<MyAttendancePage />} />
                                    <Route path="/hrms/attendance" element={<AttendancePage />} />
                                    <Route path="/hrms/attendance-imports" element={<AttendanceImportsPage />} />
                                    <Route path="/hrms/attendance-imports/:id" element={<AttendanceImportPage />} />
                                    <Route path="/payroll/salary-components" element={<SalaryComponentsPage />} />
                                    <Route path="/payroll/salary-structures" element={<SalaryStructuresPage />} />
                                    <Route path="/payroll/runs" element={<PayrollRunsPage />} />
                                    <Route path="/payroll/payslips" element={<PayslipsPage />} />
                                    <Route path="/maintenance/assets" element={<AssetsPage />} />
                                    <Route path="/maintenance/schedules" element={<SchedulesPage />} />
                                    <Route path="/maintenance/work-orders" element={<MaintenanceWorkOrdersPage />} />
                                    <Route path="/maintenance/reliability" element={<ReliabilityReportPage />} />
                                    <Route path="/tally-sync" element={<TallySyncPage />} />
                                    <Route path="/tally-sync/agent-tokens" element={<AgentTokensPage />} />
                                    <Route path="/tally-sync/settings" element={<TallySettingsPage />} />
                                    {/* The one destination for the four utilities
                                        below it, which used to sit loose at the
                                        bottom of the sidebar. Ungated like they are:
                                        the CARDS gate themselves (settingsSections
                                        mirrors AppLayout's adoption-then-permission
                                        rule), so a login holding neither users nor
                                        roles still opens the page and sees only
                                        Downloads and Help. */}
                                    <Route path="/settings" element={<SettingsPage />} />
                                    {/* The Download / Export Center. Open to every
                                        login like every other route — the SERVER's
                                        catalogue is what filters: a kind is offered,
                                        and runnable, only to a reader holding one of
                                        its permissions. */}
                                    <Route path="/exports" element={<ExportCenterPage />} />
                                    <Route path="/help" element={<HelpPage />} />
                                    <Route path="/administration/users" element={<UsersPage />} />
                                    <Route path="/administration/roles" element={<RolesPage />} />
                                    <Route path="*" element={<Navigate to="/" replace />} />
                                </Routes>
                            </AppLayout>
                        </ProtectedRoute>
                    }
                />
            </Routes>
        </BrowserRouter>
    );
}

/**
 * Where a retired configuration URL lands, with its query string intact.
 *
 * Exported and pure so the contract is testable without a router: given the
 * incoming `search` and a target tab, this is the URL the reader ends up on.
 *
 * `set`, not `append`: an incoming `?tab=` is OVERWRITTEN, never duplicated.
 * That is the case that actually bites — /production/molds?tab=products is a
 * link somebody can plausibly hand-edit or copy out of a stale note, and a URL
 * carrying two `tab` values resolves to whichever URLSearchParams reads first,
 * which is not a thing to leave to luck.
 */
export function productionConfigurationTarget(search: string, tab: string): string {
    const params = new URLSearchParams(search);
    params.set('tab', tab);

    return `/production/configuration?${params.toString()}`;
}

/**
 * A retired configuration URL, kept alive with its query string intact.
 *
 * A plain <Navigate to="…?tab=x"> would have been shorter and wrong for
 * /production/standards, which is where this started. Two real callers send a
 * user to /production/standards carrying state:
 *
 *  - a blocked Start Batch (startBatchResume's `phase=configure` params),
 *    which is how a supervisor gets back to the batch they were starting;
 *  - the Tally sync failure links, which arrive as
 *    `?view=incomplete&missing_tally=1`.
 *
 * Dropping those parameters would not look broken — it would land the reader
 * on a full, ready-filtered table with no way back to their batch, which is
 * worse than an error. So the incoming search is preserved and `tab` is
 * merged in on top of it.
 *
 * /production/scrap-reasons, /production/molds and /production/shifts have no
 * such caller today. They use the same component anyway: the cost is nothing,
 * and the failure it prevents is silent. /production/work-centers stays a bare
 * <Navigate> — it is pinned as a redirect by App.routes.test.tsx and has no
 * state to carry.
 */
function ProductionConfigurationRedirect({ tab }: { tab: string }) {
    const { search } = useLocation();

    return <Navigate to={productionConfigurationTarget(search, tab)} replace />;
}

/** Same rule as productionConfigurationTarget: `set`, never `append`. */
export function storeProductionTarget(search: string, tab: string): string {
    const params = new URLSearchParams(search);
    params.set('tab', tab);

    return `/inventory/store-production?${params.toString()}`;
}

/**
 * A retired material-flow URL, kept alive with its query string intact.
 *
 * /inventory/store-issue-queue and /inventory/production-returns were live
 * screens in the sidebar — the second of them for barely a day — so both are
 * in real bookmarks and in prose written this week. Neither may 404.
 *
 * A COMPONENT rather than a bare <Navigate> for the reason the production
 * ones are: it carries whatever query string arrives. Nothing sends state to
 * these two today, but the cost is nothing and the failure it prevents is
 * silent — a reader landing on a ready-filtered screen with no way back to
 * what they were looking at.
 *
 * This means the two paths read as PAGES, not as redirects, to
 * App.routes.test.tsx's REDIRECT_ROUTES check (:130), exactly as the four
 * production URLs do. That is deliberate; its docblock says so.
 */
function StoreProductionRedirect({ tab }: { tab: string }) {
    const { search } = useLocation();

    return <Navigate to={storeProductionTarget(search, tab)} replace />;
}
