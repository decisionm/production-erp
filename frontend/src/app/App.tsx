import { BrowserRouter, Navigate, Route, Routes, useLocation } from 'react-router-dom';
import RolesPage from '@/features/access/pages/RolesPage';
import UsersPage from '@/features/access/pages/UsersPage';
import ChangePasswordPage from '@/features/auth/pages/ChangePasswordPage';
import LoginPage from '@/features/auth/pages/LoginPage';
import DashboardPage from '@/features/dashboard/pages/DashboardPage';
import ExportCenterPage from '@/features/exports/pages/ExportCenterPage';
import GstRatesPage from '@/features/compliance/pages/GstRatesPage';
import GstRegistrationsPage from '@/features/compliance/pages/GstRegistrationsPage';
import GstReportsPage from '@/features/compliance/pages/GstReportsPage';
import HelpPage from '@/features/help/pages/HelpPage';
import AttendancePage from '@/features/hrms/pages/AttendancePage';
import EmployeesPage from '@/features/hrms/pages/EmployeesPage';
import LeaveBalancesPage from '@/features/hrms/pages/LeaveBalancesPage';
import LeaveRequestsPage from '@/features/hrms/pages/LeaveRequestsPage';
import LeaveTypesPage from '@/features/hrms/pages/LeaveTypesPage';
import AssetsPage from '@/features/maintenance/pages/AssetsPage';
import ReliabilityReportPage from '@/features/maintenance/pages/ReliabilityReportPage';
import SchedulesPage from '@/features/maintenance/pages/SchedulesPage';
import MaintenanceWorkOrdersPage from '@/features/maintenance/pages/WorkOrdersPage';
import ApproveProductionPage from '@/features/production/pages/ApproveProductionPage';
import BomsPage from '@/features/production/pages/BomsPage';
import CapacityPlanPage from '@/features/production/pages/CapacityPlanPage';
import CartonTracePage from '@/features/production/pages/CartonTracePage';
import FactoryDayBinPage from '@/features/production/pages/FactoryDayBinPage';
import MaterialRequestsPage from '@/features/material-flow/pages/MaterialRequestsPage';
import StoreIssueQueuePage from '@/features/material-flow/pages/StoreIssueQueuePage';
import MrpPage from '@/features/production/pages/MrpPage';
import ProductionQueuePage from '@/features/production/pages/ProductionQueuePage';
import ProductionReportsPage from '@/features/production/pages/ReportsPage';
import ReworkOrdersPage from '@/features/production/pages/ReworkOrdersPage';
import RoutingsPage from '@/features/production/pages/RoutingsPage';
import ProductionConfigurationPage from '@/features/production/pages/ProductionConfigurationPage';
import LiveMonitorPage from '@/features/production/pages/LiveMonitorPage';
import ShiftProductionEntryPage from '@/features/production/pages/ShiftProductionEntryPage';
import ShiftSummaryPage from '@/features/production/pages/ShiftSummaryPage';
import SubcontractOrdersPage from '@/features/production/pages/SubcontractOrdersPage';
import WorkOrdersPage from '@/features/production/pages/WorkOrdersPage';
import PayrollRunsPage from '@/features/payroll/pages/PayrollRunsPage';
import PayslipsPage from '@/features/payroll/pages/PayslipsPage';
import SalaryComponentsPage from '@/features/payroll/pages/SalaryComponentsPage';
import SalaryStructuresPage from '@/features/payroll/pages/SalaryStructuresPage';
import LeadsPage from '@/features/crm/pages/LeadsPage';
import OpportunitiesPage from '@/features/crm/pages/OpportunitiesPage';
import QuotationsPage from '@/features/crm/pages/QuotationsPage';
import ChartOfAccountsPage from '@/features/finance/pages/ChartOfAccountsPage';
import JournalEntriesPage from '@/features/finance/pages/JournalEntriesPage';
import ReportsPage from '@/features/finance/pages/ReportsPage';
import CapasPage from '@/features/quality/pages/CapasPage';
import IncomingInspectionsPage from '@/features/quality/pages/IncomingInspectionsPage';
import InstrumentsPage from '@/features/quality/pages/InstrumentsPage';
import NonConformanceReportsPage from '@/features/quality/pages/NonConformanceReportsPage';
import ProductionQcPage from '@/features/quality/pages/ProductionQcPage';
import SpcChartPage from '@/features/quality/pages/SpcChartPage';
import SpcCharacteristicsPage from '@/features/quality/pages/SpcCharacteristicsPage';
import BarcodeLabelsPage from '@/features/inventory/pages/BarcodeLabelsPage';
import BatchesPage from '@/features/inventory/pages/BatchesPage';
import ItemDetailPage from '@/features/inventory/pages/ItemDetailPage';
import ItemsPage from '@/features/inventory/pages/ItemsPage';
import MaterialLotsPage from '@/features/inventory/pages/MaterialLotsPage';
import SerialNumbersPage from '@/features/inventory/pages/SerialNumbersPage';
import PlanningDashboardPage from '@/features/inventory/pages/PlanningDashboardPage';
import StockMovementsPage from '@/features/inventory/pages/StockMovementsPage';
import StockPage from '@/features/inventory/pages/StockPage';
import StoreFulfilmentPage from '@/features/inventory/pages/StoreFulfilmentPage';
import WarehousesPage from '@/features/inventory/pages/WarehousesPage';
import GoodsReceiptsPage from '@/features/procurement/pages/GoodsReceiptsPage';
import SupplierBillsPage from '@/features/procurement/pages/SupplierBillsPage';
import PurchaseOrdersPage from '@/features/procurement/pages/PurchaseOrdersPage';
import PurchaseRequisitionsPage from '@/features/procurement/pages/PurchaseRequisitionsPage';
import VendorsPage from '@/features/procurement/pages/VendorsPage';
import CustomersPage from '@/features/sales/pages/CustomersPage';
import DeliveriesPage from '@/features/sales/pages/DeliveriesPage';
import InvoicesPage from '@/features/sales/pages/InvoicesPage';
import SalesOrdersPage from '@/features/sales/pages/SalesOrdersPage';
import AgentTokensPage from '@/features/tally-sync/pages/AgentTokensPage';
import TallySettingsPage from '@/features/tally-sync/pages/TallySettingsPage';
import TallySyncPage from '@/features/tally-sync/pages/TallySyncPage';
import AppLayout from './AppLayout';
import ProtectedRoute from './ProtectedRoute';

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
                                    <Route path="/crm/leads" element={<LeadsPage />} />
                                    <Route path="/crm/opportunities" element={<OpportunitiesPage />} />
                                    <Route path="/crm/quotations" element={<QuotationsPage />} />
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
                                    <Route path="/inventory/store-issue-queue" element={<StoreIssueQueuePage />} />
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
                                    <Route path="/procurement/purchase-requisitions" element={<PurchaseRequisitionsPage />} />
                                    <Route path="/procurement/purchase-orders" element={<PurchaseOrdersPage />} />
                                    <Route path="/procurement/goods-receipts" element={<GoodsReceiptsPage />} />
                                    <Route path="/procurement/supplier-bills" element={<SupplierBillsPage />} />
                                    <Route path="/sales/customers" element={<CustomersPage />} />
                                    <Route path="/sales/sales-orders" element={<SalesOrdersPage />} />
                                    <Route path="/sales/deliveries" element={<DeliveriesPage />} />
                                    <Route path="/sales/invoices" element={<InvoicesPage />} />
                                    <Route path="/finance/chart-of-accounts" element={<ChartOfAccountsPage />} />
                                    <Route path="/finance/journal-entries" element={<JournalEntriesPage />} />
                                    <Route path="/finance/reports" element={<ReportsPage />} />
                                    <Route path="/quality/production-qc" element={<ProductionQcPage />} />
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
                                    <Route path="/hrms/attendance" element={<AttendancePage />} />
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
