import '../css/app.css';

import { StyleProvider } from '@ant-design/cssinjs';
import { createInertiaApp } from '@inertiajs/react';
import type { ResolvedComponent } from '@inertiajs/react';
import { App as AntdApp, ConfigProvider } from 'antd';
import enUS from 'antd/locale/en_US';
import itIT from 'antd/locale/it_IT';
import axios from 'axios';
import { useEffect, type ReactNode } from 'react';
import { createRoot } from 'react-dom/client';
import { useTranslation } from 'react-i18next';

import TranslationBatchPanel from '@/components/TranslationBatchPanel';
import { TranslationBatchProvider } from '@/contexts/TranslationBatchProvider';
import { initI18n, normalizeLocale } from '@/i18n';

const csrfToken = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content;

if (csrfToken) {
    axios.defaults.headers.common['X-CSRF-TOKEN'] = csrfToken;
}

axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';
axios.defaults.withCredentials = true;

const pages = import.meta.glob<{ default: ResolvedComponent }>('./pages/**/*.tsx');

function RootProviders({ children }: { children: ReactNode }) {
    const { i18n } = useTranslation();
    const locale = normalizeLocale(i18n.resolvedLanguage ?? i18n.language);

    useEffect(() => {
        document.documentElement.lang = locale;
    }, [locale]);

    return (
        <StyleProvider layer>
            <ConfigProvider
                locale={locale === 'en' ? enUS : itIT}
                theme={{
                    token: {
                        colorPrimary: '#8b5e3c',
                        colorInfo: '#8b5e3c',
                        colorSuccess: '#4f7a5b',
                        colorWarning: '#c28a2e',
                        colorError: '#a94442',
                        borderRadius: 8,
                        fontFamily: "'Instrument Sans', ui-sans-serif, system-ui, sans-serif",
                    },
                    components: {
                        Layout: {
                            bodyBg: '#fbf8f3',
                            headerBg: '#fffdf9',
                            siderBg: '#fffdf9',
                        },
                        Menu: {
                            itemBg: 'transparent',
                        },
                    },
                }}
            >
                <AntdApp>
                    <TranslationBatchProvider>
                        {children}
                        <TranslationBatchPanel />
                    </TranslationBatchProvider>
                </AntdApp>
            </ConfigProvider>
        </StyleProvider>
    );
}

createInertiaApp({
    title: (title) => (title ? `${title} · Book Translation Studio` : 'Book Translation Studio'),
    resolve: async (name) => {
        const load = pages[`./pages/${name}.tsx`];

        if (!load) {
            throw new Error(`Pagina Inertia non trovata: ${name}`);
        }

        const module = await load();

        return module.default;
    },
    setup({ el, App, props }) {
        initI18n(props.initialPage.props.locale as string | undefined);

        createRoot(el).render(
            <RootProviders>
                <App {...props} />
            </RootProviders>,
        );
    },
    progress: {
        color: '#8b5e3c',
    },
});
