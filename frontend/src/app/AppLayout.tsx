import { useMutation } from '@tanstack/react-query';
import { Button, Layout, Menu } from 'antd';
import type { PropsWithChildren } from 'react';
import { useLocation, useNavigate } from 'react-router-dom';
import { logout } from '@/features/auth/api';
import { useAuthStore } from '@/features/auth/store';

const navItems = [
    { key: '/', label: 'Dashboard' },
    { key: '/inventory/items', label: 'Items' },
    { key: '/inventory/warehouses', label: 'Warehouses' },
    { key: '/inventory/stock', label: 'Stock' },
];

export default function AppLayout({ children }: PropsWithChildren) {
    const navigate = useNavigate();
    const location = useLocation();
    const user = useAuthStore((state) => state.user);
    const setUser = useAuthStore((state) => state.setUser);

    const mutation = useMutation({
        mutationFn: logout,
        onSuccess: () => {
            setUser(null);
            navigate('/login');
        },
    });

    return (
        <Layout style={{ minHeight: '100vh' }}>
            <Layout.Header style={{ display: 'flex', alignItems: 'center', gap: 24 }}>
                <span style={{ color: '#fff', fontWeight: 600, marginRight: 24 }}>ERP</span>
                <Menu
                    theme="dark"
                    mode="horizontal"
                    selectedKeys={[location.pathname]}
                    items={navItems}
                    onClick={({ key }) => navigate(key)}
                    style={{ flex: 1, minWidth: 0 }}
                />
                <span style={{ color: '#fff' }}>{user?.name}</span>
                <Button onClick={() => mutation.mutate()} loading={mutation.isPending}>
                    Sign out
                </Button>
            </Layout.Header>
            <Layout.Content style={{ padding: 24 }}>{children}</Layout.Content>
        </Layout>
    );
}
