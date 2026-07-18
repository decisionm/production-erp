import { Button, Layout, Typography } from 'antd';
import { useMutation } from '@tanstack/react-query';
import { useNavigate } from 'react-router-dom';
import { logout } from '@/features/auth/api';
import { useAuthStore } from '@/features/auth/store';

export default function DashboardPage() {
    const navigate = useNavigate();
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
        <Layout style={{ minHeight: '100vh', padding: 24 }}>
            <Typography.Title level={3}>Welcome{user ? `, ${user.name}` : ''}</Typography.Title>
            <Typography.Paragraph>
                This is the dashboard shell. Each ERP module (Inventory, Sales, Finance, …)
                gets its own route + feature folder alongside this one.
            </Typography.Paragraph>
            <Button onClick={() => mutation.mutate()} loading={mutation.isPending}>
                Sign out
            </Button>
        </Layout>
    );
}
