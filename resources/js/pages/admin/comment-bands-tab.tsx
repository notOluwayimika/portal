// ═══════════════════════════════════════════════════════════════════════════
// SCORE COMMENTS TAB
//
// A school's comment ladder: the score ranges teachers get suggestions for, and
// the comments inside each one. Replaces seven arrays that used to be hardcoded
// in score-entry-page.tsx.
//
// These ranges are deliberately SEPARATE from Grade Boundaries. Comments are
// finer than grades at the top (one grade A often spans "Outstanding",
// "Excellent" and "Very good") and coarser at the bottom, so forcing them to
// share ranges would collapse suggestion sets a teacher relies on. The coverage
// strip draws the grade boundaries underneath so a school can SEE how the two
// ladders line up without being forced into one.
// ═══════════════════════════════════════════════════════════════════════════

import axios from 'axios';
import { Plus, Trash2 } from 'lucide-react';
import { useCallback, useEffect, useMemo, useState } from 'react';
import { toast } from 'react-toastify';
import { Empty } from '@/components/setup/setup-ui';
import type { CommentBand, ExamType, GradeBoundary } from '@/types/models';
import { CommentListEditor } from './comment-list-editor';
import { RatingCommentsPanel } from './rating-comments-panel';

/** The top of the scale — CommentBand::MAX_SCORE. */
const MAX_SCORE = 100;

/** A band being edited locally. `id` is absent until the ladder has been saved. */
interface DraftBand {
    id?: string;
    min_score: number;
    label: string;
}

const BAND_COLORS = [
    '#15803d',
    '#4d7c0f',
    '#1d4ed8',
    '#0e7490',
    '#b45309',
    '#c2410c',
    '#b91c1c',
];

type Mode = 'numeric' | 'categorical';

