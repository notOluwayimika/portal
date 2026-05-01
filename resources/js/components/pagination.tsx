import { ChevronDown, ChevronLeft, ChevronRight } from 'lucide-react';
import { useState } from 'react';

const LIMITS = [5, 10, 25, 50, 100];

function visiblePages(current = 1, last = 1, delta = 2) {
    const pages = new Set([1, last]);

    for (
        let i = Math.max(2, current - delta);
        i <= Math.min(last - 1, current + delta);
        i++
    ) {
        pages.add(i);
    }

    const sorted = [...pages].sort((a, b) => a - b);
    const result: (number | string)[] = [];
    sorted.forEach((p, i) => {
        if (i > 0 && p - sorted[i - 1] > 1) {
            result.push('...');
        }

        result.push(p);
    });

    return result;
}

export function Pagination({
    meta,
    setPage,
    setLimit,
}: {
    meta: any;
    setPage: (page: number) => void;
    setLimit: (limit: number) => void;
}) {
    const [limitOpen, setLimitOpen] = useState(false);
    const pages = visiblePages(meta.current_page, meta.last_page);
    const from = (meta.current_page - 1) * meta.per_page + 1;
    const to = Math.min(meta.current_page * meta.per_page, meta.total);

    return (
        <div className="m-4 flex flex-col items-center gap-3">
            {/* Info strip */}
            <p className="text-sm text-muted-foreground">
                Showing{' '}
                <span className="font-medium text-foreground">
                    {from}–{to}
                </span>{' '}
                of{' '}
                <span className="font-medium text-foreground">
                    {meta.total}
                </span>{' '}
                results
            </p>

            <div className="flex items-center gap-1">
                {/* Prev */}
                <button
                    disabled={!meta.prev_page_url}
                    onClick={() => setPage(meta.current_page - 1)}
                    className="inline-flex h-9 items-center gap-1.5 rounded-md border border-border bg-background px-3 text-sm text-muted-foreground transition-colors hover:bg-muted hover:text-foreground disabled:cursor-not-allowed disabled:opacity-35"
                >
                    <ChevronLeft className="h-3.5 w-3.5" />
                    Prev
                </button>

                {/* Page numbers */}
                {pages.map((p, i) =>
                    p === '...' ? (
                        <span
                            key={i}
                            className="px-1 text-sm text-muted-foreground"
                        >
                            …
                        </span>
                    ) : (
                        <button
                            key={p}
                            onClick={() => setPage(p as number)}
                            disabled={p === meta.current_page}
                            className={`inline-flex h-9 w-9 items-center justify-center rounded-md border text-sm transition-colors ${
                                p === meta.current_page
                                    ? 'cursor-default border-foreground bg-foreground font-medium text-background'
                                    : 'border-border bg-background text-muted-foreground hover:bg-muted hover:text-foreground'
                            }`}
                        >
                            {p}
                        </button>
                    ),
                )}

                {/* Separator */}
                <div className="mx-1 h-5 w-px bg-border" />

                {/* Limit dropdown */}
                <div className="relative">
                    <button
                        onClick={() => setLimitOpen(!limitOpen)}
                        className={`inline-flex h-9 items-center gap-1.5 rounded-md border px-3 text-sm transition-colors ${
                            limitOpen
                                ? 'border-border bg-muted text-foreground'
                                : 'border-border bg-background text-muted-foreground hover:bg-muted hover:text-foreground'
                        }`}
                    >
                        {meta.per_page}/page
                        <ChevronDown
                            className={`h-3.5 w-3.5 transition-transform ${limitOpen ? 'rotate-180' : ''}`}
                        />
                    </button>

                    {limitOpen && (
                        <>
                            {/* Backdrop */}
                            <div
                                className="fixed inset-0 z-10"
                                onClick={() => setLimitOpen(false)}
                            />
                            <div className="absolute bottom-[calc(100%+6px)] left-1/2 z-20 min-w-[140px] -translate-x-1/2 overflow-hidden rounded-xl border border-border bg-background shadow-md">
                                {LIMITS.map((l) => (
                                    <button
                                        key={l}
                                        onClick={() => {
                                            setLimit(l);
                                            setLimitOpen(false);
                                        }}
                                        className={`flex w-full items-center justify-between px-3.5 py-2 text-sm transition-colors hover:bg-muted ${l === meta.per_page ? 'font-medium text-foreground' : 'text-muted-foreground'}`}
                                    >
                                        <span>{l} / page</span>
                                        <span className="text-xs text-muted-foreground">
                                            {Math.ceil(meta.total / l)} pages
                                        </span>
                                    </button>
                                ))}
                            </div>
                        </>
                    )}
                </div>

                {/* Separator */}
                <div className="mx-1 h-5 w-px bg-border" />

                {/* Next */}
                <button
                    disabled={!meta.next_page_url}
                    onClick={() => setPage(meta.current_page + 1)}
                    className="inline-flex h-9 items-center gap-1.5 rounded-md border border-border bg-background px-3 text-sm text-muted-foreground transition-colors hover:bg-muted hover:text-foreground disabled:cursor-not-allowed disabled:opacity-35"
                >
                    Next
                    <ChevronRight className="h-3.5 w-3.5" />
                </button>
            </div>
        </div>
    );
}
