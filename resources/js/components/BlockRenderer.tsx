import type { ReactNode } from 'react';

import { splitSentences } from '@/lib/alignment';
import type { Block } from '@/types';

type RenderableBlock = Pick<Block, 'id' | 'type' | 'text'>;

interface BlockRendererProps {
    blocks: RenderableBlock[];
    placeholder?: string;
    locale?: string;
    sentenceHighlights?: Record<number, number[]>;
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
                    className="bg-amber-200/80 text-inherit"
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
}: {
    block: RenderableBlock;
    locale?: string;
    sentenceIndexes?: number[];
}) {
    const content = renderText(block.text, sentenceIndexes, locale);

    switch (block.type) {
        case 'h1':
            return <h1 data-block-id={block.id}>{content}</h1>;
        case 'h2':
            return <h2 data-block-id={block.id}>{content}</h2>;
        case 'h3':
            return <h3 data-block-id={block.id}>{content}</h3>;
        case 'h4':
            return <h4 data-block-id={block.id}>{content}</h4>;
        case 'h5':
            return <h5 data-block-id={block.id}>{content}</h5>;
        case 'h6':
            return <h6 data-block-id={block.id}>{content}</h6>;
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
}: BlockRendererProps) {
    if (blocks.length === 0) {
        return <p className="block-placeholder">{placeholder ?? 'Nessun testo.'}</p>;
    }

    return (
        <div className="reading-pane">
            {blocks.map((block) => (
                <BlockElement
                    key={block.id}
                    block={block}
                    locale={locale}
                    sentenceIndexes={sentenceHighlights?.[block.id]}
                />
            ))}
        </div>
    );
}
