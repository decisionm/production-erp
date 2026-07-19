import { useMutation } from '@tanstack/react-query';
import { Button, Layout, Menu } from 'antd';
import type { PropsWithChildren } from 'react';
import { useLocation, useNavigate } from 'react-router-dom';
import { logout } from '@/features/auth/api';
import { useAuthStore } from '@/features/auth/store';

const navItems = [
    { key: '/', label: 'Dashboard' },
    {
        key: 'crm',
        label: 'CRM',
        children: [
            { key: '/crm/leads', label: 'Leads' },
            { key: '/crm/opportunities', label: 'Opportunities' },
            { key: '/crm/quotations', label: 'Quotations' },
        ],
    },
    {
        key: 'inventory',
        label: 'Inventory',
        children: [
            { key: '/inventory/items', label: 'Items' },
            { key: '/inventory/warehouses', label: 'Warehouses' },
            { key: '/inventory/stock', label: 'Stock' },
        ],
    },
    {
        key: 'production',
        label: 'Production',
        children: [
            { key: '/production/work-centers', label: 'Work Centers' },
            { key: '/production/boms', label: 'Bills of Material' },
            { key: '/production/routings', label: 'Routings' },
            { key: '/production/work-orders', label: 'Work Orders' },
            { key: '/production/mrp', label: 'MRP' },
        ],
    },
    {
        key: 'procurement',
        label: 'Procurement',
        children: [
            { key: '/procurement/vendors', label: 'Vendors' },
            { key: '/procurement/purchase-requisitions', label: 'Purchase Requisitions' },
            { key: '/procurement/purchase-orders', label: 'Purchase Orders' },
            { key: '/procurement/goods-receipts', label: 'Goods Receipts' },
        ],
    },
    {
        key: 'sales',
        label: 'Sales',
        children: [
            { key: '/sales/customers', label: 'Customers' },
            { key: '/sales/sales-orders', label: 'Sales Orders' },
            { key: '/sales/deliveries', label: 'Deliveries' },
            { key: '/sales/invoices', label: 'Invoices' },
        ],
    },
    {
        key: 'finance',
        label: 'Finance',
        children: [
            { key: '/finance/chart-of-accounts', label: 'Chart of Accounts' },
            { key: '/finance/journal-entries', label: 'Journal Entries' },
            { key: '/finance/reports', label: 'Reports' },
        ],
    },
    {
        key: 'quality',
        label: 'Quality',
        children: [
            { key: '/quality/incoming-inspections', label: 'Incoming Inspections' },
            { key: '/quality/ncrs', label: 'Non-Conformance Reports' },
        ],
    },
    {
        key: 'compliance',
        label: 'Compliance',
        children: [
            { key: '/compliance/gst-rates', label: 'GST Rates' },
            { key: '/compliance/gst-registrations', label: 'GST Registrations' },
            { key: '/compliance/gst-reports', label: 'GST Reports' },
        ],
    },
    {
        key: 'hrms',
        label: 'HRMS',
        children: [
            { key: '/hrms/employees', label: 'Employees' },
            { key: '/hrms/leave-types', label: 'Leave Types' },
            { key: '/hrms/leave-balances', label: 'Leave Balances' },
            { key: '/hrms/leave-requests', label: 'Leave Requests' },
            { key: '/hrms/attendance', label: 'Attendance' },
        ],
    },
    {
        key: 'payroll',
        label: 'Payroll',
        children: [
            { key: '/payroll/salary-components', label: 'Salary Components' },
            { key: '/payroll/salary-structures', label: 'Salary Structures' },
            { key: '/payroll/runs', label: 'Payroll Runs' },
            { key: '/payroll/payslips', label: 'Payslips' },
        ],
    },
    { key: '/tally-sync', label: 'Tally Sync' },
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
