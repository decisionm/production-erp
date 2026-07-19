import { BrowserRouter, Navigate, Route, Routes } from 'react-router-dom';
import LoginPage from '@/features/auth/pages/LoginPage';
import DashboardPage from '@/features/dashboard/pages/DashboardPage';
import GstRatesPage from '@/features/compliance/pages/GstRatesPage';
import GstRegistrationsPage from '@/features/compliance/pages/GstRegistrationsPage';
import GstReportsPage from '@/features/compliance/pages/GstReportsPage';
import AttendancePage from '@/features/hrms/pages/AttendancePage';
import EmployeesPage from '@/features/hrms/pages/EmployeesPage';
import LeaveBalancesPage from '@/features/hrms/pages/LeaveBalancesPage';
import LeaveRequestsPage from '@/features/hrms/pages/LeaveRequestsPage';
import LeaveTypesPage from '@/features/hrms/pages/LeaveTypesPage';
import LeadsPage from '@/features/crm/pages/LeadsPage';
import OpportunitiesPage from '@/features/crm/pages/OpportunitiesPage';
import QuotationsPage from '@/features/crm/pages/QuotationsPage';
import ChartOfAccountsPage from '@/features/finance/pages/ChartOfAccountsPage';
import JournalEntriesPage from '@/features/finance/pages/JournalEntriesPage';
import ReportsPage from '@/features/finance/pages/ReportsPage';
import IncomingInspectionsPage from '@/features/quality/pages/IncomingInspectionsPage';
import NonConformanceReportsPage from '@/features/quality/pages/NonConformanceReportsPage';
import ItemsPage from '@/features/inventory/pages/ItemsPage';
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
                                    <Route path="/inventory/warehouses" element={<WarehousesPage />} />
                                    <Route path="/inventory/stock" element={<StockPage />} />
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
                                    <Route path="/compliance/gst-rates" element={<GstRatesPage />} />
                                    <Route path="/compliance/gst-registrations" element={<GstRegistrationsPage />} />
                                    <Route path="/compliance/gst-reports" element={<GstReportsPage />} />
                                    <Route path="/hrms/employees" element={<EmployeesPage />} />
                                    <Route path="/hrms/leave-types" element={<LeaveTypesPage />} />
                                    <Route path="/hrms/leave-balances" element={<LeaveBalancesPage />} />
                                    <Route path="/hrms/leave-requests" element={<LeaveRequestsPage />} />
                                    <Route path="/hrms/attendance" element={<AttendancePage />} />
                                    <Route path="/tally-sync" element={<TallySyncPage />} />
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
