import { Head, Link } from '@inertiajs/react';
import axios from 'axios';
import {
    AlertTriangle,
    ArrowLeft,
    CheckCircle2,
    Clock,
    Download,
    FileSpreadsheet,
    Loader2,
    Send,
    Upload,
    UploadCloud,
    XCircle,
} from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { toast } from 'react-toastify';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { formatNaira } from '@/lib/format';
import { cn } from '@/lib/utils';
import { openingBalanceImports } from '@/services/opening-balance-imports';
import type {
    OpeningBalanceBatchDetail,
    OpeningBalanceBatchRecord,
} from '@/services/opening-balance-imports';

/**
 * U12b — THE OPENING-BALANCE OPERATOR SCREEN (§9 step 5b-iii).
 *
 * It is the guardian import's flow, applied to a WCBS extract: download the template the platform
 * issues, upload the filled file with a control total, read the findings, submit for approval.
 * Nothing here posts anything — the post is a SECOND person's approval on the approvals queue (§8),
 * and that decision is irreversible, which is why that screen confirms it and this one cannot.
 *
 * THE TEMPLATE BUTTON IS THE POINT OF THE HERO. §9 step 5b-i shipped `GET …/import/template` and
 * linked it from nowhere: a download nobody can find is not a download. This is the screen that was
 * always meant to carry it, and the button is wired to the wayfinder-generated route rather than to
 * a path string, so a rename breaks the build instead of the button.
 *
 * THE CONTROL TOTAL IS A FIELD ON THIS FORM AND THAT IS THE WHOLE REASON L2 IS A CHECK AT ALL. It is
 * the operator's ATTESTATION — read off WCBS's own report and typed by the person doing the upload
 * (§12 decision 2). A total carried inside the file was produced by the same export run as the rows,
 * so a student dropped on the way out of WCBS vanishes from both and the two still agree. The form
 * SAYS this, in the words below the input, rather than leaving it in a docblock the data team never
 * reads.
 *
 * THE PRIVACY DISCIPLINE IS INHERITED FROM THE IMPORT COMMAND AND IT MATTERS MORE HERE. The findings
 * are line numbers, admission numbers and counts — never a name, never a per-student figure beyond
 * the two sides of a failed check on the operator's own file. This is the same report the console
 * printed to one person, now reaching everyone who can open the screen, and widening the audience is
 * exactly when that rule stops being decorative.
 */

interface Term {
    id: number;
    label: string;
}

const TERMINAL: OpeningBalanceBatchRecord['status'][] = [
    'validated',
    'rejected',
    'submitted',
    'posted',
];

function statusChip(status: OpeningBalanceBatchRecord['status']) {
    switch (status) {
        case 'validated':
            return 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-400';
        case 'rejected':
            return 'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-400';
        case 'submitted':
            return 'bg-indigo-100 text-indigo-700 dark:bg-indigo-900/40 dark:text-indigo-400';
        case 'posted':
            return 'bg-teal-100 text-teal-700 dark:bg-teal-900/40 dark:text-teal-400';
        default:
            return 'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-400';
    }
}

function statusIcon(status: OpeningBalanceBatchRecord['status']) {
    switch (status) {
        case 'validated':
            return <CheckCircle2 className="h-3.5 w-3.5" />;
        case 'rejected':
            return <XCircle className="h-3.5 w-3.5" />;
        case 'draft':
            return <Loader2 className="h-3.5 w-3.5 animate-spin" />;
        default:
            return <Clock className="h-3.5 w-3.5" />;
    }
}

function Stat({ label, value }: { label: string; value: number | string }) {
    return (
        <div className="rounded-xl border border-slate-100 bg-white px-4 py-3 shadow-sm dark:border-white/5 dark:bg-card">
            <p className="text-[10px] font-bold tracking-wide text-slate-400 uppercase">
                {label}
            </p>
            <p className="mt-1 text-2xl font-extrabold tracking-tight text-slate-800 tabular-nums dark:text-slate-100">
                {value}
            </p>
        </div>
    );
}

