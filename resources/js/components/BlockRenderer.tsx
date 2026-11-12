import type { ElementType, ReactNode } from 'react';
import { useTranslation } from 'react-i18next';

import { splitSentences } from '@/lib/alignment';
import type { Block } from '@/types';

type RenderableBlock = Pick<Block, 'id' | 'type' | 'text'>;

interface BlockRendererProps {
    blocks: RenderableBlock[];
    placeholder?: string;
    locale?: string;
    sentenceHighlights?: Record<number, number[]>;
    /**
     * Demote content headings so they nest under the page's own h1 instead of
     * competing with it in the document outline.
     */
    headingOffset?: number;
}

function renderText(
    text: string,
    sentenceIndexes: number[] | undefined,
    locale: string | undefined,
): ReactNode {
    if (!sentenceIndexes || sentenceIndexes.length === 0) {
        return text;
    }

    const sentences = splitSentences(text, locale);

    if (sentences.length === 0) {
        return text;
    }

    const highlighted = new Set(sentenceIndexes);
    const parts: ReactNode[] = [];
    let cursor = 0;

    sentences.forEach((sentence, index) => {
        if (sentence.start > cursor) {
            parts.push(text.slice(cursor, sentence.start));
        }

        if (highlighted.has(index)) {
            parts.push(
                <mark
                    key={`sentence-${sentence.start}`}
                    className="bg-annotation-amber/80 text-inherit"
                >
                    {sentence.text}
                </mark>,
            );
        } else {
            parts.push(sentence.text);
        }

        cursor = sentence.end;
    });

    if (cursor < text.length) {
        parts.push(text.slice(cursor));
    }

    return parts;
}

function BlockElement({
    block,
    locale,
    sentenceIndexes,
    headingOffset,
}: {
    block: RenderableBlock;
    locale?: string;
    sentenceIndexes?: number[];
    headingOffset: number;
}) {
    const content = renderText(block.text, sentenceIndexes, locale);

    if (/^h[1-6]$/.test(block.type)) {
        const level = Math.min(6, Number(block.type[1]) + headingOffset);
        const Heading = `h${level}` as ElementType;

        return <Heading data-block-id={block.id}>{content}</Heading>;
    }

    switch (block.type) {
        case 'blockquote':
            return <blockquote data-block-id={block.id}>{content}</blockquote>;
        case 'pre':
            return <pre data-block-id={block.id}>{content}</pre>;
        case 'li':
            return (
                <div data-block-id={block.id} className="reading-list-item">
                    {content}
                </div>
            );
        default:
            return <p data-block-id={block.id}>{content}</p>;
    }
}

export default function BlockRenderer({
    blocks,
    placeholder,
    locale,
    sentenceHighlights,
    headingOffset = 0,
}: BlockRendererProps) {
    const { t } = useTranslation();

    if (blocks.length === 0) {
        return <p className="block-placeholder">{placeholder ?? t('block.empty')}</p>;
    }

    return (
        <div className="reading-pane">
            {blocks.map((block) => (
                <BlockElement
                    key={block.id}
                    block={block}
                    locale={locale}
                    sentenceIndexes={sentenceHighlights?.[block.id]}
                    headingOffset={headingOffset}
                />
            ))}
        </div>
    );
}
