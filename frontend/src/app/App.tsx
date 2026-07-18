import { BrowserRouter, Navigate, Route, Routes } from 'react-router-dom';
import LoginPage from '@/features/auth/pages/LoginPage';
import DashboardPage from '@/features/dashboard/pages/DashboardPage';
import ProtectedRoute from './ProtectedRoute';

export default function App() {
    return (
        <BrowserRouter>
            <Routes>
                <Route path="/login" element={<LoginPage />} />
                <Route
                    path="/"
                    element={
                        <ProtectedRoute>
                            <DashboardPage />
                        </ProtectedRoute>
                    }
                />
                <Route path="*" element={<Navigate to="/" replace />} />
            </Routes>
        </BrowserRouter>
    );
}
