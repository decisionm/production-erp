import { BrowserRouter, Navigate, Route, Routes } from 'react-router-dom';
import RolesPage from '@/features/access/pages/RolesPage';
import UsersPage from '@/features/access/pages/UsersPage';
import LoginPage from '@/features/auth/pages/LoginPage';
import DashboardPage from '@/features/dashboard/pages/DashboardPage';
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
import MoldsPage from '@/features/production/pages/MoldsPage';
import MrpPage from '@/features/production/pages/MrpPage';
import ReworkOrdersPage from '@/features/production/pages/ReworkOrdersPage';
import RoutingsPage from '@/features/production/pages/RoutingsPage';
import ScrapReasonsPage from '@/features/production/pages/ScrapReasonsPage';
import ShiftProductionEntryPage from '@/features/production/pages/ShiftProductionEntryPage';
import ShiftsPage from '@/features/production/pages/ShiftsPage';
import ShiftSummaryPage from '@/features/production/pages/ShiftSummaryPage';
import SubcontractOrdersPage from '@/features/production/pages/SubcontractOrdersPage';
import WorkCentersPage from '@/features/production/pages/WorkCentersPage';
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
import SpcChartPage from '@/features/quality/pages/SpcChartPage';
import SpcCharacteristicsPage from '@/features/quality/pages/SpcCharacteristicsPage';
import BatchesPage from '@/features/inventory/pages/BatchesPage';
import ItemDetailPage from '@/features/inventory/pages/ItemDetailPage';
import ItemsPage from '@/features/inventory/pages/ItemsPage';
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
                                    <Route path="/crm/leads" element={<LeadsPage />} />
                                    <Route path="/crm/opportunities" element={<OpportunitiesPage />} />
                                    <Route path="/crm/quotations" element={<QuotationsPage />} />
                                    <Route path="/inventory/items" element={<ItemsPage />} />
                                    <Route path="/inventory/items/:id" element={<ItemDetailPage />} />
                                    <Route path="/inventory/warehouses" element={<WarehousesPage />} />
                                    <Route path="/inventory/stock" element={<StockPage />} />
                                    <Route path="/inventory/batches" element={<BatchesPage />} />
                                    <Route path="/inventory/serial-numbers" element={<SerialNumbersPage />} />
                                    <Route path="/production/work-centers" element={<WorkCentersPage />} />
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
                                    <Route path="/production/shift-summary" element={<ShiftSummaryPage />} />
                                    <Route path="/production/approve-production" element={<ApproveProductionPage />} />
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
