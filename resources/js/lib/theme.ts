import type { ThemeConfig } from 'antd';

/**
 * Machine-readable mirror of the palette in resources/css/app.css.
 * Ant Design needs literal colors, while Tailwind consumes the CSS custom
 * properties. Keep the two files in sync when a palette value changes.
 */
export const palette = {
    parchment50: '#fbf8f3',
    parchment100: '#f5efe5',
    parchment200: '#e9dfd0',
    parchment300: '#d8c6ad',
    surfaceWhite: '#ffffff',
    ink900: '#2b2520',
    ink700: '#4a4038',
    ink500: '#746a60',
    leather700: '#6f4e37',
    leather600: '#8b5e3c',
    leather100: '#f2e7dc',
    annotationAmber: '#fee685',
    successGreen: '#4f7a5b',
    warningGold: '#c28a2e',
    dangerRed: '#a94442',
    dangerSurface: '#fef2f2',
    dangerInk: '#b91c1c',
    placeholderInk: '#746a60',
    scrollbarThumb: '#cfc3b4',
} as const;

export const bodyFontFamily = "'Instrument Sans', ui-sans-serif, system-ui, sans-serif";

export const antdTheme: ThemeConfig = {
    token: {
        colorPrimary: palette.leather600,
        colorInfo: palette.leather600,
        colorSuccess: palette.successGreen,
        colorWarning: palette.warningGold,
        colorError: palette.dangerRed,
        colorTextPlaceholder: palette.ink500,
        borderRadius: 8,
        fontFamily: bodyFontFamily,
    },
    components: {
        Layout: {
            bodyBg: palette.parchment50,
            headerBg: palette.surfaceWhite,
            siderBg: palette.surfaceWhite,
        },
        Menu: {
            itemBg: 'transparent',
        },
    },
};
