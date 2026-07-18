import { BrowserRouter, Navigate, Route, Routes } from 'react-router-dom';
import LoginPage from '@/features/auth/pages/LoginPage';
import DashboardPage from '@/features/dashboard/pages/DashboardPage';
import ItemsPage from '@/features/inventory/pages/ItemsPage';
import StockPage from '@/features/inventory/pages/StockPage';
import WarehousesPage from '@/features/inventory/pages/WarehousesPage';
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
