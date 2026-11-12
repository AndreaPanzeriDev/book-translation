import {
    ArrowRightOutlined,
    DeleteOutlined,
    InboxOutlined,
    ReadOutlined,
    RocketOutlined,
} from '@ant-design/icons';
import { Head, Link, router } from '@inertiajs/react';
import { Alert, App, Button, Drawer, Popconfirm, Tag, Upload } from 'antd';
import type { UploadFile } from 'antd';
import { useState } from 'react';
import { Trans, useTranslation } from 'react-i18next';

import AppLayout from '@/layouts/AppLayout';
import { languageLabel } from '@/lib/languages';
import type { BookSummary } from '@/types';

const { Dragger } = Upload;

interface Props {
    books: BookSummary[];
    accountsCount: number;
}

export default function Library({ books, accountsCount }: Props) {
    const { t, i18n } = useTranslation();
    const { message } = App.useApp();
    const [file, setFile] = useState<File | null>(null);
    const [fileList, setFileList] = useState<UploadFile[]>([]);
    const [uploading, setUploading] = useState(false);
    const [progress, setProgress] = useState(0);
    const [importOpen, setImportOpen] = useState(false);

    const totalPages = books.reduce((total, book) => total + book.pages_count, 0);
    const numberFormat = new Intl.NumberFormat(i18n.language);

    const importBook = () => {
        if (!file) {
            return;
        }

        setUploading(true);
        setProgress(0);

        router.post(
            '/books',
            { file },
            {
                forceFormData: true,
                onProgress: (event) => setProgress(Math.round(event?.percentage ?? 0)),
                onSuccess: () => {
                    setFile(null);
                    setFileList([]);
                    setProgress(0);
                },
                onError: (errors) => {
                    void message.error(
                        Object.values(errors)[0] ?? t('library.importFailed'),
                    );
                },
                onFinish: () => setUploading(false),
            },
        );
    };

    const importHeader = (
        <div className="mb-3 flex items-start justify-between gap-3">
            <div>
                <h2 className="text-base font-semibold text-ink-900">
                    {t('library.importTitle')}
                </h2>
                <p className="text-xs text-ink-500">
                    {t('library.importDescription')}
                </p>
            </div>
            <ReadOutlined className="mt-0.5 text-xl text-leather-600" />
        </div>
    );

    const importForm = (
        <>
            <Dragger
                accept=".epub,application/epub+zip"
                maxCount={1}
                fileList={fileList}
                disabled={uploading}
                beforeUpload={(selectedFile) => {
                    setFile(selectedFile);
                    setFileList([
                        {
                            uid: selectedFile.uid,
                            name: selectedFile.name,
                            size: selectedFile.size,
                            status: 'done',
                        },
                    ]);
                    return false;
                }}
                onRemove={() => {
                    setFile(null);
                    setFileList([]);
                }}
                className="bg-parchment-50! [&_.ant-upload-drag]:max-h-[220px]"
            >
                <p className="ant-upload-drag-icon">
                    <InboxOutlined className="text-leather-600!" />
                </p>
                <p className="ant-upload-text">{t('library.importDrag')}</p>
                <p className="ant-upload-hint text-ink-500!">{t('library.importHint')}</p>
            </Dragger>

            {uploading && (
                <div className="mt-3">
                    <div className="mb-1 flex justify-between text-xs text-ink-500">
                        <span>{t('library.importing')}</span>
                        <span>{progress}%</span>
                    </div>
                    <div className="h-1.5 overflow-hidden rounded-full bg-parchment-200">
                        <div
                            className="h-full rounded-full bg-leather-600 transition-all"
                            style={{ width: `${progress}%` }}
                        />
                    </div>
                </div>
            )}

            <Button
                type="primary"
                size="large"
                block
                className="mt-3"
                icon={<RocketOutlined />}
                disabled={!file}
                loading={uploading}
                onClick={importBook}
            >
                {t('library.importSubmit')}
            </Button>

            {accountsCount === 0 && (
                <Alert
                    className="mt-3"
                    type="warning"
                    showIcon
                    title={t('library.noAccountsTitle')}
                    description={
                        <Trans
                            i18nKey="library.noAccountsDescription"
                            components={{ link: <Link href="/accounts" /> }}
                        />
                    }
                />
            )}
        </>
    );

    if (books.length === 0) {
        return (
            <AppLayout>
                <Head title={t('library.documentTitle')} />
                <section className="mb-6 overflow-hidden rounded-2xl border border-parchment-200 bg-white p-6 md:p-8">
                    <div className="grid gap-8 lg:grid-cols-[1.25fr_1fr] lg:items-center">
                        <div>
                            <span className="mb-3 inline-flex items-center rounded-full border border-parchment-200 bg-parchment-100 px-3 py-1 text-[11px] font-semibold uppercase tracking-[0.14em] text-ink-700">
                                {t('library.heroBadge')}
                            </span>
                            <h1 className="max-w-2xl font-reading text-3xl leading-tight text-ink-900 md:text-4xl">
                                {t('library.heroTitle')}
                            </h1>
                            <p className="mt-3 max-w-2xl text-sm leading-relaxed text-ink-700 md:text-base">
                                {t('library.heroDescription')}
                            </p>
                        </div>

                        <div className="rounded-xl border border-parchment-200 bg-parchment-50 p-4">
                            {importHeader}
                            {importForm}
                        </div>
                    </div>
                </section>
            </AppLayout>
        );
    }

    const booksSummary = t('library.summaryBooks', {
        count: books.length,
        formatted: numberFormat.format(books.length),
    });
    const pagesSummary = t('library.summaryPages', {
        count: totalPages,
        formatted: numberFormat.format(totalPages),
    });
    const accountsSummary = t('library.summaryAccounts', {
        count: accountsCount,
        formatted: numberFormat.format(accountsCount),
    });

    return (
        <AppLayout>
            <Head title={t('library.documentTitle')} />
            <section className="mb-5 flex flex-wrap items-end justify-between gap-3">
                <div>
                    <h1 className="font-reading text-2xl leading-tight text-ink-900 md:text-3xl">
                        {t('library.title')}
                    </h1>
                    <p className="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-sm tabular-nums text-ink-500">
                        <span>{booksSummary}</span>
                        <span aria-hidden="true" className="text-parchment-300">
                            ·
                        </span>
                        <span>{pagesSummary}</span>
                        <span aria-hidden="true" className="text-parchment-300">
                            ·
                        </span>
                        <Link
                            href="/accounts"
                            className="text-leather-700 no-underline hover:underline"
                        >
                            {accountsSummary}
                        </Link>
                    </p>
                </div>

                <Button
                    type="primary"
                    icon={<InboxOutlined />}
                    onClick={() => setImportOpen(true)}
                >
                    {t('library.importTitle')}
                </Button>
            </section>

            <section>
                <div className="grid gap-5 sm:grid-cols-2 lg:grid-cols-3 2xl:grid-cols-4">
                    {books.map((book) => (
                        <article
                            key={book.id}
                            className="group relative flex flex-col overflow-hidden rounded-xl border border-parchment-200 bg-white shadow-sm transition duration-200 hover:-translate-y-0.5 hover:shadow-md focus-within:ring-2 focus-within:ring-leather-600/40 focus-within:ring-offset-2 focus-within:ring-offset-parchment-50"
                        >
                            <Link
                                href={`/books/${book.id}`}
                                className="flex flex-1 flex-col no-underline focus-visible:outline-none"
                            >
                                <div className="relative h-52 overflow-hidden bg-gradient-to-br from-parchment-100 to-parchment-200">
                                    {book.cover_url ? (
                                        <img
                                            src={book.cover_url}
                                            alt={t('library.coverAlt', { title: book.title })}
                                            className="h-full w-full object-cover"
                                            loading="lazy"
                                        />
                                    ) : (
                                        <div className="flex h-full flex-col items-center justify-center gap-3 text-leather-600">
                                            <ReadOutlined className="text-4xl opacity-60" />
                                            <span className="max-w-[80%] text-center font-reading text-lg leading-snug text-ink-700">
                                                {book.title}
                                            </span>
                                        </div>
                                    )}
                                    <Tag className="absolute left-3 top-3" color="default">
                                        {languageLabel(book.language)}
                                    </Tag>
                                </div>

                                <div className="flex flex-1 flex-col p-4">
                                    <h2 className="line-clamp-2 font-reading text-lg leading-snug text-ink-900 transition-colors group-hover:text-leather-700">
                                        {book.title}
                                    </h2>
                                    <p className="mt-0.5 line-clamp-1 text-sm text-ink-500">
                                        {book.author ?? t('common.authorUnknown')}
                                    </p>

                                    <div className="mt-3 flex flex-wrap gap-1.5">
                                        <Tag>
                                            {t('common.chapters', { count: book.chapters_count })}
                                        </Tag>
                                        <Tag>{t('common.pages', { count: book.pages_count })}</Tag>
                                        <Tag className="border-warning-gold/40! bg-warning-gold/10! text-ink-900!">
                                            → {languageLabel(book.target_language)}
                                        </Tag>
                                    </div>

                                    <span className="mt-auto flex items-center gap-1.5 pt-4 text-sm font-medium text-leather-700">
                                        {t('library.open')}
                                        <ArrowRightOutlined className="text-xs transition-transform group-hover:translate-x-0.5" />
                                    </span>
                                </div>
                            </Link>

                            <Popconfirm
                                title={t('library.deleteTitle')}
                                description={t('library.deleteDescription')}
                                okText={t('common.delete')}
                                cancelText={t('common.cancel')}
                                okButtonProps={{ danger: true }}
                                onConfirm={() =>
                                    router.delete(`/books/${book.id}`, { preserveScroll: true })
                                }
                            >
                                <Button
                                    danger
                                    type="text"
                                    icon={<DeleteOutlined />}
                                    aria-label={t('library.deleteAria')}
                                    className="absolute right-3 top-3 z-10 h-11! w-11! bg-white/90 shadow-sm backdrop-blur-sm"
                                />
                            </Popconfirm>
                        </article>
                    ))}
                </div>
            </section>

            <Drawer
                title={t('library.importTitle')}
                open={importOpen}
                onClose={() => setImportOpen(false)}
                size={440}
                styles={{ body: { paddingTop: 16 } }}
            >
                <p className="mb-3 text-xs text-ink-500">
                    {t('library.importDescription')}
                </p>
                {importForm}
            </Drawer>
        </AppLayout>
    );
}
