import { Head, Link } from '@inertiajs/react';
import axios from 'axios';
import {
    AlertTriangle,
    ArrowLeft,
    BookOpen,
    CheckCircle2,
    ChevronDown,
    Download,
    FileSpreadsheet,
    GraduationCap,
    Loader2,
    Percent,
    Upload,
    UploadCloud,
    XCircle,
} from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { toast } from 'react-toastify';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { usePermissions } from '@/hooks/use-permissions';
import { cn } from '@/lib/utils';
import {
    DISCOUNT_AWARD_IMPORT_TERMINAL,
    discountAwardImports,
} from '@/services/discount-award-imports';
import type {
    DiscountAwardImportRecord,
    DiscountAwardImportRow,
    DiscountAwardOutcome,
} from '@/services/discount-award-imports';

/**
 * THE BSS DISCOUNT-AWARD OPERATOR SCREEN.
 *
 * Brookstone's accounts team holds the scholarship list outside the system, as a spreadsheet pairing
 * each student with the percentage they were awarded. This is where it comes in: download the template,
 * fill it from the BSS list, upload it, read what happened to every row. The four endpoints it drives
 * shipped one commit earlier and rendered on nothing.
 *
 * IT IS THE IMPORT FLOW THIS CODEBASE ALREADY HAS, and the sibling is
 * pages/admin/finance/opening-balances/import.tsx: the same four states, the same terminal-set poll,
 * the same wayfinder-only URLs, the same format guide rendered from the server's own constants.
 *
 * ── THE EMPTY STATE IS THE PART THAT WAS WORTH BUILDING ──────────────────────────────────────────
 *
 * Every row resolves to an ACTIVE percentage discount policy matching its (percentage, applies-to)
 * pair, and a pair nobody approved is refused — by design, because this import never creates a policy.
 * So a school holding no such policies rejects EVERY row, and a bursar who uploads ninety-one rows to
 * be told ninety-one times that nothing matched has been failed by this screen, not by their file.
 *
 * The pairs therefore arrive as a PROP and the warning is on the first paint. The same list answers the
 * question that follows the empty one — which percentages this sheet may use — and flags a pair carrying
 * two active policies, which the importer also refuses. All three are knowable before the upload, and
 * a refusal knowable beforehand that is only reported afterwards is a refusal this screen chose not to
 * prevent.
 *
 * ── THE REPORT IS FOR A BURSAR ───────────────────────────────────────────────────────────────────
 *
 * Every row is shown, keyed by THEIR line number and THEIR admission number — not by a name read back
 * out of the database — and every outcome is rendered in words they can act on. ALREADY AWARDED IS NOT
 * RED. It is what a correct re-upload produces, and a screen that paints ninety-one non-events as
 * failures is a screen nobody trusts on the third run.
 *
 * The rows are structured data on the status endpoint, not a CSV parsed in the browser: the job persists
 * the outcomes it computed beside the CSV it renders from them. The download stays, because a file is
 * what a bursar works from.
 */

/** One column of the format, as `DiscountAwardImporter::COLUMNS` describes it. */
interface FormatColumn {
    column: string;
    required: boolean;
    format: string;
    example: string;
    notes: string;
}

/** A rule that belongs to no single column — `DiscountAwardImporter::NOTES`. */
interface FormatNote {
    rule: string;
    meaning: string;
}

/**
 * One (percentage, applies-to) pair this school has approved, and how many active policies sit on it.
 *
 * `applies_to` IS THE PHRASE TO TYPE IN THE SHEET, not the enum value — it comes off
 * `DiscountAwardImporter::appliesToLabel()`, the same function the refusal messages read, so the screen
 * cannot teach a word the file would refuse.
 */
interface PolicyPair {
    percent: number;
    base: string;
    applies_to: string;
    policy_count: number;
}

const OUTCOME_LABEL: Record<DiscountAwardOutcome, string> = {
    awarded: 'Awarded',
    // NOT "skipped", and not anything with "fail" in it. This is a student who is already on exactly
    // the policy their row asked for: the correct outcome of a second upload, and nothing to fix.
    already_awarded: 'Already awarded',
    // NOT "failed" either. The row was refused and the reason beside it says what to do; "failed"
    // invites a bursar to look for a fault in the portal.
    rejected: 'Not applied',
};

