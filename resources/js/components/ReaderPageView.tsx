import { useTranslation } from 'react-i18next';

import BlockRenderer from '@/components/BlockRenderer';
import type { ReaderPageContent } from '@/types';

interface ReaderPageViewProps {
    page: ReaderPageContent;
    locale?: string;
    spread?: boolean;
}

export default function ReaderPageView({ page, locale, spread = false }: ReaderPageViewProps) {
    const { t } = useTranslation();

    return (
        <article className={`reader-page ${spread ? 'reader-page--spread' : ''}`}>
            <header className="mb-5 flex items-center justify-between gap-3 border-b border-parchment-200/80 pb-2 text-[10px] uppercase tracking-[0.16em] text-ink-500">
                <span>{t('reader.pageLabel', { position: page.position })}</span>
                <span>
                    {t('reader.chapterTag', { position: page.chapter_position })}
                </span>
            </header>

            <BlockRenderer
                blocks={page.blocks}
                locale={locale}
                placeholder={t('reader.emptyPage')}
            />
        </article>
    );
}
