import { type ThemeConfig, theme as antdTheme } from 'antd';
import type { ThemeMode } from './mode';

/**
 * The one place the app's colours and type live (03-Sep-2026 visual refresh,
 * design note: docs/superpowers/specs/2026-09-03-visual-refresh-design.md).
 *
 * Every value is brand-derived: the navy and the copper-orange are the
 * SwaashPET mark's own two colours; the page ground is the cool cast of clear
 * PET; the teal is the tint at a bottle's base. `index.css` mirrors the same
 * values as CSS custom properties for the few rules antd tokens cannot reach.
 *
 * The sidebar is DARK NAVY IN BOTH MODES: it is the app's one constant, the
 * rail the logo and the orange chevron sit on, and a floor tablet finds it
 * in daylight faster than a pale one.
 */
export const brand = {
    navy: '#12256B',
    navyDeep: '#0A145B',
    navyHover: '#1A3390',
    /** The sidebar's own ground — a step off navyDeep so the logo tile still separates. */
    sider: '#14224F',
    siderDark: '#0C1330',
    ink: '#141B33',
    orange: '#F07C1A',
    orangeSoft: '#FFF4EA',
    glass: '#F2F6FB',
    teal: '#0E9AA7',
    rule: '#D9E0EC',
    ruleSoft: '#EAEFF6',
    textSecondary: '#5B6579',
    success: '#1B8A4C',
    warning: '#D98E04',
    danger: '#C8321F',
} as const;

/**
 * The dark palette. Text first: #E9EDF7 on #0F1526 is a contrast ratio well
 * past 12:1, and the secondary tone past 6:1 — the point of a dark mode is
 * that the words are MORE readable, not less, so nothing here is a dimmed
 * grey on a grey. The accents are the same two brand colours, lifted enough
 * to stay themselves against a dark ground.
 */
export const dark = {
    /** Page ground and the containers that sit on it. */
    bg: '#0F1526',
    bgContainer: '#171F33',
    bgElevated: '#1D2740',
    text: '#E9EDF7',
    textSecondary: '#AFB9D0',
    heading: '#FFFFFF',
    border: '#2A3450',
    borderSoft: '#212B42',
    /** The navy reads as black once the page is dark, so the primary lifts. */
    primary: '#5B7BD6',
    primaryHover: '#7691E0',
    orange: '#FF9440',
    teal: '#2BB7C4',
    success: '#35B06B',
    warning: '#E8A72B',
    danger: '#E8604B',
    tableHeader: '#1B2547',
    rowHover: '#232E4C',
} as const;

/** Archivo is bundled (`@fontsource-variable/archivo`), so no request leaves the factory network for a font. */
export const FONT_FAMILY =
    "'Archivo Variable', 'Archivo', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif";

/** The sidebar's palette for a mode — the menu is dark-on-navy either way. */
function siderTokens(mode: ThemeMode) {
    const bg = mode === 'dark' ? brand.siderDark : brand.sider;

    return {
        siderBg: bg,
        menu: {
            darkItemBg: 'transparent',
            darkSubMenuItemBg: 'transparent',
            darkPopupBg: bg,
            darkItemColor: 'rgba(233, 237, 247, 0.82)',
            darkItemHoverBg: 'rgba(255, 255, 255, 0.08)',
            darkItemHoverColor: '#ffffff',
            darkItemSelectedBg: 'rgba(240, 124, 26, 0.16)',
            darkItemSelectedColor: '#ffffff',
            darkSubMenuItemSelectedColor: '#ffffff',
            darkGroupTitleColor: 'rgba(233, 237, 247, 0.55)',
            itemBorderRadius: 8,
            subMenuItemBorderRadius: 8,
            itemHeight: 40,
            iconSize: 16,
        },
    };
}