function outcomeChip(outcome: DiscountAwardOutcome) {
    switch (outcome) {
        case 'awarded':
            return 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-400';
        case 'already_awarded':
            return 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300';
        default:
            return 'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-400';
    }
}

function outcomeIcon(outcome: DiscountAwardOutcome) {
    switch (outcome) {
        case 'awarded':
            return <CheckCircle2 className="h-3.5 w-3.5" />;
        case 'already_awarded':
            return <CheckCircle2 className="h-3.5 w-3.5" />;
        default:
            return <XCircle className="h-3.5 w-3.5" />;
    }
}

function Stat({
    label,
    value,
    tone,
}: {
    label: string;
    value: number | string;
    tone?: 'good' | 'bad';
}) {
    return (
        <div className="rounded-xl border border-slate-100 bg-white px-4 py-3 shadow-sm dark:border-white/5 dark:bg-card">
            <p className="text-[10px] font-bold tracking-wide text-slate-400 uppercase">
                {label}
            </p>
            <p
                className={cn(
                    'mt-1 text-2xl font-extrabold tracking-tight tabular-nums',
                    tone === 'good' && 'text-emerald-600 dark:text-emerald-400',
                    tone === 'bad' && 'text-red-600 dark:text-red-400',
                    tone === undefined && 'text-slate-800 dark:text-slate-100',
                )}
            >
                {value}
            </p>
        </div>
    );
}

