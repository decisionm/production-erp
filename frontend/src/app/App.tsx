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
import MoldsPage from '@/features/production/pages/MoldsPage';
import MrpPage from '@/features/production/pages/MrpPage';
import ProductionReportsPage from '@/features/production/pages/ReportsPage';
import ReworkOrdersPage from '@/features/production/pages/ReworkOrdersPage';
import RoutingsPage from '@/features/production/pages/RoutingsPage';
import ScrapReasonsPage from '@/features/production/pages/ScrapReasonsPage';
import ProductionConfigurationPage from '@/features/production/pages/ProductionConfigurationPage';
import LiveMonitorPage from '@/features/production/pages/LiveMonitorPage';
import ShiftProductionEntryPage from '@/features/production/pages/ShiftProductionEntryPage';
import ShiftsPage from '@/features/production/pages/ShiftsPage';
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
import BatchesPage from '@/features/inventory/pages/BatchesPage';
import ItemDetailPage from '@/features/inventory/pages/ItemDetailPage';
import ItemsPage from '@/features/inventory/pages/ItemsPage';
import MaterialLotsPage from '@/features/inventory/pages/MaterialLotsPage';
import SerialNumbersPage from '@/features/inventory/pages/SerialNumbersPage';
import StockPage from '@/features/inventory/pages/StockPage';
import WarehousesPage from '@/features/inventory/pages/WarehousesPage';
import GoodsReceiptsPage from '@/features/procurement/pages/GoodsReceiptsPage';
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
                                    <Route path="/inventory/material-lots" element={<MaterialLotsPage />} />
                                    <Route path="/inventory/batches" element={<BatchesPage />} />
                                    <Route path="/inventory/serial-numbers" element={<SerialNumbersPage />} />
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
                                    <Route path="/production/scrap-reasons" element={<ScrapReasonsPage />} />
                                    <Route path="/production/molds" element={<MoldsPage />} />
                                    <Route path="/production/shifts" element={<ShiftsPage />} />
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
                                    <Route path="/production/standards" element={<ProductStandardsRedirect />} />
                                    {/* The central factory day bin (a warehouse). The
                                        per-machine bag-level bin bay below it is the
                                        optional detail, not the main path. */}
                                    <Route path="/production/day-bin" element={<FactoryDayBinPage />} />
                                    <Route path="/production/shift-summary" element={<ShiftSummaryPage />} />
                                    <Route path="/production/approve-production" element={<ApproveProductionPage />} />
                                    <Route path="/production/reports" element={<ProductionReportsPage />} />
                                    <Route path="/production/rework-orders" element={<ReworkOrdersPage />} />
                                    <Route path="/procurement/vendors" element={<VendorsPage />} />
                                    <Route path="/procurement/purchase-requisitions" element={<PurchaseRequisitionsPage />} />
                                    <Route path="/procurement/purchase-orders" element={<PurchaseOrdersPage />} />
                                    <Route path="/procurement/goods-receipts" element={<GoodsReceiptsPage />} />
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
 * The retired Product Standards URL, kept alive with its query string intact.
 *
 * A plain <Navigate to="…?tab=products"> would have been shorter and wrong.
 * Two real callers send a user to /production/standards carrying state:
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
 */
function ProductStandardsRedirect() {
    const { search } = useLocation();
    const params = new URLSearchParams(search);
    params.set('tab', 'products');

    return <Navigate to={`/production/configuration?${params.toString()}`} replace />;
}
