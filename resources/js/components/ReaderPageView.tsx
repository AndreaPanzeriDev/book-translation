import { useTranslation } from 'react-i18next';

import BlockRenderer from '@/components/BlockRenderer';
import type { ReaderPageContent } from '@/types';

interface ReaderPageViewProps {
    page: ReaderPageContent;
    locale?: string;
    chapterPosition?: number;
    spread?: boolean;
}

export default function ReaderPageView({
    page,
    locale,
    chapterPosition,
    spread = false,
}: ReaderPageViewProps) {
    const { t } = useTranslation();

    return (
        <article
            className={`reader-page ${spread ? 'reader-page--spread' : ''}`}
            lang={locale}
        >
            <header className="mb-5 flex items-center justify-between gap-3 border-b border-parchment-200/80 pb-2 text-[11px] uppercase tracking-[0.16em] text-ink-500">
                <span>{t('reader.pageLabel', { position: page.position })}</span>
                {chapterPosition !== undefined && (
                    <span>
                        {t('reader.chapterPage', {
                            chapter: chapterPosition,
                            page: page.chapter_position,
                        })}
                    </span>
                )}
            </header>

            <BlockRenderer
                blocks={page.blocks}
                locale={locale}
                placeholder={t('reader.emptyPage')}
                headingOffset={1}
            />
        </article>
    );
}
