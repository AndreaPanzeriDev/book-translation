import { useCallback, useEffect, useLayoutEffect, useRef } from 'react';

import ReaderPageView from '@/components/ReaderPageView';
import type { ReaderPageContent } from '@/types';

interface ReaderCanvasProps {
    pages: ReaderPageContent[];
    columns: 1 | 2;
    screenIndex: number;
    locale?: string;
    chapterPositions: Record<number, number>;
    onScreenCountChange: (count: number) => void;
}

function columnGap(element: HTMLElement): number {
    const gap = Number.parseFloat(window.getComputedStyle(element).columnGap);

    return Number.isFinite(gap) ? gap : 0;
}

function screenStride(element: HTMLElement): number {
    return element.clientWidth + columnGap(element);
}

export default function ReaderCanvas({
    pages,
    columns,
    screenIndex,
    locale,
    chapterPositions,
    onScreenCountChange,
}: ReaderCanvasProps) {
    const flowRef = useRef<HTMLDivElement>(null);
    const screenIndexRef = useRef(screenIndex);
    const screenCountRef = useRef(0);

    screenIndexRef.current = screenIndex;

    const scrollToScreen = useCallback((index: number) => {
        const element = flowRef.current;

        if (!element) {
            return;
        }

        const count = Math.max(0, screenCountRef.current - 1);
        const target = Math.min(Math.max(0, index), count);

        element.scrollLeft = target * screenStride(element);
    }, []);

    const measure = useCallback(() => {
        const element = flowRef.current;

        if (!element || element.clientWidth === 0) {
            return;
        }

        const gap = columnGap(element);
        const stride = element.clientWidth + gap;
        const count = Math.max(1, Math.round((element.scrollWidth + gap) / stride));

        screenCountRef.current = count;
        onScreenCountChange(count);
        scrollToScreen(screenIndexRef.current);
    }, [onScreenCountChange, scrollToScreen]);

    useLayoutEffect(() => {
        measure();

        const element = flowRef.current;

        if (!element) {
            return;
        }

        const observer = new ResizeObserver(() => measure());
        observer.observe(element);

        return () => observer.disconnect();
    }, [columns, measure, pages]);

    useLayoutEffect(() => {
        scrollToScreen(screenIndex);
    }, [columns, pages, screenIndex, scrollToScreen]);

    useEffect(() => {
        let active = true;

        document.fonts?.ready.then(() => {
            if (active) {
                measure();
            }
        });

        return () => {
            active = false;
        };
    }, [measure]);

    return (
        <div
            ref={flowRef}
            className={`reader-flow ${
                columns === 2 ? 'reader-flow--spread' : 'reader-flow--single'
            }`}
        >
            {pages.map((page) => (
                <div key={page.id} className="reader-flow-page">
                    <ReaderPageView
                        page={page}
                        locale={locale}
                        chapterPosition={chapterPositions[page.chapter_id]}
                        spread={columns === 2}
                    />
                </div>
            ))}
        </div>
    );
}
