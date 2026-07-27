// ═══════════════════════════════════════════════════════════════════════════
// RATING COMMENTS PANEL
//
// The categorical half of Score Comments: the suggestions a teacher is offered
// when they give a student a particular RATING.
//
// Ratings are not mapped onto a 0-100 scale to reuse the numeric bands. A
// grading scheme carries only code/label/display_order and typically includes
// entries like "Not Applicable", so ordinal position is not a quality ranking —
// mapping it would file "Not Applicable" at the bottom and suggest "This result
// is below expectation" for it. The comments hang off the rating itself.
//
// The ratings themselves are NOT editable here; they belong to Categorical
// Grading. This panel fills in a ladder the school already defined.
// ═══════════════════════════════════════════════════════════════════════════

import axios from 'axios';
import { useCallback, useEffect, useState } from 'react';
import { toast } from 'react-toastify';
import { Empty } from '@/components/setup/setup-ui';
import type { CommentEntry, GradingScheme } from '@/types/models';
import { CommentListEditor } from './comment-list-editor';

interface RatingWithComments {
    id: string;
    code: string;
    label: string;
    display_order: number;
    comments: CommentEntry[];
}

export function RatingCommentsPanel() {
    const [schemes, setSchemes] = useState<GradingScheme[]>([]);
    const [scheme, setScheme] = useState<string | null>(null);
    // Tagged with the scheme it belongs to, so "loading" is derived and switching schemes never
    // shows one scheme's ratings under another's heading.
    const [loaded, setLoaded] = useState<{
        scheme: string;
        ratings: RatingWithComments[];
    } | null>(null);
    const [reloadToken, setReloadToken] = useState(0);

    const reload = useCallback(() => setReloadToken((t) => t + 1), []);

    useEffect(() => {
        axios
            .get('/api/grading-schemes')
            .then((r) => {
                const rows: GradingScheme[] = r.data.data ?? [];
                setSchemes(rows);
                setScheme((current) => current ?? rows[0]?.id ?? null);
            })
            .catch(() => toast.error('Failed to load grading schemes'));
    }, []);

    useEffect(() => {
        if (!scheme) {
            return;
        }

        const fetchRatings = async () => {
            try {
                const response = await axios.get(
                    `/api/grading-schemes/${scheme}/rating-comments`,
                );
                setLoaded({ scheme, ratings: response.data.data ?? [] });
            } catch {
                toast.error('Failed to load rating comments');
            }
        };

        fetchRatings();
    }, [scheme, reloadToken]);

    const ratings =
        loaded && loaded.scheme === scheme ? loaded.ratings : undefined;

    const addComment = async (ratingId: string, body: string) => {
        try {
            await axios.post(`/api/grading-scheme-items/${ratingId}/comments`, {
                body,
            });
            reload();
        } catch (error: any) {
            toast.error(
                error?.response?.data?.message ?? 'Failed to add comment',
            );
        }
    };

    const deleteComment = async (ratingId: string, entryId: string) => {
        try {
            await axios.delete(
                `/api/grading-scheme-items/${ratingId}/comments/${entryId}`,
            );
            reload();
        } catch {
            toast.error('Failed to delete comment');
        }
    };

    if (schemes.length === 0) {
        return (
            <Empty
                icon="🏷️"
                title="No categorical grading schemes"
                sub="Create one under Categorical Grading first — rating comments attach to its ratings."
            />
        );
    }

    return (
        <>
            <div className="filter-row">
                {schemes.map((s) => (
                    <button
                        key={s.id}
                        className={
                            scheme === s.id ? 'filter-btn on' : 'filter-btn'
                        }
                        onClick={() => setScheme(s.id)}
                    >
                        {s.name}
                    </button>
                ))}
            </div>

            <div
                style={{
                    marginBottom: 12,
                    fontSize: 12,
                    color: 'var(--slate)',
                }}
            >
                Teachers see these when they pick a rating for a student. The
                ratings themselves are managed under Categorical Grading.
            </div>

            <div className="card" style={{ padding: 12 }}>
                {ratings === undefined && (
                    <div style={{ padding: 24, fontSize: 13 }}>Loading…</div>
                )}

                {ratings?.length === 0 && (
                    <Empty
                        icon="🏷️"
                        title="This scheme has no ratings"
                        sub="Add ratings under Categorical Grading, then come back to write their comments."
                    />
                )}

                {ratings?.map((rating, index) => (
                    <div
                        key={rating.id}
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
                                    background: 'var(--blue)',
                                    color: '#fff',
                                    borderRadius: 4,
                                    padding: '2px 8px',
                                    fontSize: 12,
                                    fontWeight: 600,
                                }}
                            >
                                {rating.code}
                            </span>
                            <span style={{ fontSize: 13 }}>{rating.label}</span>
                            <span
                                style={{
                                    fontSize: 11,
                                    color: 'var(--slate)',
                                    marginLeft: 'auto',
                                }}
                            >
                                {rating.comments.length} comment
                                {rating.comments.length === 1 ? '' : 's'}
                            </span>
                        </div>

                        <CommentListEditor
                            comments={rating.comments}
                            placeholder={`Add a comment for "${rating.label}"…`}
                            onAdd={(body) => addComment(rating.id, body)}
                            onDelete={(entryId) =>
                                deleteComment(rating.id, entryId)
                            }
                        />
                    </div>
                ))}
            </div>
        </>
    );
}
