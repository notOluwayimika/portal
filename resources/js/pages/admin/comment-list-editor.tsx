// ═══════════════════════════════════════════════════════════════════════════
// COMMENT LIST EDITOR
//
// The add/remove list for one parent's comments. Shared by both grading modes:
// a numeric score band and a categorical rating get the SAME editor, the same
// character counter and the same limit — which is what keeps "categorical works
// the same way numeric does" true in the UI as well as in the API.
// ═══════════════════════════════════════════════════════════════════════════

import { Check, X } from 'lucide-react';
import { useState } from 'react';
import type { CommentEntry } from '@/types/models';

/** Must match CommentEntry::MAX_LENGTH and the student_subjects.comment column. */
export const COMMENT_MAX_LENGTH = 100;

export function CommentListEditor({
    comments,
    onAdd,
    onDelete,
    placeholder = 'Add a comment…',
}: {
    comments: CommentEntry[];
    onAdd: (body: string) => void | Promise<void>;
    onDelete: (id: string) => void | Promise<void>;
    placeholder?: string;
}) {
    const [draft, setDraft] = useState('');

    const submit = () => {
        const body = draft.trim();

        if (!body) {
            return;
        }

        setDraft('');
        onAdd(body);
    };

    return (
        <>
            {comments.map((entry) => (
                <div
                    key={entry.id}
                    style={{
                        display: 'flex',
                        alignItems: 'center',
                        gap: 8,
                        padding: '3px 0',
                        fontSize: 13,
                    }}
                >
                    <span style={{ flex: 1 }}>{entry.body}</span>
                    <button
                        className="btn btn-ghost btn-sm btn-icon"
                        title="Delete comment"
                        onClick={() => onDelete(entry.id)}
                    >
                        <X className="h-[13px] w-[13px]" />
                    </button>
                </div>
            ))}

            <div
                style={{
                    display: 'flex',
                    gap: 8,
                    alignItems: 'center',
                    marginTop: 6,
                }}
            >
                <input
                    type="text"
                    placeholder={placeholder}
                    maxLength={COMMENT_MAX_LENGTH}
                    value={draft}
                    onChange={(e) => setDraft(e.target.value)}
                    onKeyDown={(e) => e.key === 'Enter' && submit()}
                    style={{ flex: 1 }}
                />
                {/* Teachers hit a hard server limit at this length, so the count is shown while
                    authoring rather than discovered on save. */}
                <span
                    style={{
                        fontSize: 11,
                        color:
                            draft.length > COMMENT_MAX_LENGTH - 10
                                ? 'var(--red)'
                                : 'var(--slate)',
                        minWidth: 48,
                        textAlign: 'right',
                    }}
                >
                    {draft.length}/{COMMENT_MAX_LENGTH}
                </span>
                <button
                    className="btn btn-ghost btn-sm btn-icon"
                    title="Add comment"
                    onClick={submit}
                >
                    <Check className="h-[14px] w-[14px]" />
                </button>
            </div>
        </>
    );
}
