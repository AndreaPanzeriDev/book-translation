import type { ReactNode } from 'react';

export interface ChapterTrack {
    key: string;
    label: string;
    translated: number;
    total: number;
    tone?: 'primary' | 'secondary';
}

interface ChapterRowProps {
    position: number;
    title: string;
    meta: ReactNode;
    active?: boolean;
    disabled?: boolean;
    tracks?: ChapterTrack[];
    onSelect: () => void;
}

export default function ChapterRow({
    position,
    title,
    meta,
    active = false,
    disabled = false,
    tracks = [],
    onSelect,
}: ChapterRowProps) {
    return (
        <div className={`rounded-md transition ${active ? 'bg-leather-100' : ''}`}>
            <button
                type="button"
                disabled={disabled}
                aria-current={active ? 'page' : undefined}
                onClick={onSelect}
                className={`flex min-h-11 w-full items-center gap-2 rounded-md px-2 py-1.5 text-left transition ${
                    active
                        ? 'text-leather-700'
                        : disabled
                          ? 'cursor-not-allowed text-ink-500'
                          : 'text-ink-700 hover:bg-parchment-100'
                }`}
            >
                <span
                    className={`flex h-6 w-6 shrink-0 items-center justify-center rounded-full text-[11px] tabular-nums ${
                        active
                            ? 'bg-leather-600 text-white'
                            : 'bg-parchment-200 text-ink-700'
                    }`}
                >
                    {position}
                </span>
                <span className="min-w-0 flex-1 truncate text-sm">{title}</span>
                <span className="shrink-0 text-[11px] tabular-nums">{meta}</span>
            </button>

            {tracks.length > 0 && (
                <div className="ml-8 mr-2 flex flex-col gap-0.5 pb-1.5">
                    {tracks.map((track) => {
                        const progress =
                            track.total > 0
                                ? Math.min(100, (track.translated / track.total) * 100)
                                : 0;

                        return (
                            <div
                                key={track.key}
                                role="progressbar"
                                aria-label={track.label}
                                aria-valuemin={0}
                                aria-valuemax={Math.max(track.total, 1)}
                                aria-valuenow={track.translated}
                                aria-valuetext={`${track.translated} / ${track.total}`}
                                className={`h-0.5 overflow-hidden rounded-full ${
                                    active ? 'bg-white/70' : 'bg-parchment-200'
                                }`}
                            >
                                <div
                                    className={`h-full rounded-full ${
                                        track.tone === 'secondary'
                                            ? 'bg-leather-700/40'
                                            : 'bg-leather-600/55'
                                    }`}
                                    style={{ width: `${progress}%` }}
                                />
                            </div>
                        );
                    })}
                </div>
            )}
        </div>
    );
}
