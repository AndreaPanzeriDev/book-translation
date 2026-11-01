export interface SentenceRange {
    text: string;
    start: number;
    end: number;
}

export interface TextSelection {
    start: number;
    end: number;
}

const FALLBACK_BOUNDARY = /[.!?…]+(?:["'”’»)\]]+)?\s+/g;

function fallbackSentences(text: string): SentenceRange[] {
    const sentences: SentenceRange[] = [];
    let start = 0;

    for (const match of text.matchAll(FALLBACK_BOUNDARY)) {
        const end = (match.index ?? 0) + match[0].length;

        if (text.slice(start, end).trim() !== '') {
            sentences.push({ text: text.slice(start, end), start, end });
        }

        start = end;
    }

    if (start < text.length && text.slice(start).trim() !== '') {
        sentences.push({ text: text.slice(start), start, end: text.length });
    }

    return sentences;
}

/**
 * Split a block into sentences while keeping the original character offsets.
 * Offsets are what let us map a browser selection back to sentence indexes.
 */
export function splitSentences(text: string, locale?: string): SentenceRange[] {
    if (text.trim() === '') {
        return [];
    }

    if (typeof Intl !== 'undefined' && typeof Intl.Segmenter === 'function') {
        const segmenter = new Intl.Segmenter(locale, { granularity: 'sentence' });

        return Array.from(segmenter.segment(text))
            .filter((segment) => segment.segment.trim() !== '')
            .map((segment) => ({
                text: segment.segment,
                start: segment.index,
                end: segment.index + segment.segment.length,
            }));
    }

    return fallbackSentences(text);
}

export function selectedSentenceIndices(
    sentences: SentenceRange[],
    selection: TextSelection,
): number[] {
    if (selection.end <= selection.start) {
        return [];
    }

    return sentences
        .map((sentence, index) => ({ sentence, index }))
        .filter(
            ({ sentence }) =>
                sentence.start < selection.end && sentence.end > selection.start,
        )
        .map(({ index }) => index);
}

function sentenceLength(sentence: SentenceRange): number {
    return sentence.text.replace(/\s+/g, '').length;
}

function groupLength(sentences: SentenceRange[], start: number, count: number): number {
    let total = 0;

    for (let index = start; index < start + count; index += 1) {
        total += sentenceLength(sentences[index]);
    }

    return total;
}

function numbersIn(sentences: SentenceRange[], start: number, count: number): Set<string> {
    const text = sentences
        .slice(start, start + count)
        .map((sentence) => sentence.text)
        .join(' ');

    return new Set(text.match(/\d+(?:[.,]\d+)*/g) ?? []);
}

function punctuationAt(sentences: SentenceRange[], index: number): string {
    return sentences[index].text.trim().slice(-1);
}

/**
 * Cheap length-based alignment used when a translation has a different number
 * of sentences than its source. It allows 1:1, 1:2 and 2:1 groups and skips.
 */
export function alignSentences(
    source: SentenceRange[],
    target: SentenceRange[],
): number[][] {
    const mapping: number[][] = source.map(() => []);

    if (source.length === 0 || target.length === 0) {
        return mapping;
    }

    // Translators almost always preserve one sentence for one sentence. Keeping
    // the identity mapping in this common case avoids unnecessary misalignment.
    if (source.length === target.length) {
        return source.map((_, index) => [index]);
    }

    const sourceTotal = groupLength(source, 0, source.length);
    const targetTotal = groupLength(target, 0, target.length);
    const ratio = sourceTotal > 0 ? targetTotal / sourceTotal : 1;
    const skipCost = 1.6;

    const groupCost = (
        sourceStart: number,
        targetStart: number,
        sourceCount: number,
        targetCount: number,
    ): number => {
        const sourceChars = groupLength(source, sourceStart, sourceCount);
        const targetChars = groupLength(target, targetStart, targetCount);
        const expected = sourceChars * ratio;
        const deviation = Math.abs(
            Math.log((targetChars + 1) / (expected + 1)),
        );

        let cost = Math.min(deviation, 1.2);

        if (sourceCount === 1 && targetCount === 1) {
            cost += 0;
        } else {
            cost += 0.12;
        }

        const sourceEnd = punctuationAt(source, sourceStart + sourceCount - 1);
        const targetEnd = punctuationAt(target, targetStart + targetCount - 1);
        const sourceQuestion = sourceEnd === '?' || sourceEnd === '!';
        const targetQuestion = targetEnd === '?' || targetEnd === '!';

        if (sourceQuestion && targetQuestion && sourceEnd === targetEnd) {
            cost -= 0.08;
        } else if (sourceQuestion !== targetQuestion) {
            cost += 0.12;
        }

        const sourceNumbers = numbersIn(source, sourceStart, sourceCount);
        const targetNumbers = numbersIn(target, targetStart, targetCount);
        let sharedNumbers = 0;

        for (const number of sourceNumbers) {
            if (targetNumbers.has(number)) {
                sharedNumbers += 1;
            }
        }

        cost -= Math.min(0.3, sharedNumbers * 0.15);

        return Math.max(0, cost);
    };

    const costs: number[][] = Array.from({ length: source.length + 1 }, () =>
        Array<number>(target.length + 1).fill(Number.POSITIVE_INFINITY),
    );
    const moves: Array<Array<{ sourceCount: number; targetCount: number } | null>> =
        Array.from({ length: source.length + 1 }, () =>
            Array<{ sourceCount: number; targetCount: number } | null>(
                target.length + 1,
            ).fill(null),
        );

    costs[0][0] = 0;

    const relax = (
        sourceFrom: number,
        targetFrom: number,
        sourceCount: number,
        targetCount: number,
        cost: number,
        move: { sourceCount: number; targetCount: number } | null,
    ) => {
        const nextSource = sourceFrom + sourceCount;
        const nextTarget = targetFrom + targetCount;

        if (cost < costs[nextSource][nextTarget]) {
            costs[nextSource][nextTarget] = cost;
            moves[nextSource][nextTarget] = move;
        }
    };

    for (let sourceIndex = 0; sourceIndex <= source.length; sourceIndex += 1) {
        for (let targetIndex = 0; targetIndex <= target.length; targetIndex += 1) {
            const current = costs[sourceIndex][targetIndex];

            if (!Number.isFinite(current)) {
                continue;
            }

            const remainingSource = source.length - sourceIndex;
            const remainingTarget = target.length - targetIndex;

            if (remainingSource >= 1 && remainingTarget >= 1) {
                const move = { sourceCount: 1, targetCount: 1 };
                relax(
                    sourceIndex,
                    targetIndex,
                    1,
                    1,
                    current + groupCost(sourceIndex, targetIndex, 1, 1),
                    move,
                );
            }

            if (remainingSource >= 1 && remainingTarget >= 2) {
                const move = { sourceCount: 1, targetCount: 2 };
                relax(
                    sourceIndex,
                    targetIndex,
                    1,
                    2,
                    current + groupCost(sourceIndex, targetIndex, 1, 2),
                    move,
                );
            }

            if (remainingSource >= 2 && remainingTarget >= 1) {
                const move = { sourceCount: 2, targetCount: 1 };
                relax(
                    sourceIndex,
                    targetIndex,
                    2,
                    1,
                    current + groupCost(sourceIndex, targetIndex, 2, 1),
                    move,
                );
            }

            if (remainingSource >= 1) {
                relax(sourceIndex, targetIndex, 1, 0, current + skipCost, {
                    sourceCount: 1,
                    targetCount: 0,
                });
            }

            if (remainingTarget >= 1) {
                relax(sourceIndex, targetIndex, 0, 1, current + skipCost, {
                    sourceCount: 0,
                    targetCount: 1,
                });
            }
        }
    }

    let sourceIndex = source.length;
    let targetIndex = target.length;

    while (sourceIndex > 0 || targetIndex > 0) {
        const move = moves[sourceIndex][targetIndex];

        if (!move) {
            break;
        }

        if (move.sourceCount > 0 && move.targetCount > 0) {
            for (
                let sourceCursor = sourceIndex - move.sourceCount;
                sourceCursor < sourceIndex;
                sourceCursor += 1
            ) {
                for (
                    let targetCursor = targetIndex - move.targetCount;
                    targetCursor < targetIndex;
                    targetCursor += 1
                ) {
                    mapping[sourceCursor].push(targetCursor);
                }
            }
        }

        sourceIndex -= move.sourceCount;
        targetIndex -= move.targetCount;
    }

    return mapping.map((indexes) => Array.from(new Set(indexes)).sort((a, b) => a - b));
}

/**
 * Map a text selection in a source block to the sentence indexes of its
 * translation. Selecting a whole block always highlights the whole translation.
 */
export function targetSentenceIndices(
    sourceText: string,
    targetText: string,
    selection: TextSelection,
    sourceLocale?: string,
    targetLocale?: string,
): number[] {
    const sourceSentences = splitSentences(sourceText, sourceLocale);
    const targetSentences = splitSentences(targetText, targetLocale);

    if (sourceSentences.length === 0 || targetSentences.length === 0) {
        return [];
    }

    const selected = selectedSentenceIndices(sourceSentences, selection);

    if (selected.length === 0) {
        return [];
    }

    const firstNonSpace = sourceText.search(/\S/);
    const lastNonSpace = sourceText.trimEnd().length;

    if (selection.start <= firstNonSpace && selection.end >= lastNonSpace) {
        return targetSentences.map((_, index) => index);
    }

    const mapping = alignSentences(sourceSentences, targetSentences);
    const highlighted = new Set<number>();

    for (const sourceIndex of selected) {
        const mapped = mapping[sourceIndex] ?? [];

        if (mapped.length > 0) {
            mapped.forEach((targetIndex) => highlighted.add(targetIndex));

            continue;
        }

        // If the aligner deliberately skipped this sentence, fall back to the
        // relative position so the user still gets useful feedback.
        const fallback = Math.min(
            targetSentences.length - 1,
            Math.floor(
                ((sourceIndex + 0.5) / sourceSentences.length) *
                    targetSentences.length,
            ),
        );

        highlighted.add(fallback);
    }

    return Array.from(highlighted).sort((a, b) => a - b);
}
