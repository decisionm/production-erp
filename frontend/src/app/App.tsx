import { BrowserRouter, Navigate, Route, Routes } from 'react-router-dom';
import LoginPage from '@/features/auth/pages/LoginPage';
import DashboardPage from '@/features/dashboard/pages/DashboardPage';
import ItemsPage from '@/features/inventory/pages/ItemsPage';
import StockPage from '@/features/inventory/pages/StockPage';
import WarehousesPage from '@/features/inventory/pages/WarehousesPage';
import GoodsReceiptsPage from '@/features/procurement/pages/GoodsReceiptsPage';
import PurchaseOrdersPage from '@/features/procurement/pages/PurchaseOrdersPage';
import PurchaseRequisitionsPage from '@/features/procurement/pages/PurchaseRequisitionsPage';
import VendorsPage from '@/features/procurement/pages/VendorsPage';
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
                                    <Route path="/inventory/items" element={<ItemsPage />} />
                                    <Route path="/inventory/warehouses" element={<WarehousesPage />} />
                                    <Route path="/inventory/stock" element={<StockPage />} />
                                    <Route path="/procurement/vendors" element={<VendorsPage />} />
                                    <Route path="/procurement/purchase-requisitions" element={<PurchaseRequisitionsPage />} />
                                    <Route path="/procurement/purchase-orders" element={<PurchaseOrdersPage />} />
                                    <Route path="/procurement/goods-receipts" element={<GoodsReceiptsPage />} />
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
