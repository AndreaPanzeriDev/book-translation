import type { ReactNode } from 'react';

import { BookOutlined, CloudServerOutlined, SafetyCertificateOutlined } from '@ant-design/icons';
import { Link, usePage } from '@inertiajs/react';
import { Tag } from 'antd';
import { useTranslation } from 'react-i18next';

import LanguageSwitcher from '@/components/LanguageSwitcher';
import { useFlash } from '@/lib/useFlash';

export default function AppLayout({ children }: { children: ReactNode }) {
    useFlash();

    const { t } = useTranslation();
    const { url } = usePage();
    const activeHref = url.startsWith('/accounts') ? '/accounts' : '/';

    const navItems = [
        { href: '/', label: t('nav.library'), icon: <BookOutlined /> },
        { href: '/accounts', label: t('nav.accounts'), icon: <CloudServerOutlined /> },
    ];

    return (
        <div className="flex min-h-screen flex-col">
            <header className="sticky top-0 z-30 border-b border-parchment-200/80 bg-white/85 backdrop-blur-md">
                <div className="mx-auto flex w-full max-w-[1500px] items-center justify-between gap-4 px-4 py-3 md:px-6">
                    <Link href="/" className="group flex min-w-0 items-center gap-3 no-underline">
                        <span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-leather-700 text-lg text-white shadow-sm">
                            <BookOutlined />
                        </span>
                        <span className="min-w-0">
                            <span className="block truncate font-semibold leading-tight text-ink-900">
                                Book Translation Studio
                            </span>
                            <span className="hidden text-xs text-ink-500 sm:block">
                                {t('app.tagline')}
                            </span>
                        </span>
                    </Link>

                    <nav className="flex items-center gap-1 rounded-lg bg-parchment-100/80 p-1">
                        {navItems.map((item) => {
                            const active = activeHref === item.href;

                            return (
                                <Link
                                    key={item.href}
                                    href={item.href}
                                    aria-label={item.label}
                                    aria-current={active ? 'page' : undefined}
                                    className={`flex min-h-11 items-center gap-2 rounded-md px-3 py-2 text-sm font-medium no-underline transition ${
                                        active
                                            ? 'bg-white text-leather-700 shadow-sm'
                                            : 'text-ink-500 hover:text-ink-900'
                                    }`}
                                >
                                    <span aria-hidden="true">{item.icon}</span>
                                    <span className="hidden sm:inline">{item.label}</span>
                                </Link>
                            );
                        })}
                    </nav>

                    <div className="flex shrink-0 items-center gap-2">
                        <LanguageSwitcher />
                        <Tag
                            icon={<SafetyCertificateOutlined />}
                            color="default"
                            className="hidden! lg:inline-flex!"
                        >
                            {t('app.localOnly')}
                        </Tag>
                    </div>
                </div>
            </header>

            <main className="mx-auto w-full max-w-[1500px] flex-1 px-4 py-6 md:px-6 md:py-8">{children}</main>

            <footer className="mx-auto flex w-full max-w-[1500px] flex-wrap items-center justify-between gap-2 px-4 pb-8 pt-2 text-xs text-ink-500 md:px-6">
                <span>{t('app.footerLocal')}</span>
                <span>{t('app.footerKeys')}</span>
            </footer>
        </div>
    );
}
