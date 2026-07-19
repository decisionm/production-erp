import {
    AccountBookOutlined,
    BuildOutlined,
    ContactsOutlined,
    DashboardOutlined,
    FileProtectOutlined,
    InboxOutlined,
    LogoutOutlined,
    MenuFoldOutlined,
    MenuUnfoldOutlined,
    QuestionCircleOutlined,
    SafetyCertificateOutlined,
    SettingOutlined,
    ShopOutlined,
    ShoppingCartOutlined,
    SyncOutlined,
    TeamOutlined,
    ToolOutlined,
    WalletOutlined,
} from '@ant-design/icons';
import { useMutation } from '@tanstack/react-query';
import { Avatar, Dropdown, Layout, Menu, type MenuProps, Space, Typography } from 'antd';
import { type PropsWithChildren, type ReactNode, useEffect, useMemo, useState } from 'react';
import { useLocation, useNavigate } from 'react-router-dom';
import { logout } from '@/features/auth/api';
import { hasModuleAccess } from '@/features/auth/permissions';
import { useAuthStore } from '@/features/auth/store';
import type { User } from '@/features/auth/types';

interface NavLeaf {
    key: string;
    label: string;
    module?: string;
}

interface NavGroup {
    key: string;
    icon: ReactNode;
    label: string;
    module?: string;
    children?: NavLeaf[];
}

const allNavItems: NavGroup[] = [
    { key: '/', icon: <DashboardOutlined />, label: 'Dashboard' },
    {
        key: 'crm',
        icon: <ContactsOutlined />,
        label: 'CRM',
        module: 'crm',
        children: [
            { key: '/crm/leads', label: 'Leads' },
            { key: '/crm/opportunities', label: 'Opportunities' },
            { key: '/crm/quotations', label: 'Quotations' },
        ],
    },
    {
        key: 'inventory',
        icon: <InboxOutlined />,
        label: 'Inventory',
        module: 'inventory',
        children: [
            { key: '/inventory/items', label: 'Items' },
            { key: '/inventory/warehouses', label: 'Warehouses' },
            { key: '/inventory/stock', label: 'Stock' },
            { key: '/inventory/batches', label: 'Batches' },
            { key: '/inventory/serial-numbers', label: 'Serial Numbers' },
        ],
    },
    {
        key: 'production',
        icon: <ToolOutlined />,
        label: 'Production',
        module: 'production',
        children: [
            { key: '/production/work-centers', label: 'Work Centers' },
            { key: '/production/boms', label: 'Bills of Material' },
            { key: '/production/routings', label: 'Routings' },
            { key: '/production/work-orders', label: 'Work Orders' },
            { key: '/production/mrp', label: 'MRP' },
            { key: '/production/capacity', label: 'Capacity Planning' },
            { key: '/production/subcontract-orders', label: 'Subcontract Orders' },
            { key: '/production/scrap-reasons', label: 'Scrap Reasons' },
            { key: '/production/rework-orders', label: 'Rework Orders' },
        ],
    },
    {
        key: 'procurement',
        icon: <ShopOutlined />,
        label: 'Procurement',
        module: 'procurement',
        children: [
            { key: '/procurement/vendors', label: 'Vendors' },
            { key: '/procurement/purchase-requisitions', label: 'Purchase Requisitions' },
            { key: '/procurement/purchase-orders', label: 'Purchase Orders' },
            { key: '/procurement/goods-receipts', label: 'Goods Receipts' },
        ],
    },
    {
        key: 'sales',
        icon: <ShoppingCartOutlined />,
        label: 'Sales',
        module: 'sales',
        children: [
            { key: '/sales/customers', label: 'Customers' },
            { key: '/sales/sales-orders', label: 'Sales Orders' },
            { key: '/sales/deliveries', label: 'Deliveries' },
            { key: '/sales/invoices', label: 'Invoices' },
        ],
    },
    {
        key: 'finance',
        icon: <AccountBookOutlined />,
        label: 'Finance',
        module: 'finance',
        children: [
            { key: '/finance/chart-of-accounts', label: 'Chart of Accounts' },
            { key: '/finance/journal-entries', label: 'Journal Entries' },
            { key: '/finance/reports', label: 'Reports' },
        ],
    },
    {
        key: 'quality',
        icon: <SafetyCertificateOutlined />,
        label: 'Quality',
        module: 'quality',
        children: [
            { key: '/quality/incoming-inspections', label: 'Incoming Inspections' },
            { key: '/quality/ncrs', label: 'Non-Conformance Reports' },
            { key: '/quality/capas', label: 'CAPA' },
            { key: '/quality/instruments', label: 'Measuring Instruments' },
            { key: '/quality/spc-characteristics', label: 'SPC Characteristics' },
        ],
    },
    {
        key: 'compliance',
        icon: <FileProtectOutlined />,
        label: 'Compliance',
        module: 'compliance',
        children: [
            { key: '/compliance/gst-rates', label: 'GST Rates' },
            { key: '/compliance/gst-registrations', label: 'GST Registrations' },
            { key: '/compliance/gst-reports', label: 'GST Reports' },
        ],
    },
    {
        key: 'hrms',
        icon: <TeamOutlined />,
        label: 'HRMS',
        module: 'hrms',
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
        icon: <WalletOutlined />,
        label: 'Payroll',
        module: 'payroll',
        children: [
            { key: '/payroll/salary-components', label: 'Salary Components' },
            { key: '/payroll/salary-structures', label: 'Salary Structures' },
            { key: '/payroll/runs', label: 'Payroll Runs' },
            { key: '/payroll/payslips', label: 'Payslips' },
        ],
    },
    {
        key: 'maintenance',
        icon: <BuildOutlined />,
        label: 'Maintenance',
        module: 'maintenance',
        children: [
            { key: '/maintenance/assets', label: 'Assets' },
            { key: '/maintenance/schedules', label: 'Schedules' },
            { key: '/maintenance/work-orders', label: 'Work Orders' },
            { key: '/maintenance/reliability', label: 'Reliability Report' },
        ],
    },
    { key: '/tally-sync', icon: <SyncOutlined />, label: 'Tally Sync', module: 'tally-sync' },
    { key: '/help', icon: <QuestionCircleOutlined />, label: 'Help' },
    {
        key: 'administration',
        icon: <SettingOutlined />,
        label: 'Administration',
        children: [
            { key: '/administration/users', label: 'Users', module: 'users' },
            { key: '/administration/roles', label: 'Roles', module: 'roles' },
        ],
    },
];

