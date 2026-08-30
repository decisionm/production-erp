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
    /**
     * A PERMISSION gate without an ADOPTION gate: the entry belongs to an
     * adopted group but is visible only to holders of this module's
     * permission. Supplier Bills is the case that needed it — procurement
     * work done by Accounts (FC-06: every figure is a purchase rate, so the
     * API gates on module:finance), while the Finance MODULE itself stays
     * unadopted (DEC-20260812-001) and must not become visible through a
     * child entry.
     */
    permissionModule?: string;
}

interface NavGroup {
    key: string;
    icon: ReactNode;
    label: string;
    module?: string;
    children?: NavLeaf[];
}

/**
 * The sidebar. The opening run is a REQUESTED order — the owner named these
 * modules one by one and they are not something to re-derive or "tidy":
 *
 *   Dashboard, Procurement, Inventory, Production, Sales, Quality,
 *   Compliance, HRMS, Payroll — then "etc." — then Tally Sync last of the
 *   modules.
 *
 * The "etc." was not spelled out. CRM, Finance and Maintenance are what falls
 * in it, and they keep the relative order they already had in this file. That
 * is the whole rule: the specified prefix is fixed, Tally Sync is last, and
 * nothing unspecified was resequenced on an agent's judgement.
 *
 * TALLY SYNC WAS PROPOSED FOR A MOVE on 26-Aug-2026 — directly after Payroll
 * — by the Phase 3 build spec, and it HAS NOT MOVED. The position it would
 * have left is a 21-Aug owner request, so the move is a REVERSAL of a
 * recorded pin, and a build spec is not owner authority (AGENTS.md: an agent
 * proposes, the owner decides; a changed decision is a NEW record). The
 * question is parked in docs/factory/PENDING-OWNER-QUESTIONS.md — "Where
 * should Tally Sync sit in the sidebar?", deliberately named rather than
 * numbered, because that file assigns question numbers at MERGE time and a
 * number written here would quietly point at somebody else's question after
 * a re-mint — and the 21-Aug order stands until it is answered. Nothing
 * here is to be
 * resequenced on the strength of the spec alone — if the owner confirms the
 * new position, move this entry to directly after Payroll and move
 * 'Tally Sync' in AppLayout.nav.test.ts's CONFIGURED_ORDER with it.
 *
 * Downloads, Help and Administration stay after Tally Sync, below the
 * divider `menuItems` inserts: they are utilities, not modules.
 *
 * This list is the FULL table — hidden modules included. It is exported, and
 * `readonly`, for exactly one reason: AppLayout.nav.test.ts reads it. Nothing
 * writes to it. What a given login actually SEES is `buildNavItems` below,
 * which filters by ADOPTED_MODULES and then by permission.
 *
 * Both orders are pinned separately, because they can disagree: a module
 * rejoining the adoption list must land back in its right place, and only the
 * configured pin can see that while the module is still hidden.
 */
