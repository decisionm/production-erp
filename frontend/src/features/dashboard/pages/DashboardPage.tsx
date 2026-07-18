import { Typography } from 'antd';
import { useAuthStore } from '@/features/auth/store';

export default function DashboardPage() {
    const user = useAuthStore((state) => state.user);

    return (
        <>
            <Typography.Title level={3}>Welcome{user ? `, ${user.name}` : ''}</Typography.Title>
            <Typography.Paragraph>
                This is the dashboard shell. Each ERP module (Inventory, Sales, Finance, …)
                gets its own route + feature folder alongside this one.
            </Typography.Paragraph>
        </>
    );
}