export function CommentBandsTab() {
    // The two grading modes band on different things — a score range vs a rating — so they are
    // configured separately here rather than pretending one ladder serves both.
    const [mode, setMode] = useState<Mode>('numeric');
    const [examTypes, setExamTypes] = useState<ExamType[]>([]);
    // null = the school's default ladder, used by any exam type without one of its own.
    const [examType, setExamType] = useState<string | null>(null);
    // Loaded bands are stored WITH the exam type they belong to. That pairing is what makes
    // "loading" and "which ladder am I looking at" derived rather than separate state — switching
    // exam types would otherwise need a synchronous setState inside an effect to clear the old
    // ladder, which briefly shows one exam type's bands under another's heading.
    const [loaded, setLoaded] = useState<{
        examType: string | null;
        bands: CommentBand[];
    } | null>(null);
    const [drafts, setDrafts] = useState<DraftBand[] | null>(null);
    const [boundaries, setBoundaries] = useState<{
        examType: string;
        rows: GradeBoundary[];
    } | null>(null);
    const [savingBands, setSavingBands] = useState(false);

    const loading = loaded === null || loaded.examType !== examType;
    const bands = loading ? [] : loaded.bands;

    useEffect(() => {
        axios
            .get('/api/exam-types')
            .then((r) => setExamTypes(r.data.data ?? []))
            .catch(() => toast.error('Failed to load exam types'));
    }, []);

    // Bumped by anything that changes comments server-side, to re-trigger the fetch below. The
    // fetch is defined INSIDE the effect (the shape the other setup tabs use) so no state setter
    // is reachable synchronously from the effect body.
    const [reloadToken, setReloadToken] = useState(0);
    const reloadBands = useCallback(() => setReloadToken((t) => t + 1), []);

    useEffect(() => {
        const fetchBands = async () => {
            try {
                const response = await axios.get('/api/comment-bands', {
                    params: examType ? { exam_type: examType } : {},
                });
                setLoaded({ examType, bands: response.data.data ?? [] });
                setDrafts(null);
            } catch {
                toast.error('Failed to load comment bands');
            }
        };

        fetchBands();
    }, [examType, reloadToken]);

    // The grade ladder, for the comparison strip only. There is no endpoint for the school-wide
    // default set, so the overlay simply does not render for it — better than implying alignment
    // we cannot show. Tagged with its exam type for the same reason as the bands above.
    useEffect(() => {
        if (!examType) {
            return;
        }

        axios
            .get(`/api/grade-boundaries/${examType}`)
            .then((r) => setBoundaries({ examType, rows: r.data.data ?? [] }))
            .catch(() => setBoundaries({ examType, rows: [] }));
    }, [examType]);

    // ─── Bands ──────────────────────────────────────────────────────────────

    const editing = drafts !== null;

    const startEditing = () =>
        setDrafts(
            bands.map((b) => ({
                id: b.id,
                min_score: b.min_score,
                label: b.label,
            })),
        );

    const updateDraft = (index: number, patch: Partial<DraftBand>) =>
        setDrafts((current) =>
            current
                ? current.map((b, i) => (i === index ? { ...b, ...patch } : b))
                : current,
        );

    const addDraft = () =>
        setDrafts((current) => [
            ...(current ?? []),
            { min_score: 0, label: '' },
        ]);

    const removeDraft = (index: number) =>
        setDrafts((current) =>
            current ? current.filter((_, i) => i !== index) : current,
        );

    // Mirrors SaveCommentBandsRequest so the admin is told before the round-trip, not after.
    // Gaps and overlaps are absent from this list on purpose: with only a minimum per band they
    // cannot be expressed at all.
    const draftError = useMemo((): string | null => {
        if (!drafts) {
            return null;
        }

        if (drafts.length === 0) {
            return 'Add at least one band.';
        }

        if (drafts.some((b) => !b.label.trim())) {
            return 'Every band needs a label.';
        }

        if (!drafts.some((b) => Number(b.min_score) === 0)) {
            return 'The lowest band must start at 0, otherwise low scores get no comments.';
        }

        const minima = drafts.map((b) => Number(b.min_score));

        if (new Set(minima).size !== minima.length) {
            return 'Two bands cannot start at the same score.';
        }

        if (minima.some((m) => m < 0 || m > MAX_SCORE)) {
            return `Band minimums must be between 0 and ${MAX_SCORE}.`;
        }

        return null;
    }, [drafts]);

    const saveBands = async () => {
        if (!drafts || draftError) {
            return;
        }

        setSavingBands(true);

        try {
            const response = await axios.put('/api/comment-bands', {
                exam_type_id: examType,
                bands: drafts.map((b) => ({
                    id: b.id ?? null,
                    min_score: Number(b.min_score),
                    label: b.label.trim(),
                })),
            });
            setLoaded({ examType, bands: response.data.data ?? [] });
            setDrafts(null);
            toast.success('Bands saved');
        } catch (error: any) {
            toast.error(
                error?.response?.data?.message ?? 'Failed to save bands',
            );
        } finally {
            setSavingBands(false);
        }
    };

    const loadDefaults = async () => {
        try {
            const response = await axios.post(
                '/api/comment-bands/load-defaults',
                {
                    exam_type_id: examType,
                },
            );
            setLoaded({ examType, bands: response.data.data ?? [] });
            toast.success('Starter comments loaded — edit them freely');
        } catch (error: any) {
            toast.error(
                error?.response?.data?.message ?? 'Failed to load defaults',
            );
        }
    };

    // ─── Comments ───────────────────────────────────────────────────────────

    const addComment = async (bandId: string, body: string) => {
        try {
            await axios.post(`/api/comment-bands/${bandId}/entries`, { body });
            reloadBands();
        } catch (error: any) {
            toast.error(
                error?.response?.data?.message ?? 'Failed to add comment',
            );
        }
    };

    const deleteComment = async (bandId: string, entryId: string) => {
        try {
            await axios.delete(
                `/api/comment-bands/${bandId}/entries/${entryId}`,
            );
            reloadBands();
        } catch {
            toast.error('Failed to delete comment');
        }
    };

    // ─── Coverage strip ─────────────────────────────────────────────────────

    const strip = (
        segments: Array<{
            key: string;
            from: number;
            to: number;
            label: string;
        }>,
        colored: boolean,
    ) => (
        <div style={{ display: 'flex', width: '100%', height: 26, gap: 2 }}>
            {segments.map((segment, index) => (
                <div
                    key={segment.key}
                    title={`${segment.label} · ${segment.from}–${segment.to}`}
                    style={{
                        flexGrow: Math.max(segment.to - segment.from, 0.01),
                        flexBasis: 0,
                        background: colored
                            ? BAND_COLORS[index % BAND_COLORS.length]
                            : 'var(--slate-lt)',
                        color: colored ? '#fff' : 'var(--slate)',
                        fontSize: 10,
                        display: 'flex',
                        alignItems: 'center',
                        justifyContent: 'center',
                        borderRadius: 3,
                        overflow: 'hidden',
                        whiteSpace: 'nowrap',
                    }}
                >
                    {segment.label}
                </div>
            ))}
        </div>
    );

    const bandSegments = [...bands]
        .sort((a, b) => a.min_score - b.min_score)
        .map((b) => ({
            key: b.id,
            from: b.min_score,
            to: b.max_score,
            label: b.label,
        }));

    // Only the CURRENT exam type's boundaries, so a stale fetch never draws under the
    // wrong ladder. The school-default view has none to compare against.
    const boundarySegments = [
        ...(boundaries && boundaries.examType === examType
            ? boundaries.rows
            : []),
    ]
        .sort((a, b) => Number(a.min_score) - Number(b.min_score))
        .map((b) => ({
            key: b.id,
            from: Number(b.min_score),
            // Grade boundaries treat max_score as exclusive and the seeded top band writes 101,
            // so clamp for display — the strip is a comparison aid, not a second source of truth.
            to: Math.min(Number(b.max_score), MAX_SCORE),
            label: b.grade,
        }));

    const modeSwitch = (
        <div className="filter-row" style={{ marginBottom: 4 }}>
            <button
                className={mode === 'numeric' ? 'filter-btn on' : 'filter-btn'}
                onClick={() => setMode('numeric')}
            >
                Numeric — by score range
            </button>
            <button
                className={
                    mode === 'categorical' ? 'filter-btn on' : 'filter-btn'
                }
                onClick={() => setMode('categorical')}
            >
                Categorical — by rating
            </button>
        </div>
    );

    if (mode === 'categorical') {
        return (
            <>
                <div className="page-hdr">
                    <div>
                        <h1>Score Comments</h1>
                        <p>
                            Comments teachers can pick from when entering
                            results, grouped by the rating given
                        </p>
                    </div>
                </div>
                {modeSwitch}
                <RatingCommentsPanel />
            </>
        );
    }

    return (
        <>
            <div className="page-hdr">
                <div>
                    <h1>Score Comments</h1>
                    <p>
                        Comments teachers can pick from when entering scores,
                        grouped by score range
                    </p>
                </div>
                <div className="page-hdr-actions">
                    {editing ? (
                        <>
                            <button
                                className="btn btn-ghost"
                                onClick={() => setDrafts(null)}
                                disabled={savingBands}
                            >
                                Cancel
                            </button>
                            <button
                                className="btn btn-primary"
                                onClick={saveBands}
                                disabled={savingBands || !!draftError}
                            >
                                {savingBands ? 'Saving…' : 'Save bands'}
                            </button>
                        </>
                    ) : (
                        <button
                            className="btn btn-primary"
                            onClick={startEditing}
                            disabled={loading}
                        >
                            Edit bands
                        </button>
                    )}
                </div>
            </div>

            {modeSwitch}

            <div className="filter-row">
                <button
                    className={
                        examType === null ? 'filter-btn on' : 'filter-btn'
                    }
                    onClick={() => setExamType(null)}
                >
                    School default
                </button>
                {examTypes.map((et) => (
                    <button
                        key={et.id}
                        className={
                            examType === et.id ? 'filter-btn on' : 'filter-btn'
                        }
                        onClick={() => setExamType(et.id)}
                    >
                        {et.name}
                    </button>
                ))}
            </div>

            {examType !== null && bands.length === 0 && !loading && (
                <div
                    style={{
                        marginBottom: 12,
                        fontSize: 12,
                        color: 'var(--slate)',
                    }}
                >
                    This exam type has no bands of its own, so it uses the
                    school default ladder. Adding bands here overrides it for
                    this exam type only.
                </div>
            )}

            {/* Coverage — the point of this strip is that a gap or a mismatch is visible
                BEFORE a teacher discovers it mid-entry. */}
            {bands.length > 0 && (
                <div className="card" style={{ padding: 16, marginBottom: 12 }}>
                    <div
                        style={{
                            fontSize: 11,
                            color: 'var(--slate)',
                            marginBottom: 6,
                        }}
                    >
                        Comment bands (0–{MAX_SCORE})
                    </div>
                    {strip(bandSegments, true)}

                    {boundarySegments.length > 0 && (
                        <>
                            <div
                                style={{
                                    fontSize: 11,
                                    color: 'var(--slate)',
                                    margin: '10px 0 6px',
                                }}
                            >
                                Your grade boundaries, for comparison — they do
                                not have to match
                            </div>
                            {strip(boundarySegments, false)}
                        </>
                    )}
                </div>
            )}

            {editing && draftError && (
                <div
                    style={{
                        marginBottom: 12,
                        padding: '8px 12px',
                        borderRadius: 6,
                        background: 'var(--red-lt)',
                        color: 'var(--red)',
                        fontSize: 12,
                    }}
                >
                    {draftError}
                </div>
            )}

            <div className="card" style={{ padding: 12 }}>
                {loading && (
                    <div style={{ padding: 24, fontSize: 13 }}>Loading…</div>
                )}

                {!loading && !editing && bands.length === 0 && (
                    <Empty
                        icon="💬"
                        title="No comment bands yet"
                        sub="Teachers can still type comments freely. Load the starter set to get suggestions."
                    />
                )}

                {!loading && !editing && bands.length === 0 && (
                    <div
                        style={{ padding: '0 16px 16px', textAlign: 'center' }}
                    >
                        <button
                            className="btn btn-primary"
                            onClick={loadDefaults}
                        >
                            Load starter comments
                        </button>
                    </div>
                )}

                {/* ── Editing the ladder ───────────────────────────────── */}
                {editing &&
                    drafts.map((band, index) => (
                        <div
                            key={band.id ?? `new-${index}`}
                            style={{
                                display: 'flex',
                                gap: 8,
                                alignItems: 'center',
                                padding: '6px 8px',
                            }}
                        >
                            <label
                                style={{ fontSize: 11, color: 'var(--slate)' }}
                            >
                                From
                            </label>
                            <input
                                type="number"
                                min={0}
                                max={MAX_SCORE}
                                value={band.min_score}
                                onChange={(e) =>
                                    updateDraft(index, {
                                        min_score: Number(e.target.value),
                                    })
                                }
                                style={{ width: 80 }}
                            />
                            <input
                                type="text"
                                placeholder="Label, e.g. Excellent"
                                maxLength={50}
                                value={band.label}
                                onChange={(e) =>
                                    updateDraft(index, {
                                        label: e.target.value,
                                    })
                                }
                                style={{ flex: 1 }}
                            />
                            <button
                                className="btn btn-ghost btn-sm btn-icon"
                                title="Remove band (its comments go with it)"
                                onClick={() => removeDraft(index)}
                            >
                                <Trash2 className="h-[14px] w-[14px]" />
                            </button>
                        </div>
                    ))}

                {editing && (
                    <div style={{ padding: '6px 8px' }}>
                        <button
                            className="btn btn-ghost btn-sm"
                            onClick={addDraft}
                        >
                            <Plus className="h-[14px] w-[14px]" /> Add band
                        </button>
                        <span
                            style={{
                                marginLeft: 12,
                                fontSize: 11,
                                color: 'var(--slate)',
                            }}
                        >
                            Each band runs from its own start up to the next
                            band's — you only set where it begins.
                        </span>
                    </div>
                )}

                {/* ── Comments within each band ────────────────────────── */}
                {!editing &&
                    bands.map((band, index) => (
                        <div
                            key={band.id}
                            style={{
                                borderTop:
                                    index === 0
                                        ? 'none'
                                        : '1px solid var(--border)',
                                padding: '12px 8px',
                            }}
                        >
                            <div
                                style={{
                                    display: 'flex',
                                    alignItems: 'center',
                                    gap: 8,
                                    marginBottom: 8,
                                }}
                            >
                                <span
                                    style={{
                                        background:
                                            BAND_COLORS[
                                                (bands.length - 1 - index) %
                                                    BAND_COLORS.length
                                            ],
                                        color: '#fff',
                                        borderRadius: 4,
                                        padding: '2px 8px',
                                        fontSize: 12,
                                        fontWeight: 600,
                                    }}
                                >
                                    {band.label}
                                </span>
                                <span
                                    style={{
                                        fontSize: 12,
                                        color: 'var(--slate)',
                                    }}
                                >
                                    {band.min_score} – {band.max_score}
                                </span>
                                <span
                                    style={{
                                        fontSize: 11,
                                        color: 'var(--slate)',
                                        marginLeft: 'auto',
                                    }}
                                >
                                    {band.comments.length} comment
                                    {band.comments.length === 1 ? '' : 's'}
                                </span>
                            </div>

                            <CommentListEditor
                                comments={band.comments}
                                placeholder="Add a comment for this range…"
                                onAdd={(body) => addComment(band.id, body)}
                                onDelete={(entryId) =>
                                    deleteComment(band.id, entryId)
                                }
                            />
                        </div>
                    ))}
            </div>
        </>
    );
}
