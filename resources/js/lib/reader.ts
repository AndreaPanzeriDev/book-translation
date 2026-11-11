import type {
    ReaderPageIndexItem,
    ReaderScope,
    ReaderVersion,
} from '@/types';

export function versionKey(version: ReaderVersion): string {
    return `${version.account_id}::${version.model}::${version.target_language}`;
}

/**
 * Pair adjacent pages inside the same chapter. Every chapter starts on the
 * left page of a new spread.
 */
export function buildSpreads(pages: ReaderPageIndexItem[]): ReaderPageIndexItem[][] {
    const spreads: ReaderPageIndexItem[][] = [];
    let current: ReaderPageIndexItem[] = [];
    let chapterId: number | null = null;

    for (const page of pages) {
        if (chapterId !== null && page.chapter_id !== chapterId) {
            if (current.length > 0) {
                spreads.push(current);
            }

            current = [page];
            chapterId = page.chapter_id;

            continue;
        }

        chapterId = page.chapter_id;

        if (current.length < 2) {
            current.push(page);

            continue;
        }

        spreads.push(current);
        current = [page];
    }

    if (current.length > 0) {
        spreads.push(current);
    }

    return spreads;
}

export function scopePages(
    pages: ReaderPageIndexItem[],
    currentPage: ReaderPageIndexItem | null,
    scope: ReaderScope,
): ReaderPageIndexItem[] {
    if (scope === 'page') {
        return currentPage ? [currentPage] : [];
    }

    if (scope === 'chapter') {
        if (!currentPage) {
            return [];
        }

        return pages.filter((page) => page.chapter_id === currentPage.chapter_id);
    }

    return pages;
}

/**
 * Keep the same page after a version switch when possible, otherwise move to
 * the nearest page in the same chapter.
 */
export function nearestPageId(
    pages: ReaderPageIndexItem[],
    previous: ReaderPageIndexItem | null,
): number | null {
    if (!previous) {
        return pages[0]?.id ?? null;
    }

    if (pages.some((page) => page.id === previous.id)) {
        return previous.id;
    }

    const sameChapter = pages.filter((page) => page.chapter_id === previous.chapter_id);

    if (sameChapter.length > 0) {
        return sameChapter.reduce((nearest, page) => {
            const distance = Math.abs(page.position - previous.position);
            const nearestDistance = Math.abs(nearest.position - previous.position);

            return distance < nearestDistance ? page : nearest;
        }).id;
    }

    return pages[0]?.id ?? null;
}
