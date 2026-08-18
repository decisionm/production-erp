import {
    AccountBookOutlined,
    BuildOutlined,
    ContactsOutlined,
    DashboardOutlined,
    DownloadOutlined,
    FileProtectOutlined,
    InboxOutlined,
    KeyOutlined,
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
// The factory's adoption list — the "which modules does this factory use"
// decision, shared with the dashboard's Office band. Full rationale lives
// on the constant itself.
import { ADOPTED_MODULES } from '@/lib/adoptedModules';

const SIDER_WIDTH = 200;
const SIDER_COLLAPSED_WIDTH = 80;

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
            { key: '/inventory/material-lots', label: 'Material Receipts & Bag Labels' },
            { key: '/inventory/batches', label: 'Batches' },
            { key: '/inventory/serial-numbers', label: 'Serial Numbers' },
            // The STORE's queue (Phase 7.5): what production has asked for,
            // fulfilled — in part or in full — here. Issuing is not
            // consuming: it moves stock into Production/WIP.
            { key: '/inventory/store-issue-queue', label: 'Store Issue Queue' },
        ],
    },
    {
        key: 'production',
        icon: <ToolOutlined />,
        label: 'Production',
        module: 'production',
        children: [
            // Floor-first ordering: the daily-use pages a supervisor actually
            // touches come first, setup/reference pages after.
            { key: '/production/shift-production', label: 'Shift Floor' },
            // NO Day Bin entry. DEC-20260817-001 settles the inventory
            // locations as RM Store -> Production/WIP -> FG Store, and a
            // first-class nav entry presented the Day Bin as a place the
            // factory still keeps stock in. This nav item is what the owner
            // was still seeing on 18-Aug, on EVERY route (it renders in the
            // layout, so `/production/configuration?tab=products` showed it
            // too).
            //
            // The ROUTE is deliberately left mounted, reachable by URL. The
            // page is not dead: it still hosts the only writer of the day-bin
            // warehouse setting, and the bag scan it performs is currently the
            // sole inflow that prices resin (FactoryDayBinService::loadBag is
            // the only caller of ResinPoolService::fold). Hiding a mechanism
            // that still runs is worse than naming it, so the entry goes and
            // the machinery stays until its successor exists. The floor is
            // unaffected: it scans from the Shift Floor page, not from here.
            // The floor's half of the Phase 7.5 material flow: raise a
            // request, then watch what the store has actually issued. The
            // store's half is under Inventory, because the two halves have
            // two different readers and this group is gated on production.
            { key: '/production/material-requests', label: 'Material Requests' },
            // Bin Bay Loading is GONE, not just unlinked (DEC-20260807-006):
            // the floor's only load flow is the Shift Floor's central Load
            // Material scan into the common resin input.
            { key: '/production/approve-production', label: 'Approve Production' },
            { key: '/production/live-monitor', label: 'Live Monitor' },
            // The INTERNAL carton trace (DEC-20260810-001): its own module
            // key, so this entry appears only for logins holding
            // carton-trace.view — Owner (Administrator), Plant Manager,
            // Accounts. A supervisor's menu never shows it; the server 403s
            // the URL for them regardless.
            { key: '/production/carton-trace', label: 'Carton Trace', module: 'carton-trace' },
            // ONE configuration destination, not two. Product Standards and
            // Machine Setup were separate menu entries, and nothing in either
            // name told a supervisor which one owned the setting they were
            // looking for — so this entry now opens a workspace whose tabs are
            // Product Standards, Machines & Capabilities, Downtime Reasons,
            // Factory Rules and Import from Workbook. Both retired URLs still
            // answer as redirects (App.tsx): /production/standards keeps its
            // query string, /production/work-centers lands on the machines tab.
            { key: '/production/configuration', label: 'Production Configuration' },
            { key: '/production/boms', label: 'Bills of Material' },
            { key: '/production/shift-summary', label: 'Shift Summary' },
            { key: '/production/reports', label: 'Reports' },
            // Routings, Work Orders, MRP, Capacity Planning, Subcontract
            // Orders, and Rework Orders are deliberately NOT linked here.
            // WorkOrderService completes work orders by calling
            // recordIssue/recordReceipt directly (real stock movements)
            // completely outside the Supervisor -> PM -> Accountant -> Tally
            // approval chain, and MRP/Capacity render empty/wrong with no
            // BOMs or routings behind them. The routes still exist (App.tsx)
            // for a deliberate direct URL visit; do not re-add these nav
            // entries without first wiring the approval chain into
            // WorkOrderService.
            { key: '/production/scrap-reasons', label: 'Scrap Reasons' },
            { key: '/production/molds', label: 'Molds' },
            { key: '/production/shifts', label: 'Shifts' },
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
            // First in the group on purpose: it is the only one of these worked
            // every single shift, and the factory opens it each morning.
            { key: '/quality/production-qc', label: 'Production QC' },
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
    {
        key: 'tally-sync',
        icon: <SyncOutlined />,
        label: 'Tally Sync',
        module: 'tally-sync',
        children: [
            { key: '/tally-sync', label: 'Sync Queue' },
            { key: '/tally-sync/agent-tokens', label: 'Agent Tokens' },
            { key: '/tally-sync/settings', label: 'Settings' },
        ],
    },
    // The Download / Export Center — a utility, not a module, so it sits
    // with Help below the divider. Deliberately NO `module` gate: every
    // login may open it, and the server's catalogue decides which kinds
    // (if any) that login is offered. Gating the entry by one module here
    // would hide the Tally Sync downloads from an accountant who holds no
    // production permission, or the reverse.
    { key: '/exports', icon: <DownloadOutlined />, label: 'Downloads' },
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
            // Not adopted by this factory yet — hidden whatever the user's
            // permissions say. Checked BEFORE permissions, because an
            // Administrator holds every permission and would otherwise see every
            // module regardless of whether the factory uses it.
            if (item.module && !ADOPTED_MODULES.has(item.module)) return null;
            if (item.module && !hasModuleAccess(user, item.module)) return null;
            if (item.children) {
                const children = item.children.filter(
                    (child) => !child.module || (ADOPTED_MODULES.has(child.module) && hasModuleAccess(user, child.module)),
                );
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
            if (item.key === '/exports') {
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
                width={SIDER_WIDTH}
                collapsedWidth={isMobile ? 0 : SIDER_COLLAPSED_WIDTH}
                trigger={null}
                style={{
                    position: 'fixed',
                    insetInlineStart: 0,
                    top: 0,
                    bottom: 0,
                    height: '100vh',
                    overflow: 'auto',
                    zIndex: isMobile ? 100 : 10,
                }}
            >
                {/* Bottom margin is deliberately tighter than the sides: with the
                    attribution line gone there is nothing under the plaque, so the
                    menu starts directly beneath it instead of below a gap that no
                    longer holds anything. */}
                <div
                    style={{
                        margin: '12px 12px 8px',
                        display: 'flex',
                        flexDirection: 'column',
                        // Expanded, the plaque stretches to the sider width so the
                        // logo has the whole width to fill. Collapsed, it stays
                        // centred and shrinks to the mark — stretching there would
                        // just wrap the small mark in new empty white.
                        alignItems: collapsed ? 'center' : 'stretch',
                        overflow: 'hidden',
                    }}
                >
                    {/* The logo is navy-on-white artwork, so it sits on a white
                        plaque rather than directly on the dark sider — keeping the
                        factory's own colours instead of inventing a reversed
                        variant. Collapsed, only the chevron mark fits.

                        Expanded, the artwork is width-driven: it takes the full
                        plaque minus its padding so it fills the sider rather than
                        floating in the middle of it. width:100% + height:auto +
                        object-fit:contain cannot stretch it — the 335x144 source
                        proportions are preserved by construction, and max-height
                        is only a ceiling, never a second dimension to fight the
                        width. Collapsed keeps the mark at its intrinsic ratio and
                        centred. */}
                    <div
                        style={{
                            background: '#fff',
                            borderRadius: 8,
                            padding: collapsed ? '6px 8px' : '8px 12px',
                            display: 'flex',
                            alignItems: 'center',
                            justifyContent: 'center',
                            flexShrink: 0,
                            lineHeight: 0,
                        }}
                    >
                        <img
                            src={`${import.meta.env.BASE_URL}${collapsed ? 'swaashpet-mark.png' : 'swaashpet-logo.png'}`}
                            alt="SWAASHPET POLYMERS"
                            style={
                                collapsed
                                    ? { height: 32, width: 'auto', objectFit: 'contain', display: 'block' }
                                    : { width: '100%', height: 'auto', maxHeight: 72, objectFit: 'contain', display: 'block' }
                            }
                        />
                    </div>
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
            <Layout
                style={{
                    marginInlineStart: isMobile ? 0 : collapsed ? SIDER_COLLAPSED_WIDTH : SIDER_WIDTH,
                    transition: 'margin-inline-start 0.2s',
                }}
            >
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
                    {/* On a phone the sider is off-canvas at width 0, so the logo
                        above is unreachable until the drawer is opened. This is
                        the same mark shown there, never both at once. */}
                    {isMobile && (
                        <img
                            src={`${import.meta.env.BASE_URL}swaashpet-logo.png`}
                            alt="SWAASHPET POLYMERS"
                            style={{ height: 26, width: 'auto', display: 'block' }}
                        />
                    )}
                    <Dropdown
                        menu={{
                            items: [
                                {
                                    key: 'change-password',
                                    icon: <KeyOutlined />,
                                    label: 'Change password',
                                    onClick: () => navigate('/account/change-password'),
                                },
                                { type: 'divider' },
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