export function appTheme(mode: ThemeMode = 'light'): ThemeConfig {
    const isDark = mode === 'dark';
    const sider = siderTokens(mode);

    return {
        algorithm: isDark ? antdTheme.darkAlgorithm : antdTheme.defaultAlgorithm,
        token: {
            fontFamily: FONT_FAMILY,
            fontSize: 14,
            fontWeightStrong: 700,
            colorPrimary: isDark ? dark.primary : brand.navy,
            colorPrimaryHover: isDark ? dark.primaryHover : brand.navyHover,
            colorLink: isDark ? dark.primary : brand.navy,
            colorLinkHover: isDark ? dark.orange : brand.orange,
            colorInfo: isDark ? dark.teal : brand.teal,
            colorSuccess: isDark ? dark.success : brand.success,
            colorWarning: isDark ? dark.warning : brand.warning,
            colorError: isDark ? dark.danger : brand.danger,
            colorBgLayout: isDark ? dark.bg : brand.glass,
            colorBgContainer: isDark ? dark.bgContainer : '#ffffff',
            colorBgElevated: isDark ? dark.bgElevated : '#ffffff',
            colorTextHeading: isDark ? dark.heading : brand.navyDeep,
            colorText: isDark ? dark.text : brand.ink,
            colorTextSecondary: isDark ? dark.textSecondary : brand.textSecondary,
            colorTextDescription: isDark ? dark.textSecondary : brand.textSecondary,
            colorBorder: isDark ? dark.border : brand.rule,
            colorBorderSecondary: isDark ? dark.borderSoft : brand.ruleSoft,
            borderRadius: 6,
            borderRadiusLG: 10,
            borderRadiusSM: 4,
            controlHeight: 38,
            controlOutline: 'rgba(240, 124, 26, 0.25)',
            boxShadow: isDark
                ? '0 1px 2px rgba(0, 0, 0, 0.45), 0 2px 8px rgba(0, 0, 0, 0.35)'
                : '0 1px 2px rgba(10, 20, 91, 0.06), 0 2px 8px rgba(10, 20, 91, 0.04)',
            boxShadowSecondary: isDark ? '0 6px 20px rgba(0, 0, 0, 0.5)' : '0 6px 20px rgba(10, 20, 91, 0.10)',
            // A Tag with no colour is solid slate, not the near-black antd would pick.
            colorBgSolid: isDark ? '#46527A' : brand.textSecondary,
        },
        components: {
            Layout: {
                siderBg: sider.siderBg,
                triggerBg: sider.siderBg,
                headerBg: isDark ? dark.bgContainer : '#ffffff',
                bodyBg: isDark ? dark.bg : brand.glass,
                /*
                 * PINNED, and load-bearing. antd derives the header's height
                 * from `controlHeight` (x2), so raising controls to 38 pushed
                 * this to 76 — while every list in the app freezes its table
                 * header at TABLE_STICKY's 64. The 12px difference is a band
                 * where rows scroll through the app bar. tokens.test.ts pins
                 * these two numbers to each other.
                 */
                headerHeight: 64,
            },
            Menu: sider.menu,
            Button: {
                borderRadius: 8,
                fontWeight: 600,
                controlHeight: 38,
                primaryShadow: 'none',
                defaultShadow: 'none',
            },
            Card: {
                borderRadiusLG: 12,
                boxShadowTertiary: isDark ? '0 1px 2px rgba(0, 0, 0, 0.4)' : '0 1px 2px rgba(10, 20, 91, 0.05)',
            },
            Table: {
                borderRadius: 10,
                headerBorderRadius: 10,
                headerBg: isDark ? dark.tableHeader : brand.navy,
                headerColor: '#ffffff',
                headerSortActiveBg: isDark ? '#22305C' : brand.navyDeep,
                headerSortHoverBg: isDark ? '#26365F' : brand.navyHover,
                headerFilterHoverBg: isDark ? '#26365F' : brand.navyHover,
                headerSplitColor: 'rgba(255, 255, 255, 0.18)',
                bodySortBg: isDark ? '#1B2338' : '#FAFBFE',
                rowHoverBg: isDark ? dark.rowHover : brand.orangeSoft,
                rowSelectedBg: isDark ? '#243154' : '#E9EEFB',
                rowSelectedHoverBg: isDark ? '#2A3860' : '#DFE6F8',
                borderColor: isDark ? dark.borderSoft : brand.ruleSoft,
                cellPaddingBlock: 12,
                cellPaddingInline: 14,
            },
            Input: {
                borderRadius: 8,
                controlHeight: 38,
                activeBorderColor: isDark ? dark.primary : brand.navy,
                hoverBorderColor: isDark ? dark.primary : brand.navy,
            },
            Select: {
                borderRadius: 8,
                controlHeight: 38,
                optionSelectedBg: isDark ? '#243154' : '#E9EEFB',
            },
            DatePicker: {
                borderRadius: 8,
                controlHeight: 38,
            },
            Tabs: {
                titleFontSize: 14,
                lineType: 'solid',
                inkBarColor: brand.orange,
                itemSelectedColor: isDark ? dark.text : brand.navy,
                itemHoverColor: isDark ? dark.text : brand.navy,
                horizontalItemPadding: '10px 0',
            },
            Tag: {
                borderRadiusSM: 999,
                fontSizeSM: 12,
            },
            Breadcrumb: {
                itemColor: isDark ? dark.textSecondary : brand.textSecondary,
                lastItemColor: isDark ? dark.heading : brand.navyDeep,
                linkColor: isDark ? dark.textSecondary : brand.textSecondary,
            },
            Pagination: {
                itemActiveBg: isDark ? dark.bgElevated : '#ffffff',
            },
            Statistic: {
                contentFontSize: 28,
            },
            Segmented: {
                itemSelectedBg: isDark ? dark.primary : brand.navy,
                itemSelectedColor: '#ffffff',
            },
        },
    };
}