function buildNavItems(user: User | null) {
    return allNavItems
        .map((item) => {
            // The group's own module gates the whole group (CRM, Inventory, ...
            // each set `module` only at this level, not per-child). Checked
            // before the children filter below, which handles groups like
            // Administration where individual children carry their own,
            // more granular module instead.
            if (item.module && !hasModuleAccess(user, item.module)) return null;
            if (item.children) {
                const children = item.children.filter((child) => !child.module || hasModuleAccess(user, child.module));
                if (children.length === 0) return null;
                return { ...item, children };
            }
            return item;
        })
        .filter((item): item is NavGroup => item !== null);
}

export default function AppLayout({ children }: PropsWithChildren) {
    const navigate = useNavigate();
    const location = useLocation();
    const user = useAuthStore((state) => state.user);
    const setUser = useAuthStore((state) => state.setUser);
    const [collapsed, setCollapsed] = useState(false);
    const [isMobile, setIsMobile] = useState(false);

    const mutation = useMutation({
        mutationFn: logout,
        onSuccess: () => {
            setUser(null);
            navigate('/login');
        },
    });

    const navItems = useMemo(() => buildNavItems(user), [user]);
    const openKey = navItems.find((item) => item.children?.some((child) => child.key === location.pathname))?.key;
    const rootSubmenuKeys = useMemo(() => navItems.filter((item) => item.children).map((item) => item.key), [navItems]);

    // Accordion behaviour: opening one module group closes whichever else was
    // open, so the sidebar can't stack several fully-expanded groups at once
    // (that's what buried the Help entry between Maintenance and
    // Administration when both happened to be open).
    const [openKeys, setOpenKeys] = useState<string[]>(openKey ? [openKey] : []);
    useEffect(() => {
        setOpenKeys(openKey ? [openKey] : []);
    }, [openKey]);

    const menuItems = useMemo(() => {
        const items: unknown[] = [];
        navItems.forEach((item) => {
            if (item.key === '/help') {
                items.push({ type: 'divider', key: 'divider-utility', style: { borderColor: 'rgba(255,255,255,0.12)', margin: '8px 16px' } });
            }
            items.push(item);
        });
        return items as MenuProps['items'];
    }, [navItems]);

    return (
        <Layout style={{ minHeight: '100vh' }}>
            <Layout.Sider
                theme="dark"
                collapsible
                collapsed={collapsed}
                onCollapse={(value, type) => {
                    setCollapsed(value);
                    if (type === 'responsive') setIsMobile(value);
                }}
                breakpoint="lg"
                collapsedWidth={isMobile ? 0 : 80}
                trigger={null}
                style={
                    isMobile
                        ? { position: 'fixed', top: 0, bottom: 0, left: 0, height: '100vh', overflow: 'auto', zIndex: 100 }
                        : { position: 'sticky', top: 0, height: '100vh', overflow: 'auto', zIndex: 10 }
                }
            >
                <div
                    style={{
                        height: 56,
                        margin: 12,
                        display: 'flex',
                        alignItems: 'center',
                        justifyContent: collapsed ? 'center' : 'flex-start',
                        gap: 8,
                        color: '#fff',
                        overflow: 'hidden',
                    }}
                >
                    <div
                        style={{
                            width: 32,
                            height: 32,
                            flexShrink: 0,
                            borderRadius: 8,
                            background: 'linear-gradient(135deg, #1677ff, #52c9ff)',
                            display: 'flex',
                            alignItems: 'center',
                            justifyContent: 'center',
                            fontWeight: 700,
                        }}
                    >
                        M
                    </div>
                    {!collapsed && (
                        <Typography.Text style={{ color: '#fff', fontWeight: 600, whiteSpace: 'nowrap' }}>
                            Manufacturing ERP
                        </Typography.Text>
                    )}
                </div>
                <Menu
                    theme="dark"
                    mode="inline"
                    selectedKeys={[location.pathname]}
                    openKeys={openKeys}
                    onOpenChange={(keys) => {
                        const nextOpenKey = keys.find((key) => !openKeys.includes(key));
                        setOpenKeys(nextOpenKey && rootSubmenuKeys.includes(nextOpenKey) ? [nextOpenKey] : keys);
                    }}
                    items={menuItems}
                    onClick={({ key }) => {
                        navigate(key);
                        if (isMobile) setCollapsed(true);
                    }}
                />
            </Layout.Sider>
            {isMobile && !collapsed && (
                <div
                    onClick={() => setCollapsed(true)}
                    style={{
                        position: 'fixed',
                        inset: 0,
                        background: 'rgba(0,0,0,0.45)',
                        zIndex: 90,
                    }}
                />
            )}
            <Layout>
                <Layout.Header
                    style={{
                        display: 'flex',
                        alignItems: 'center',
                        justifyContent: 'space-between',
                        padding: '0 16px',
                        background: '#fff',
                        borderBottom: '1px solid #f0f0f0',
                        position: 'sticky',
                        top: 0,
                        zIndex: 9,
                    }}
                >
                    {collapsed ? (
                        <MenuUnfoldOutlined style={{ fontSize: 18, cursor: 'pointer' }} onClick={() => setCollapsed(false)} />
                    ) : (
                        <MenuFoldOutlined style={{ fontSize: 18, cursor: 'pointer' }} onClick={() => setCollapsed(true)} />
                    )}
                    <Dropdown
                        menu={{
                            items: [
                                {
                                    key: 'logout',
                                    icon: <LogoutOutlined />,
                                    label: 'Sign out',
                                    onClick: () => mutation.mutate(),
                                },
                            ],
                        }}
                        trigger={['click']}
                    >
                        <Space style={{ cursor: 'pointer' }}>
                            <Avatar style={{ backgroundColor: '#1677ff' }}>
                                {user?.name?.charAt(0).toUpperCase() ?? '?'}
                            </Avatar>
                            <Typography.Text>{user?.name}</Typography.Text>
                        </Space>
                    </Dropdown>
                </Layout.Header>
                <Layout.Content className="app-content" style={{ padding: 24, minHeight: 0 }}>
                    <div style={{ maxWidth: 1400, margin: '0 auto' }}>{children}</div>
                </Layout.Content>
            </Layout>
        </Layout>
    );
}