export default function DiscountAwardImports({
    columns,
    notes,
    pairs,
}: {
    columns: FormatColumn[];
    notes: FormatNote[];
    pairs: PolicyPair[];
}) {
    const { can } = usePermissions();
    const fileInputRef = useRef<HTMLInputElement>(null);

    const [file, setFile] = useState<File | null>(null);
    const [submitting, setSubmitting] = useState(false);
    const [active, setActive] = useState<DiscountAwardImportRecord | null>(
        null,
    );
    const [dragOver, setDragOver] = useState(false);
    const [formatOpen, setFormatOpen] = useState(false);
    const [outcomeFilter, setOutcomeFilter] = useState<
        DiscountAwardOutcome | 'all'
    >('all');

    // NO ACTIVE PERCENTAGE POLICY MEANS EVERY ROW WILL BE REFUSED. The upload is withheld rather than
    // offered-and-then-explained: an operator who can press it will press it, and the report they get
    // back says nothing they could not have been told first.
    const noPolicies = pairs.length === 0;
    const ambiguous = pairs.filter((pair) => pair.policy_count > 1);

    // Poll while the job is in flight. The terminal set is stated positively — `!== 'completed'` would
    // spin forever on a failed import, which is the state most worth reaching.
    useEffect(() => {
        if (
            active === null ||
            DISCOUNT_AWARD_IMPORT_TERMINAL.includes(active.status)
        ) {
            return;
        }

        const id = setInterval(() => {
            void (async () => {
                try {
                    const res = await axios.get<{
                        import: DiscountAwardImportRecord;
                    }>(discountAwardImports.statusUrl(active.uuid));
                    setActive(res.data.import);
                } catch {
                    /* keep polling — a transient 500 must not strand the screen */
                }
            })();
        }, 2000);

        return () => clearInterval(id);
    }, [active]);

    const pickFile = useCallback((chosen: File | undefined) => {
        if (chosen === undefined) {
            return;
        }

        setFile(chosen);
    }, []);

    const upload = async () => {
        if (file === null) {
            return;
        }

        setSubmitting(true);

        try {
            const res = await axios.post<{
                import: DiscountAwardImportRecord;
            }>(
                discountAwardImports.storeUrl(),
                discountAwardImports.formData(file),
                { headers: { 'Content-Type': 'multipart/form-data' } },
            );

            setActive(res.data.import);
            setOutcomeFilter('all');
            setFile(null);

            if (fileInputRef.current !== null) {
                fileInputRef.current.value = '';
            }

            toast.info(
                'Uploaded. Every row is being read — this page will show what happened to each one.',
            );
        } catch (err: unknown) {
            // THE FIELD ERROR FIRST, the envelope only as a fallback. A 422 from a FormRequest carries
            // Laravel's generic "There are validation errors" in `message` and the sentence the
            // operator actually needs in `errors.file[0]`; reading `message` throws away the one line
            // that says what to do about an .xlsx.
            const message = axios.isAxiosError(err)
                ? (Object.values(
                      (err.response?.data?.errors ?? {}) as Record<
                          string,
                          string[]
                      >,
                  )[0]?.[0] ??
                  err.response?.data?.message ??
                  'Could not upload this list.')
                : 'Could not upload this list.';
            toast.error(message);
        } finally {
            setSubmitting(false);
        }
    };

    const inFlight =
        active !== null &&
        !DISCOUNT_AWARD_IMPORT_TERMINAL.includes(active.status);

    const rows: DiscountAwardImportRow[] = active?.rows ?? [];
    const shown =
        outcomeFilter === 'all'
            ? rows
            : rows.filter((row) => row.outcome === outcomeFilter);

    return (
        <>
            <Head title="Discount awards — import" />

            <div className="min-h-screen bg-[#f5f7fb] px-4 py-5 pb-24 sm:px-6 lg:px-8 dark:bg-background">
                <div className="mx-auto max-w-7xl space-y-5">
                    {/* ── Hero: the template lives here ────────────────────────────────────── */}
                    <div className="relative overflow-hidden rounded-2xl border border-white bg-white px-6 py-4 shadow-[0_8px_30px_rgb(0,0,0,0.04)] dark:border-white/5 dark:bg-card">
                        <div className="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                            <div className="flex items-center gap-4">
                                <div className="flex size-12 shrink-0 items-center justify-center rounded-xl bg-linear-to-br from-indigo-50 to-violet-50 shadow-sm ring-1 ring-black/5 dark:from-indigo-950/50 dark:to-violet-950/50">
                                    <GraduationCap className="h-6 w-6 text-indigo-600 dark:text-indigo-400" />
                                </div>
                                <div>
                                    <div className="flex items-center gap-2">
                                        <Link
                                            href="/finance"
                                            className="text-slate-400 transition-colors hover:text-indigo-600"
                                            title="Back to accounts"
                                        >
                                            <ArrowLeft className="h-4 w-4" />
                                        </Link>
                                        <h1 className="text-xl font-extrabold tracking-tight text-slate-900 dark:text-white">
                                            Discount awards — import
                                        </h1>
                                    </div>
                                    <p className="max-w-2xl text-xs text-slate-500">
                                        Put students on discount policies your
                                        school has already approved, from the
                                        scholarship list. This never creates a
                                        policy and never changes an award that
                                        already exists — uploading the same
                                        sheet twice is safe.
                                    </p>
                                </div>
                            </div>

                            <a
                                href={discountAwardImports.templateUrl()}
                                download
                                className="shrink-0"
                            >
                                <Button
                                    size="sm"
                                    className="rounded-lg bg-indigo-600 px-4 font-semibold text-white shadow-md transition-all hover:bg-indigo-700 hover:shadow-lg active:scale-95"
                                >
                                    <Download className="mr-1.5 h-4 w-4" />
                                    Download template
                                </Button>
                            </a>
                        </div>
                    </div>

                    {/* ── THE POLICIES THIS SHEET CAN NAME ─────────────────────────────────────
                        The empty case is not a warning beside the upload, it is INSTEAD of it. */}
                    {noPolicies ? (
                        <Card className="overflow-hidden rounded-xl border-none shadow-[0_8px_30px_rgb(0,0,0,0.04)]">
                            <CardContent className="flex flex-col gap-3 p-5">
                                <div className="flex items-start gap-3">
                                    <span className="mt-0.5 flex size-8 shrink-0 items-center justify-center rounded-lg bg-amber-50 ring-1 ring-amber-200 dark:bg-amber-950/40 dark:ring-amber-900">
                                        <AlertTriangle className="h-4 w-4 text-amber-600 dark:text-amber-400" />
                                    </span>
                                    <div className="space-y-2">
                                        <p className="text-sm font-bold text-slate-800 dark:text-slate-100">
                                            This school has no approved
                                            percentage discount policies yet, so
                                            there is nothing a list could put a
                                            student on.
                                        </p>
                                        <p className="text-xs leading-relaxed text-slate-600 dark:text-slate-400">
                                            Every row of this file names a
                                            percentage and what it comes off,
                                            and is matched against a discount
                                            policy that is already{' '}
                                            <strong>approved and active</strong>
                                            . This import never creates one. If
                                            you upload now, every row will be
                                            refused for the same reason.
                                        </p>
                                        <p className="text-xs leading-relaxed text-slate-600 dark:text-slate-400">
                                            Each percentage you intend to use —
                                            on each of{' '}
                                            <strong>
                                                DISCOUNTABLE CHARGES
                                            </strong>{' '}
                                            and <strong>THE WHOLE BILL</strong>{' '}
                                            — has to be submitted and approved
                                            through the discount-policy approval
                                            flow first. That approval is what
                                            makes the figure legitimate; this
                                            sheet only says which student sits
                                            on which approved figure.
                                        </p>

                                        {/* The link is offered ONLY to a seat that holds the ability
                                            its route requires. An offered link that 403s is worse
                                            than prose — the seeded accounts_officer holds both, but a
                                            runtime matrix edit can separate them. */}
                                        {can(
                                            'finance.discount-policy.change.submit',
                                        ) ? (
                                            <Link
                                                href="/finance/discount-policies"
                                                className="inline-flex items-center gap-1.5 text-xs font-semibold text-indigo-600 hover:text-indigo-700"
                                            >
                                                <Percent className="h-3.5 w-3.5" />
                                                Go to Discount policies to
                                                submit them
                                            </Link>
                                        ) : (
                                            <p className="text-xs font-semibold text-slate-500">
                                                Discount policies are submitted
                                                on the Discount policies screen,
                                                which needs a different
                                                permission from this one — ask
                                                whoever authors your school's
                                                fee policies.
                                            </p>
                                        )}
                                    </div>
                                </div>
                            </CardContent>
                        </Card>
                    ) : (
                        <Card className="overflow-hidden rounded-xl border-none shadow-[0_8px_30px_rgb(0,0,0,0.04)]">
                            <CardContent className="space-y-3 p-5">
                                <div>
                                    <p className="text-sm font-bold text-slate-800 dark:text-slate-100">
                                        What your sheet may name
                                    </p>
                                    <p className="mt-1 text-xs text-slate-500">
                                        A row is matched against one of these.
                                        Anything else is refused — this import
                                        never creates a policy.
                                    </p>
                                </div>

                                <div className="flex flex-wrap gap-2">
                                    {pairs.map((pair) => (
                                        <span
                                            key={`${pair.percent}-${pair.base}`}
                                            className={cn(
                                                'inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-xs font-semibold ring-1',
                                                pair.policy_count > 1
                                                    ? 'bg-amber-50 text-amber-800 ring-amber-200 dark:bg-amber-950/40 dark:text-amber-300 dark:ring-amber-900'
                                                    : 'bg-slate-50 text-slate-700 ring-slate-200 dark:bg-slate-900 dark:text-slate-200 dark:ring-slate-700',
                                            )}
                                        >
                                            {pair.policy_count > 1 && (
                                                <AlertTriangle className="h-3.5 w-3.5" />
                                            )}
                                            {pair.percent}% of {pair.applies_to}
                                        </span>
                                    ))}
                                </div>

                                {/* Two active policies on one pair is ALSO a refusal — the import will
                                    not choose on your behalf. Knowable now rather than afterwards. */}
                                {ambiguous.length > 0 && (
                                    <p className="rounded-lg bg-amber-50 px-3 py-2 text-xs leading-relaxed text-amber-800 dark:bg-amber-950/40 dark:text-amber-300">
                                        {ambiguous.length === 1
                                            ? 'One of these pairs has more than one active policy on it, so a row naming it will be refused: '
                                            : 'Some of these pairs have more than one active policy on them, so a row naming one will be refused: '}
                                        {ambiguous
                                            .map(
                                                (pair) =>
                                                    `${pair.percent}% of ${pair.applies_to} (${pair.policy_count} policies)`,
                                            )
                                            .join(', ')}
                                        . Retire the ones no longer in use
                                        before you upload — we will not choose
                                        which one a student is on.
                                    </p>
                                )}
                            </CardContent>
                        </Card>
                    )}

                    {/* ── THE FORMAT ───────────────────────────────────────────────────────────
                        The template is a single-sheet CSV and cannot carry a format reference, so
                        these rules live here — rendered from the SAME constants the template renders,
                        so the file an operator fills in and the reference beside it cannot drift. */}
                    <Card className="overflow-hidden rounded-xl border-none shadow-[0_8px_30px_rgb(0,0,0,0.04)]">
                        <button
                            type="button"
                            onClick={() => setFormatOpen((v) => !v)}
                            aria-expanded={formatOpen}
                            className="flex w-full items-center justify-between gap-3 border-b border-slate-50 bg-slate-50/30 px-5 py-3 text-left transition-colors hover:bg-slate-50/70 dark:hover:bg-slate-900/40"
                        >
                            <span className="flex items-center gap-2.5 text-sm font-bold text-slate-800 dark:text-slate-100">
                                <span className="flex size-7 items-center justify-center rounded-lg bg-white shadow-sm ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-700">
                                    <BookOpen className="h-4 w-4 text-indigo-600" />
                                </span>
                                Read this before you fill in the file
                            </span>
                            <span className="flex items-center gap-2 text-xs font-semibold text-indigo-600">
                                {formatOpen ? 'Hide' : 'Open the format guide'}
                                <ChevronDown
                                    className={cn(
                                        'h-4 w-4 transition-transform',
                                        formatOpen && 'rotate-180',
                                    )}
                                />
                            </span>
                        </button>

                        {!formatOpen && (
                            <p className="px-5 py-3 text-xs text-slate-500">
                                Three columns, and the third one changes the
                                money. Open it before you start.
                            </p>
                        )}

                        <CardContent
                            className={cn(
                                'space-y-5 p-5',
                                !formatOpen && 'hidden',
                            )}
                        >
                            <div className="overflow-x-auto">
                                <table className="w-full min-w-[52rem] text-left text-xs">
                                    <thead>
                                        <tr className="border-b border-slate-100 text-[10px] font-bold tracking-wide text-slate-400 uppercase dark:border-white/5">
                                            <th className="py-2 pr-3">
                                                Column
                                            </th>
                                            <th className="py-2 pr-3">
                                                Format
                                            </th>
                                            <th className="py-2 pr-3">
                                                Example
                                            </th>
                                            <th className="py-2">
                                                What it must contain
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {columns.map((column) => (
                                            <tr
                                                key={column.column}
                                                className="border-b border-slate-50 align-top last:border-0 dark:border-white/5"
                                            >
                                                <td className="py-2.5 pr-3 font-mono font-semibold text-slate-800 dark:text-slate-100">
                                                    {column.column}
                                                    {column.required && (
                                                        <span className="ml-1 text-red-500">
                                                            *
                                                        </span>
                                                    )}
                                                </td>
                                                <td className="py-2.5 pr-3 text-slate-600 dark:text-slate-400">
                                                    {column.format}
                                                </td>
                                                <td className="py-2.5 pr-3 font-mono text-slate-500">
                                                    {column.example}
                                                </td>
                                                <td className="py-2.5 leading-relaxed text-slate-600 dark:text-slate-400">
                                                    {column.notes}
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>

                            <div className="space-y-2.5">
                                {notes.map((note) => (
                                    <div
                                        key={note.rule}
                                        className="rounded-lg bg-slate-50/70 px-3 py-2.5 dark:bg-slate-900/40"
                                    >
                                        <p className="text-xs font-bold text-slate-800 dark:text-slate-100">
                                            {note.rule}
                                        </p>
                                        <p className="mt-1 text-xs leading-relaxed text-slate-600 dark:text-slate-400">
                                            {note.meaning}
                                        </p>
                                    </div>
                                ))}
                            </div>
                        </CardContent>
                    </Card>

                    {/* ── THE UPLOAD ───────────────────────────────────────────────────────────
                        Withheld entirely when there is nothing a row could match. */}
                    {!noPolicies && (
                        <Card className="overflow-hidden rounded-xl border-none shadow-[0_8px_30px_rgb(0,0,0,0.04)]">
                            <CardContent className="space-y-4 p-5">
                                <div>
                                    <p className="text-sm font-bold text-slate-800 dark:text-slate-100">
                                        Upload the filled-in list
                                    </p>
                                    <p className="mt-1 text-xs text-slate-500">
                                        CSV only — the template is a CSV, and
                                        that is the format this reads. If you
                                        opened it in Excel, use{' '}
                                        <strong>Save As</strong> and choose CSV
                                        again.
                                    </p>
                                </div>

                                <div
                                    onDragOver={(e) => {
                                        e.preventDefault();
                                        setDragOver(true);
                                    }}
                                    onDragLeave={() => setDragOver(false)}
                                    onDrop={(e) => {
                                        e.preventDefault();
                                        setDragOver(false);
                                        pickFile(e.dataTransfer.files[0]);
                                    }}
                                    onClick={() =>
                                        fileInputRef.current?.click()
                                    }
                                    className={cn(
                                        'flex cursor-pointer flex-col items-center justify-center gap-2 rounded-xl border-2 border-dashed px-4 py-8 text-center transition-colors',
                                        dragOver
                                            ? 'border-indigo-400 bg-indigo-50/60 dark:bg-indigo-950/30'
                                            : 'border-slate-200 hover:border-indigo-300 dark:border-slate-700',
                                    )}
                                >
                                    <UploadCloud className="h-7 w-7 text-slate-400" />
                                    {file === null ? (
                                        <p className="text-xs text-slate-500">
                                            Drop the CSV here, or click to
                                            choose it
                                        </p>
                                    ) : (
                                        <p className="flex items-center gap-1.5 text-xs font-semibold text-slate-700 dark:text-slate-200">
                                            <FileSpreadsheet className="h-4 w-4 text-indigo-600" />
                                            {file.name}
                                        </p>
                                    )}
                                    <Input
                                        ref={fileInputRef}
                                        type="file"
                                        accept=".csv,text/csv"
                                        className="hidden"
                                        onChange={(e) =>
                                            pickFile(e.target.files?.[0])
                                        }
                                    />
                                </div>

                                <div className="flex justify-end">
                                    <Button
                                        size="sm"
                                        disabled={file === null || submitting}
                                        onClick={() => void upload()}
                                        className="rounded-lg bg-indigo-600 px-4 font-semibold text-white shadow-md transition-all hover:bg-indigo-700 disabled:opacity-50"
                                    >
                                        {submitting ? (
                                            <Loader2 className="mr-1.5 h-4 w-4 animate-spin" />
                                        ) : (
                                            <Upload className="mr-1.5 h-4 w-4" />
                                        )}
                                        Upload list
                                    </Button>
                                </div>
                            </CardContent>
                        </Card>
                    )}

                    {/* ── IN FLIGHT ────────────────────────────────────────────────────────── */}
                    {inFlight && active !== null && (
                        <Card className="overflow-hidden rounded-xl border-none shadow-[0_8px_30px_rgb(0,0,0,0.04)]">
                            <CardContent className="flex items-center gap-3 p-5">
                                <Loader2 className="h-5 w-5 animate-spin text-indigo-600" />
                                <div>
                                    <p className="text-sm font-bold text-slate-800 dark:text-slate-100">
                                        Reading {active.file_name}
                                    </p>
                                    <p className="mt-0.5 text-xs text-slate-500">
                                        Every row is applied one at a time. This
                                        page will show what happened to each of
                                        them — you can leave it open.
                                    </p>
                                </div>
                            </CardContent>
                        </Card>
                    )}

                    {/* ── THE REPORT ───────────────────────────────────────────────────────── */}
                    {active !== null &&
                        DISCOUNT_AWARD_IMPORT_TERMINAL.includes(
                            active.status,
                        ) && (
                            <div className="space-y-4">
                                {/* A FAILED import is a fact about the FILE or about US, never a row
                                    defect — the job says which, in words, and this is that sentence. */}
                                {active.status === 'failed' && (
                                    <Card className="overflow-hidden rounded-xl border-none shadow-[0_8px_30px_rgb(0,0,0,0.04)]">
                                        <CardContent className="flex items-start gap-3 p-5">
                                            <XCircle className="mt-0.5 h-5 w-5 shrink-0 text-red-600" />
                                            <div>
                                                <p className="text-sm font-bold text-slate-800 dark:text-slate-100">
                                                    This list was not read
                                                </p>
                                                <p className="mt-1 text-xs leading-relaxed text-slate-600 dark:text-slate-400">
                                                    {active.error ??
                                                        'The import stopped and gave no reason. Nothing further can be said from this screen.'}
                                                </p>
                                            </div>
                                        </CardContent>
                                    </Card>
                                )}

                                {active.status === 'completed' && (
                                    <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
                                        <Stat
                                            label="Rows read"
                                            value={active.total_rows}
                                        />
                                        <Stat
                                            label="Awarded"
                                            value={active.awarded}
                                            tone="good"
                                        />
                                        {/* NO TONE. Already awarded is neither good news nor bad — it
                                            is the correct outcome of a second upload, and colouring it
                                            either way tells the bursar something untrue. */}
                                        <Stat
                                            label="Already awarded"
                                            value={active.already_awarded}
                                        />
                                        <Stat
                                            label="Not applied"
                                            value={active.rejected}
                                            tone={
                                                active.rejected > 0
                                                    ? 'bad'
                                                    : undefined
                                            }
                                        />
                                    </div>
                                )}

                                <Card className="overflow-hidden rounded-xl border-none shadow-[0_8px_30px_rgb(0,0,0,0.04)]">
                                    <CardContent className="space-y-4 p-5">
                                        <div className="flex flex-wrap items-center justify-between gap-3">
                                            <div>
                                                <p className="text-sm font-bold text-slate-800 dark:text-slate-100">
                                                    Every row of{' '}
                                                    {active.file_name}
                                                </p>
                                                <p className="mt-0.5 text-xs text-slate-500">
                                                    Shown by the line number and
                                                    the admission number you
                                                    typed.
                                                </p>
                                            </div>

                                            {active.has_report && (
                                                <a
                                                    href={discountAwardImports.reportUrl(
                                                        active.uuid,
                                                    )}
                                                    download
                                                >
                                                    <Button
                                                        size="sm"
                                                        variant="outline"
                                                        className="rounded-lg font-semibold"
                                                    >
                                                        <Download className="mr-1.5 h-4 w-4" />
                                                        Download the report
                                                    </Button>
                                                </a>
                                            )}
                                        </div>

                                        {active.rows === null ? (
                                            <p className="rounded-lg bg-slate-50/70 px-3 py-2.5 text-xs leading-relaxed text-slate-600 dark:bg-slate-900/40 dark:text-slate-400">
                                                {active.has_report
                                                    ? 'The per-row outcomes cannot be shown here for this run — download the report, which carries all of them.'
                                                    : 'There are no per-row outcomes: this run ended before any row was read. The reason is above.'}
                                            </p>
                                        ) : (
                                            <>
                                                <div className="flex flex-wrap gap-2">
                                                    {(
                                                        [
                                                            'all',
                                                            'rejected',
                                                            'awarded',
                                                            'already_awarded',
                                                        ] as const
                                                    ).map((key) => (
                                                        <button
                                                            key={key}
                                                            type="button"
                                                            onClick={() =>
                                                                setOutcomeFilter(
                                                                    key,
                                                                )
                                                            }
                                                            className={cn(
                                                                'rounded-lg px-3 py-1.5 text-xs font-semibold transition-colors',
                                                                outcomeFilter ===
                                                                    key
                                                                    ? 'bg-indigo-600 text-white'
                                                                    : 'bg-slate-100 text-slate-600 hover:bg-slate-200 dark:bg-slate-800 dark:text-slate-300',
                                                            )}
                                                        >
                                                            {key === 'all'
                                                                ? `All (${rows.length})`
                                                                : `${OUTCOME_LABEL[key]} (${rows.filter((row) => row.outcome === key).length})`}
                                                        </button>
                                                    ))}
                                                </div>

                                                <div className="overflow-x-auto">
                                                    <table className="w-full min-w-[56rem] text-left text-xs">
                                                        <thead>
                                                            <tr className="border-b border-slate-100 text-[10px] font-bold tracking-wide text-slate-400 uppercase dark:border-white/5">
                                                                <th className="py-2 pr-3">
                                                                    Line
                                                                </th>
                                                                <th className="py-2 pr-3">
                                                                    Admission
                                                                    number
                                                                </th>
                                                                <th className="py-2 pr-3">
                                                                    Discount
                                                                </th>
                                                                <th className="py-2 pr-3">
                                                                    Outcome
                                                                </th>
                                                                <th className="py-2">
                                                                    What it
                                                                    means
                                                                </th>
                                                            </tr>
                                                        </thead>
                                                        <tbody>
                                                            {shown.map(
                                                                (row) => (
                                                                    <tr
                                                                        key={
                                                                            row.line_number
                                                                        }
                                                                        className="border-b border-slate-50 align-top last:border-0 dark:border-white/5"
                                                                    >
                                                                        <td className="py-2.5 pr-3 font-semibold text-slate-500 tabular-nums">
                                                                            {
                                                                                row.line_number
                                                                            }
                                                                        </td>
                                                                        {/* VERBATIM, whitespace and all — a trailing
                                                                            space they cannot see on screen is exactly
                                                                            what they need shown back. `whitespace-pre`
                                                                            is what preserves it. */}
                                                                        <td className="py-2.5 pr-3 font-mono font-semibold whitespace-pre text-slate-800 dark:text-slate-100">
                                                                            {
                                                                                row.admission_number
                                                                            }
                                                                        </td>
                                                                        <td className="py-2.5 pr-3 font-mono whitespace-pre text-slate-600 dark:text-slate-400">
                                                                            {
                                                                                row.discount_percentage
                                                                            }
                                                                            % of{' '}
                                                                            {
                                                                                row.discount_applies_to
                                                                            }
                                                                        </td>
                                                                        <td className="py-2.5 pr-3">
                                                                            <span
                                                                                className={cn(
                                                                                    'inline-flex items-center gap-1.5 rounded-md px-2 py-1 text-[11px] font-bold',
                                                                                    outcomeChip(
                                                                                        row.outcome,
                                                                                    ),
                                                                                )}
                                                                            >
                                                                                {outcomeIcon(
                                                                                    row.outcome,
                                                                                )}
                                                                                {
                                                                                    OUTCOME_LABEL[
                                                                                        row
                                                                                            .outcome
                                                                                    ]
                                                                                }
                                                                            </span>
                                                                        </td>
                                                                        <td className="py-2.5 leading-relaxed text-slate-600 dark:text-slate-400">
                                                                            {
                                                                                row.reason
                                                                            }
                                                                        </td>
                                                                    </tr>
                                                                ),
                                                            )}
                                                        </tbody>
                                                    </table>
                                                </div>

                                                {shown.length === 0 && (
                                                    <p className="py-4 text-center text-xs text-slate-500">
                                                        No rows with that
                                                        outcome.
                                                    </p>
                                                )}
                                            </>
                                        )}
                                    </CardContent>
                                </Card>
                            </div>
                        )}
                </div>
            </div>
        </>
    );
}