export default function OpeningBalanceImport({ terms }: { terms: Term[] }) {
    const fileInputRef = useRef<HTMLInputElement>(null);

    const [file, setFile] = useState<File | null>(null);
    const [controlTotal, setControlTotal] = useState('');
    const [closingTermId, setClosingTermId] = useState<number | ''>(
        terms[0]?.id ?? '',
    );
    const [asAt, setAsAt] = useState('');
    const [batchReference, setBatchReference] = useState('');

    const [submitting, setSubmitting] = useState(false);
    const [offering, setOffering] = useState(false);
    const [active, setActive] = useState<OpeningBalanceBatchDetail | null>(
        null,
    );
    const [recent, setRecent] = useState<OpeningBalanceBatchRecord[]>([]);
    const [dragOver, setDragOver] = useState(false);

    const refreshRecent = useCallback(async () => {
        try {
            const res = await axios.get<{ data: OpeningBalanceBatchRecord[] }>(
                openingBalanceImports.listUrl(),
            );
            setRecent(res.data.data);
        } catch {
            /* non-fatal: the list is context, not the flow */
        }
    }, []);

    useEffect(() => {
        // eslint-disable-next-line react-hooks/set-state-in-effect
        void refreshRecent();
    }, [refreshRecent]);

    // Poll while the job is in flight. `draft` is the only non-terminal state — the enum's own word
    // for "inserted, not yet run to completion" — so the condition is stated over the terminal set
    // rather than as `!== 'validated'`, which would spin forever on a rejected batch.
    useEffect(() => {
        if (active === null || TERMINAL.includes(active.status)) {
            return;
        }

        const id = setInterval(() => {
            void (async () => {
                try {
                    const res = await axios.get<OpeningBalanceBatchDetail>(
                        openingBalanceImports.statusUrl(active.uuid),
                    );
                    setActive(res.data);

                    if (TERMINAL.includes(res.data.status)) {
                        void refreshRecent();
                    }
                } catch {
                    /* keep polling — a transient 500 must not strand the screen */
                }
            })();
        }, 2000);

        return () => clearInterval(id);
    }, [active, refreshRecent]);

    const pickFile = (chosen: File | undefined) => {
        if (chosen === undefined) {
            return;
        }

        setFile(chosen);

        // The reference defaults to the filename, exactly as the console defaults it to the file's
        // basename. Shown in the field rather than applied invisibly, because it is §7's idempotency
        // key and re-uploading the same name is refused by the database.
        if (batchReference === '') {
            setBatchReference(chosen.name);
        }
    };

    const upload = async () => {
        if (file === null || closingTermId === '') {
            return;
        }

        setSubmitting(true);

        try {
            const res = await axios.post<OpeningBalanceBatchDetail>(
                openingBalanceImports.storeUrl(),
                openingBalanceImports.formData({
                    file,
                    controlTotal,
                    closingTermId,
                    asAt,
                    batchReference,
                }),
                { headers: { 'Content-Type': 'multipart/form-data' } },
            );

            setActive(res.data);
            setFile(null);
            void refreshRecent();
            toast.info(
                'Uploaded. Validating — nothing is posted, and nothing will be until a second person approves it.',
            );
        } catch (err: unknown) {
            const message =
                axios.isAxiosError(err) && err.response?.data?.message
                    ? err.response.data.message
                    : 'Could not upload this extract.';
            toast.error(message);
        } finally {
            setSubmitting(false);
        }
    };

    const offerForApproval = async () => {
        if (active === null) {
            return;
        }

        setOffering(true);

        try {
            const res = await axios.post<OpeningBalanceBatchRecord>(
                openingBalanceImports.submitUrl(active.uuid),
            );
            setActive({ ...active, ...res.data });
            void refreshRecent();
            toast.success(
                `Batch ${res.data.batch_reference} submitted. A second person must approve it before anything posts.`,
            );
        } catch (err: unknown) {
            const message =
                axios.isAxiosError(err) && err.response?.data?.message
                    ? err.response.data.message
                    : 'Could not submit this batch for approval.';
            toast.error(message);
        } finally {
            setOffering(false);
        }
    };

    const canUpload =
        file !== null &&
        controlTotal.trim() !== '' &&
        closingTermId !== '' &&
        asAt !== '' &&
        !submitting;

    return (
        <>
            <Head title="Opening balances — import" />

            <div className="min-h-screen bg-[#f5f7fb] px-4 py-5 pb-24 sm:px-6 lg:px-8 dark:bg-background">
                <div className="mx-auto max-w-7xl space-y-5">
                    {/* ── Hero: the template lives here, and this is the link 5b-i never had ── */}
                    <div className="relative overflow-hidden rounded-2xl border border-white bg-white px-6 py-4 shadow-[0_8px_30px_rgb(0,0,0,0.04)] dark:border-white/5 dark:bg-card">
                        <div className="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                            <div className="flex items-center gap-4">
                                <div className="flex size-12 shrink-0 items-center justify-center rounded-xl bg-linear-to-br from-indigo-50 to-violet-50 shadow-sm ring-1 ring-black/5 dark:from-indigo-950/50 dark:to-violet-950/50">
                                    <UploadCloud className="h-6 w-6 text-indigo-600 dark:text-indigo-400" />
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
                                            Opening balances — import
                                        </h1>
                                    </div>
                                    <p className="max-w-2xl text-xs text-slate-500">
                                        Bring a school's closing position across
                                        from WCBS. Uploading validates and
                                        stages only — nothing reaches a
                                        statement until a second person approves
                                        the batch, and that approval cannot be
                                        undone.
                                    </p>
                                </div>
                            </div>

                            <a
                                href={openingBalanceImports.templateUrl()}
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

                    {/* ── Upload ─────────────────────────────────────────────── */}
                    <Card className="overflow-hidden rounded-xl border-none shadow-[0_8px_30px_rgb(0,0,0,0.04)]">
                        <CardHeader className="border-b border-slate-50 bg-slate-50/30 px-5 py-3">
                            <CardTitle className="flex items-center gap-2.5 text-sm font-bold text-slate-800 dark:text-slate-100">
                                <div className="flex size-7 items-center justify-center rounded-lg bg-white shadow-sm ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-700">
                                    <Upload className="h-4 w-4 text-indigo-600" />
                                </div>
                                Upload the WCBS extract
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-4 p-5">
                            <div
                                onDragOver={(e) => {
                                    e.preventDefault();
                                    setDragOver(true);
                                }}
                                onDragLeave={() => setDragOver(false)}
                                onDrop={(e) => {
                                    e.preventDefault();
                                    setDragOver(false);
                                    pickFile(e.dataTransfer.files?.[0]);
                                }}
                                onClick={() => fileInputRef.current?.click()}
                                className={cn(
                                    'flex cursor-pointer flex-col items-center gap-2 rounded-xl border-2 border-dashed px-6 py-6 text-center transition-all',
                                    dragOver
                                        ? 'border-indigo-500 bg-indigo-50/50 dark:bg-indigo-950/20'
                                        : 'border-slate-200 bg-slate-50/30 hover:border-indigo-400 hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-900/30',
                                )}
                            >
                                <FileSpreadsheet className="h-5 w-5 text-indigo-500" />
                                <p className="text-sm font-semibold text-slate-800 dark:text-slate-100">
                                    {file ? file.name : 'Drop the CSV here'}
                                </p>
                                <p className="text-xs text-slate-500">
                                    or{' '}
                                    <span className="font-semibold text-indigo-600">
                                        click to browse
                                    </span>{' '}
                                    — one row per student PER FEE TYPE
                                </p>
                                <input
                                    ref={fileInputRef}
                                    type="file"
                                    accept=".csv,text/csv"
                                    className="hidden"
                                    onChange={(e) => {
                                        pickFile(e.target.files?.[0]);
                                        e.target.value = '';
                                    }}
                                />
                            </div>

                            <div className="grid gap-4 md:grid-cols-2">
                                {/* THE ATTESTATION. The explanation is on the form, not only in the code. */}
                                <div className="md:col-span-2">
                                    <Label htmlFor="control-total">
                                        Control total (required)
                                    </Label>
                                    <Input
                                        id="control-total"
                                        inputMode="decimal"
                                        placeholder="e.g. 145000.00, or -5000.00 if the school is net in credit"
                                        value={controlTotal}
                                        onChange={(e) =>
                                            setControlTotal(e.target.value)
                                        }
                                    />
                                    <p className="mt-1 text-xs text-muted-foreground">
                                        <span className="font-semibold text-slate-700 dark:text-slate-200">
                                            Read this off WCBS's own report and
                                            type it here — do not copy it from
                                            the file you are uploading.
                                        </span>{' '}
                                        It is your attestation that the extract
                                        is complete. A total taken from the same
                                        export as the rows would agree with them
                                        even if a student had been dropped, so
                                        it would check nothing. Typing it is
                                        what makes it an independent witness.
                                    </p>
                                </div>

                                <div>
                                    <Label htmlFor="closing-term">
                                        Term being closed out
                                    </Label>
                                    <select
                                        id="closing-term"
                                        value={closingTermId}
                                        onChange={(e) =>
                                            setClosingTermId(
                                                Number(e.target.value),
                                            )
                                        }
                                        className="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-xs"
                                    >
                                        {terms.map((term) => (
                                            <option
                                                key={term.id}
                                                value={term.id}
                                            >
                                                {term.label}
                                            </option>
                                        ))}
                                    </select>
                                    <p className="mt-1 text-xs text-muted-foreground">
                                        The LAST term, whose closing position
                                        this file carries.
                                    </p>
                                </div>

                                <div>
                                    <Label htmlFor="as-at">Cutover date</Label>
                                    <Input
                                        id="as-at"
                                        type="date"
                                        value={asAt}
                                        onChange={(e) =>
                                            setAsAt(e.target.value)
                                        }
                                    />
                                </div>

                                <div className="md:col-span-2">
                                    <Label htmlFor="batch-reference">
                                        Batch reference
                                    </Label>
                                    <Input
                                        id="batch-reference"
                                        value={batchReference}
                                        onChange={(e) =>
                                            setBatchReference(e.target.value)
                                        }
                                    />
                                    <p className="mt-1 text-xs text-muted-foreground">
                                        Defaults to the filename. It is the key
                                        that stops one extract being imported
                                        twice, so a re-upload under the same
                                        reference is refused.
                                    </p>
                                </div>
                            </div>

                            <div className="flex justify-end border-t pt-3">
                                <Button
                                    size="sm"
                                    onClick={() => void upload()}
                                    disabled={!canUpload}
                                    className="rounded-lg bg-indigo-600 px-4 font-semibold text-white shadow-md hover:bg-indigo-700 disabled:opacity-50"
                                >
                                    {submitting ? (
                                        <Spinner className="mr-1.5 h-4 w-4 animate-spin" />
                                    ) : (
                                        <Upload className="mr-1.5 h-4 w-4" />
                                    )}
                                    Validate extract
                                </Button>
                            </div>
                        </CardContent>
                    </Card>

                    {/* ── The findings ───────────────────────────────────────── */}
                    {active && (
                        <Card className="overflow-hidden rounded-xl border-none shadow-[0_8px_30px_rgb(0,0,0,0.04)]">
                            <CardHeader className="flex flex-row items-center justify-between border-b border-slate-50 bg-slate-50/30 px-5 py-3">
                                <CardTitle className="text-sm font-bold text-slate-800 dark:text-slate-100">
                                    {active.batch_reference}
                                </CardTitle>
                                <span
                                    className={cn(
                                        'inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 text-[10px] font-semibold capitalize shadow-sm',
                                        statusChip(active.status),
                                    )}
                                >
                                    {statusIcon(active.status)}
                                    {active.status}
                                </span>
                            </CardHeader>
                            <CardContent className="space-y-4 p-5">
                                <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                                    <Stat
                                        label="Lines read"
                                        value={active.file_row_count}
                                    />
                                    <Stat
                                        label="Rows staged"
                                        value={active.row_count}
                                    />
                                    <Stat
                                        label="Rejected"
                                        value={active.rejected_rows.length}
                                    />
                                    <Stat
                                        label="Control total"
                                        value={
                                            active.control_total
                                                ? formatNaira(
                                                      active.control_total,
                                                  )
                                                : '—'
                                        }
                                    />
                                </div>

                                {active.status === 'draft' && (
                                    <p className="text-xs text-slate-500">
                                        <Loader2 className="mr-1 inline h-3 w-3 animate-spin" />
                                        Validating. This runs in the background
                                        — a real extract is a few thousand
                                        lines.
                                    </p>
                                )}

                                {/* Batch findings: facts about the FILE, not about any student. */}
                                {active.findings.length > 0 && (
                                    <div className="space-y-2">
                                        {active.findings.map((finding) => (
                                            <div
                                                key={
                                                    finding.code +
                                                    finding.message
                                                }
                                                className="flex items-start gap-2 rounded-lg border border-amber-200 bg-amber-50 p-3 text-xs text-amber-900 dark:border-amber-500/30 dark:bg-amber-950/30 dark:text-amber-100"
                                            >
                                                <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0" />
                                                <span>
                                                    <span className="font-mono font-semibold">
                                                        {finding.code}
                                                    </span>{' '}
                                                    — {finding.message}
                                                </span>
                                            </div>
                                        ))}
                                    </div>
                                )}

                                {active.rejected_rows.length > 0 && (
                                    <div className="custom-scrollbar max-h-96 overflow-auto rounded-lg border border-slate-100 dark:border-slate-800">
                                        <table className="w-full text-xs">
                                            <thead className="sticky top-0 bg-slate-50/90 backdrop-blur-sm dark:bg-slate-900/90">
                                                <tr>
                                                    <th className="px-3 py-2 text-left text-[10px] font-bold tracking-wide text-slate-400 uppercase">
                                                        Line
                                                    </th>
                                                    <th className="px-3 py-2 text-left text-[10px] font-bold tracking-wide text-slate-400 uppercase">
                                                        Admission #
                                                    </th>
                                                    <th className="px-3 py-2 text-left text-[10px] font-bold tracking-wide text-slate-400 uppercase">
                                                        Why it was rejected
                                                    </th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                {active.rejected_rows.map(
                                                    (row) => (
                                                        <tr
                                                            key={
                                                                row.line_number
                                                            }
                                                            className="border-t border-slate-100 dark:border-slate-800"
                                                        >
                                                            <td className="px-3 py-2 font-semibold tabular-nums">
                                                                {
                                                                    row.line_number
                                                                }
                                                            </td>
                                                            <td className="px-3 py-2 font-mono">
                                                                {row.admission_number ??
                                                                    '—'}
                                                            </td>
                                                            <td className="px-3 py-2 text-slate-600 dark:text-slate-300">
                                                                {row.findings.map(
                                                                    (f) => (
                                                                        <div
                                                                            key={
                                                                                f.code
                                                                            }
                                                                        >
                                                                            <span className="font-mono font-semibold">
                                                                                {
                                                                                    f.code
                                                                                }
                                                                            </span>{' '}
                                                                            —{' '}
                                                                            {
                                                                                f.message
                                                                            }
                                                                        </div>
                                                                    ),
                                                                )}
                                                            </td>
                                                        </tr>
                                                    ),
                                                )}
                                            </tbody>
                                        </table>
                                    </div>
                                )}

                                {active.rejected_rows_truncated && (
                                    <p className="text-xs text-amber-700 dark:text-amber-400">
                                        Only the first rejected rows are shown.
                                        Download the report for all of them.
                                    </p>
                                )}

                                <div className="flex flex-wrap items-center justify-end gap-2 border-t pt-3">
                                    <a
                                        href={openingBalanceImports.reportUrl(
                                            active.uuid,
                                        )}
                                    >
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            className="rounded-lg"
                                        >
                                            <Download className="mr-1.5 h-4 w-4" />
                                            Download report
                                        </Button>
                                    </a>
                                    <Button
                                        size="sm"
                                        onClick={() => void offerForApproval()}
                                        disabled={
                                            !active.can_submit || offering
                                        }
                                        title={
                                            active.can_submit
                                                ? undefined
                                                : 'Only a validated batch can be submitted for approval'
                                        }
                                        className="rounded-lg bg-emerald-600 font-semibold text-white hover:bg-emerald-700"
                                    >
                                        <Send className="mr-1.5 h-4 w-4" />
                                        Submit for approval
                                    </Button>
                                </div>
                            </CardContent>
                        </Card>
                    )}

                    {/* ── Recent uploads ─────────────────────────────────────── */}
                    <Card className="overflow-hidden rounded-xl border-none shadow-[0_8px_30px_rgb(0,0,0,0.04)]">
                        <CardHeader className="border-b border-slate-50 bg-slate-50/30 px-5 py-3">
                            <CardTitle className="text-sm font-bold text-slate-800 dark:text-slate-100">
                                Recent uploads
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="p-0">
                            {recent.length === 0 ? (
                                <p className="px-5 py-8 text-center text-xs text-slate-500">
                                    Nothing uploaded yet.
                                </p>
                            ) : (
                                <table className="w-full text-xs">
                                    <tbody>
                                        {recent.map((batch) => (
                                            <tr
                                                key={batch.uuid}
                                                className="border-t border-slate-100 first:border-t-0 dark:border-slate-800"
                                            >
                                                <td className="px-4 py-2.5 font-semibold text-slate-700 dark:text-slate-200">
                                                    {batch.batch_reference}
                                                </td>
                                                <td className="px-4 py-2.5 text-slate-500">
                                                    {batch.created_at
                                                        ? new Date(
                                                              batch.created_at,
                                                          ).toLocaleDateString()
                                                        : '—'}
                                                </td>
                                                <td className="px-4 py-2.5 text-right text-slate-500 tabular-nums">
                                                    {batch.row_count} row(s)
                                                </td>
                                                <td className="px-4 py-2.5 text-right">
                                                    <span
                                                        className={cn(
                                                            'inline-flex rounded-full px-2 py-0.5 text-[10px] font-semibold capitalize',
                                                            statusChip(
                                                                batch.status,
                                                            ),
                                                        )}
                                                    >
                                                        {batch.status}
                                                    </span>
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            )}
                        </CardContent>
                    </Card>
                </div>
            </div>
        </>
    );
}

OpeningBalanceImport.layout = {
    breadcrumbs: [
        { title: 'Dashboard', href: '/dashboard' },
        { title: 'Finance', href: '/finance' },
        {
            title: 'Opening balances',
            href: '/finance/opening-balances/import',
        },
    ],
};
