import type { ThemeConfig } from 'antd';

/**
 * The one place the app's colours and type live (03-Sep-2026 visual refresh,
 * design note: docs/superpowers/specs/2026-09-03-visual-refresh-design.md).
 *
 * Every value is brand-derived: the navy and the copper-orange are the
 * SwaashPET mark's own two colours; the page ground is the cool cast of clear
 * PET; the teal is the tint at a bottle's base. `index.css` mirrors the same
 * values as CSS custom properties for the few rules antd tokens cannot reach.
 */
export const brand = {
    navy: '#12256B',
    navyDeep: '#0A145B',
    navyHover: '#1A3390',
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

/** Archivo is bundled (`@fontsource-variable/archivo`), so no request leaves the factory network for a font. */
export const FONT_FAMILY =
    "'Archivo Variable', 'Archivo', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif";

export const appTheme: ThemeConfig = {
    token: {
        fontFamily: FONT_FAMILY,
        fontSize: 14,
        fontWeightStrong: 700,
        colorPrimary: brand.navy,
        colorPrimaryHover: brand.navyHover,
        colorLink: brand.navy,
        colorLinkHover: brand.orange,
        colorInfo: brand.teal,
        colorSuccess: brand.success,
        colorWarning: brand.warning,
        colorError: brand.danger,
        colorBgLayout: brand.glass,
        colorBgContainer: '#ffffff',
        colorTextHeading: brand.navyDeep,
        colorText: brand.ink,
        colorTextSecondary: brand.textSecondary,
        colorBorder: brand.rule,
        colorBorderSecondary: brand.ruleSoft,
        borderRadius: 6,
        borderRadiusLG: 10,
        borderRadiusSM: 4,
        controlHeight: 38,
        controlOutline: 'rgba(240, 124, 26, 0.25)',
        boxShadow: '0 1px 2px rgba(10, 20, 91, 0.06), 0 2px 8px rgba(10, 20, 91, 0.04)',
        boxShadowSecondary: '0 6px 20px rgba(10, 20, 91, 0.10)',
        // A Tag with no colour is solid slate, not the near-black antd would pick.
        colorBgSolid: brand.textSecondary,
    },
    components: {
        Layout: {
            siderBg: brand.glass,
            headerBg: '#ffffff',
            bodyBg: brand.glass,
        },
        Menu: {
            itemBg: 'transparent',
            subMenuItemBg: 'transparent',
            itemColor: brand.ink,
            itemHoverBg: 'rgba(18, 37, 107, 0.06)',
            itemHoverColor: brand.navy,
            itemSelectedBg: '#ffffff',
            itemSelectedColor: brand.navy,
            itemBorderRadius: 8,
            subMenuItemBorderRadius: 8,
            itemHeight: 40,
            iconSize: 16,
            groupTitleColor: brand.textSecondary,
        },
        Button: {
            borderRadius: 8,
            fontWeight: 600,
            controlHeight: 38,
            primaryShadow: 'none',
            defaultShadow: 'none',
        },
        Card: {
            borderRadiusLG: 12,
            boxShadowTertiary: '0 1px 2px rgba(10, 20, 91, 0.05)',
        },
        Table: {
            borderRadius: 10,
            headerBorderRadius: 10,
            headerBg: brand.navy,
            headerColor: '#ffffff',
            headerSortActiveBg: brand.navyDeep,
            headerSortHoverBg: brand.navyHover,
            headerFilterHoverBg: brand.navyHover,
            headerSplitColor: 'rgba(255, 255, 255, 0.18)',
            bodySortBg: '#FAFBFE',
            rowHoverBg: brand.orangeSoft,
            rowSelectedBg: '#E9EEFB',
            rowSelectedHoverBg: '#DFE6F8',
            borderColor: brand.ruleSoft,
            cellPaddingBlock: 12,
            cellPaddingInline: 14,
        },
        Input: {
            borderRadius: 8,
            controlHeight: 38,
            activeBorderColor: brand.navy,
            hoverBorderColor: brand.navy,
        },
        Select: {
            borderRadius: 8,
            controlHeight: 38,
            optionSelectedBg: '#E9EEFB',
        },
        DatePicker: {
            borderRadius: 8,
            controlHeight: 38,
        },
        Tabs: {
            titleFontSize: 14,
            lineType: 'solid',
            inkBarColor: brand.orange,
            itemSelectedColor: brand.navy,
            itemHoverColor: brand.navy,
            horizontalItemPadding: '10px 0',
        },
        Tag: {
            borderRadiusSM: 999,
            fontSizeSM: 12,
        },
        Breadcrumb: {
            itemColor: brand.textSecondary,
            lastItemColor: brand.navyDeep,
            linkColor: brand.textSecondary,
        },
        Pagination: {
            itemActiveBg: '#ffffff',
        },
        Statistic: {
            contentFontSize: 28,
        },
        Segmented: {
            itemSelectedBg: brand.navy,
            itemSelectedColor: '#ffffff',
        },
    },
};