export const allNavItems: readonly NavGroup[] = [
    { key: '/', icon: <DashboardOutlined />, label: 'Dashboard' },
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
            // Accounts only (permission gate, not adoption — see NavLeaf).
            { key: '/procurement/supplier-bills', label: 'Supplier Bills', permissionModule: 'finance' },
        ],
    },
    {
        key: 'inventory',
        icon: <InboxOutlined />,
        label: 'Inventory',
        module: 'inventory',
        children: [
            // DAILY-USE FIRST, masters after — the same floor-first rule the
            // Production group already follows. The item master is where a
            // product's identity is fixed, so it opens the group; Stock and
            // the label bench are what a storekeeper touches every day;
            // Warehouses is setup and sits below them.
            { key: '/inventory/items', label: 'Item Master' },
            { key: '/inventory/stock', label: 'Stock' },
            // TWO LABEL REGISTERS, ONE ENTRY. They are still not the same
            // screen — per BAG (find the barcode in your hand, reprint it) and
            // per RECEIPT (which GRN a lot arrived on, what is left of it) —
            // and neither was dropped: they are the two tabs of Barcode &
            // Labels now. /inventory/material-lots stays mounted, so every
            // existing link and bookmark still opens the register directly.
            { key: '/inventory/barcode-labels', label: 'Barcode & Labels' },
            // The STORE's queue (Phase 7.5): what production has asked for,
            // fulfilled — in part or in full — here. Issuing is not
            // consuming: it moves stock into Production/WIP.
            { key: '/inventory/store-issue-queue', label: 'Store Issue Queue' },
            { key: '/inventory/warehouses', label: 'Warehouses' },
            // The ledger, first-class since 27-Aug-2026. It was NOT in this
            // menu before, and the reason recorded here was true at the time:
            // /inventory/stock-movements was an API path with no page behind
            // it, so the entry would have been a dead link. There is a page
            // now (App.tsx mounts it), and AppLayout.nav.test.ts pins that this
            // entry and that route agree.
            { key: '/inventory/stock-movements', label: 'Stock Movements' },
            // SALES ORDER FULFILMENT, the store's half: which customer lines
            // are waiting on stock, and when the floor could have what is
            // short. These MUST stay in this group, and the reason is the
            // permission model rather than taste: buildNavItems gates a whole
            // group on its parent's module, so under Sales a storekeeper
            // holding inventory permissions alone would lose both entries
            // while /inventory/fulfilment and /inventory/planning still mount
            // and their API still gates on module:inventory — the screens
            // would exist, be permitted, and be unreachable from the menu.
            // They were moved to Sales on 27-Aug for a six-entry group and
            // moved straight back when review found that (Cursor, ac56e12).
            // Neither screen moves stock and neither gates dispatch (Q27).
            { key: '/inventory/fulfilment', label: 'Store Fulfilment' },
            { key: '/inventory/planning', label: 'Fulfilment Planning' },
            // Batches and Serial Numbers are NOT children here any more, and
            // they are not withdrawn either: both routes stay mounted and both
            // pages still work. They are per-item identity registers opened
            // while working a stock line, not destinations of their own — the
            // Stock page's toolbar links to both, which is where someone
            // needing a batch or a serial number already is. Do not re-add
            // them here without moving those links out of that toolbar first.
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
            //
            // THE WORKLIST OPENS THE GROUP. It moved above Shift Floor on
            // 27-Aug-2026: it is what the floor is asked to make, so it is the
            // question a shift starts with, and the Shift Floor is where the
            // answer gets entered. Gated on `production` like the rest of this
            // group, which is the honest gate for a MENU entry — the API behind
            // it is OR-gated so a storekeeper reaching the URL still reads the
            // queue, without the floor's controls.
            { key: '/production/queue', label: 'Production Queue' },
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
            // Product Standards, Machines & Capabilities, Molds, Packing
            // Materials, Downtime Reasons, Scrap Reasons, Shifts, Factory
            // Rules and Import from Workbook. Every retired URL still answers
            // as a redirect (App.tsx), in two flavours: /production/standards,
            // /production/scrap-reasons, /production/molds and
            // /production/shifts go through the shared query-preserving
            // redirect, so an incoming search string survives the hop;
            // /production/work-centers stays the plain <Navigate> to the
            // machines tab, which carries no query string.
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
            //
            // Scrap Reasons, Molds and Shifts are gone from here too, but for
            // the opposite reason: they are not withdrawn, they MOVED. All
            // three are masters the Shift Floor selects from — a scrap reason
            // on a rejection line, a mould on a mould change, a shift on
            // every entry made — so they belong beside the other masters in
            // Production Configuration rather than as three more lines
            // between a supervisor and the pages they open daily. They are
            // tabs of it now (?tab=scrap, ?tab=molds, ?tab=shifts), the
            // components and endpoints are unchanged, and the old URLs
            // redirect.
            //
            // Shifts went last, on 23-Aug-2026: it was the only master left
            // in this menu after the others moved, and its own screen is the
            // same shape as theirs — a named list with Active/Archive/Delete
            // through the shared configuration cell. Nothing about it
            // justified a separate line once Molds and Scrap Reasons had one
            // destination. The API path `production/shifts` is untouched by
            // any of this; only the UI route moved.
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
    // LAST OF THE MODULES, per the 21-Aug owner request. The 26-Aug build
    // spec asked for it directly after Payroll; that is a reversal of an
    // owner pin, so it is parked as an owner question and NOT applied —
    // see this file's header.
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

export function buildNavItems(user: User | null) {
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

            // Whether this user reaches the group through its OWN module.
            // A group they do not reach that way can still surface — with
            // ONLY those children — when a child carries a permissionModule
            // the user holds (Codex on 073a8c2: a finance-only Accounts
            // login is accepted by every supplier-bill route yet had no
            // sidebar path to the page, because Procurement was rejected
            // before its children were looked at). Adoption stays the hard
            // gate above; this only widens the PERMISSION half.
            const reachesGroup = !item.module || hasModuleAccess(user, item.module);

            if (item.children) {
                const children = item.children.filter((child) => {
                    if (child.permissionModule) {
                        // Its own permission is the whole gate — deliberately
                        // NOT conditioned on reachesGroup.
                        return hasModuleAccess(user, child.permissionModule);
                    }
                    if (!reachesGroup) return false;
                    return !child.module || (ADOPTED_MODULES.has(child.module) && hasModuleAccess(user, child.module));
                });
                if (children.length === 0) return null;
                return { ...item, children };
            }

            if (!reachesGroup) return null;
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
