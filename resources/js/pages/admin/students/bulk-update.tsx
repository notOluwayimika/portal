import { Head } from '@inertiajs/react';
import {
    AlertTriangle,
    BookOpen,
    CheckCircle2,
    Clock,
    Download,
    FileSpreadsheet,
    History,
    Info,
    Loader2,
    Upload,
    UploadCloud,
    XCircle,
} from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';
import { toast } from 'sonner';
import Modal from '@/components/ui/Modal';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Spinner } from '@/components/ui/spinner';
import { STUDENT_BULK_UPDATE_COLUMNS } from '@/constants/student-bulk-update-columns';
import { useExcelImport } from '@/hooks/use-excel-import';
import { cn } from '@/lib/utils';
import {
    studentBulkUpdate,
    type StudentBulkUpdateRecord,
} from '@/services/student-bulk-update';

type Row = Record<string, unknown>;

const REQUIRED_COLUMNS = STUDENT_BULK_UPDATE_COLUMNS.filter((c) => c.required).map((c) => c.name);

const PREVIEW_COLUMNS: { key: string; label: string }[] = [
    { key: 'code',            label: 'Code' },
    { key: 'admission_date',  label: 'Admission Date' },
    { key: 'sport_house',     label: 'Sport House' },
    { key: 'scholarship',     label: 'Scholarship' },
    { key: 'nationality',     label: 'Nationality' },
    { key: 'state_of_origin', label: 'State of Origin' },
    { key: 'religion',        label: 'Religion' },
    { key: 'previous_school', label: 'Previous School' },
    { key: 'address',         label: 'Address' },
];

function validateRowClientSide(row: Row): string[] {
    const errors: string[] = [];
    for (const col of REQUIRED_COLUMNS) {
        const v = row[col];
        if (v === undefined || v === null || String(v).trim() === '') {
            errors.push(`${col} is required`);
        }
    }
    return errors;
}

function statusBadge(status: StudentBulkUpdateRecord['status']) {
    switch (status) {
        case 'completed':
            return 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-400';
        case 'failed':
            return 'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-400';
        case 'processing':
            return 'bg-indigo-100 text-indigo-700 dark:bg-indigo-900/40 dark:text-indigo-400';
        case 'queued':
        default:
            return 'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-400';
    }
}

function statusIcon(status: StudentBulkUpdateRecord['status']) {
    switch (status) {
        case 'completed': return <CheckCircle2 className="h-3.5 w-3.5" />;
        case 'failed':    return <XCircle      className="h-3.5 w-3.5" />;
        case 'processing':return <Loader2      className="h-3.5 w-3.5 animate-spin" />;
        case 'queued':
        default:          return <Clock        className="h-3.5 w-3.5" />;
    }
}

function SummaryStat({
    label, value, accent,
}: { label: string; value: number; accent: 'emerald' | 'red' | 'amber' | 'indigo' }) {
    const colors = {
        emerald: 'text-emerald-600 dark:text-emerald-400',
        red:     'text-red-600 dark:text-red-400',
        amber:   'text-amber-600 dark:text-amber-400',
        indigo:  'text-indigo-600 dark:text-indigo-400',
    }[accent];
    return (
        <div className="rounded-xl border border-slate-100 bg-white px-4 py-3 shadow-sm dark:border-white/5 dark:bg-card">
            <p className="text-[10px] font-bold tracking-wide text-slate-400 uppercase">{label}</p>
            <p className={cn('mt-1 text-2xl font-extrabold tracking-tight', colors)}>{value}</p>
        </div>
    );
}

export default function StudentBulkUpdate() {
    const fileInputRef = useRef<HTMLInputElement>(null);
    const { importExcelData } = useExcelImport();

    const [selectedFile, setSelectedFile] = useState<File | null>(null);
    const [previewData, setPreviewData]   = useState<Row[]>([]);
    const [showColumns, setShowColumns]   = useState(false);
    const [dragOver, setDragOver]         = useState(false);

    const [submitting, setSubmitting]     = useState(false);
    const [active, setActive]             = useState<StudentBulkUpdateRecord | null>(null);
    const [recent, setRecent]             = useState<StudentBulkUpdateRecord[]>([]);

    const clientErrors = useMemo(() => {
        const map: Record<number, string[]> = {};
        previewData.forEach((row, i) => {
            const errs = validateRowClientSide(row);
            if (errs.length) map[i] = errs;
        });
        return map;
    }, [previewData]);

    const clientErrorCount = Object.keys(clientErrors).length;
    const isInFlight = active && (active.status === 'queued' || active.status === 'processing');
    const progressPct = active && active.total_rows > 0
        ? Math.min(100, Math.round((active.processed_rows / active.total_rows) * 100))
        : 0;

    useEffect(() => { void refreshRecent(); }, []);

    useEffect(() => {
        if (!active || active.status === 'completed' || active.status === 'failed') return;
        const id = setInterval(async () => {
            try {
                const fresh = await studentBulkUpdate.status(active.uuid);
                setActive(fresh);
                if (fresh.status === 'completed' || fresh.status === 'failed') {
                    clearInterval(id);
                    void refreshRecent();
                    if (fresh.status === 'completed') {
                        toast.success(`Update complete: ${fresh.succeeded} updated, ${fresh.failed} failed, ${fresh.skipped} skipped.`);
                    } else {
                        toast.error(`Update failed: ${fresh.error ?? 'unknown error'}`);
                    }
                }
            } catch { /* keep polling */ }
        }, 2000);
        return () => clearInterval(id);
    }, [active?.uuid, active?.status]);

    const refreshRecent = async () => {
        try {
            setRecent(await studentBulkUpdate.list(10));
        } catch { /* non-fatal */ }
    };

    const handleFileChange = (e: React.ChangeEvent<HTMLInputElement>) => {
        importExcelData(e, ({ file, jsonData }) => {
            setSelectedFile(file);
            setPreviewData(jsonData);
        });
        e.target.value = '';
    };

    const handleDrop = (e: React.DragEvent<HTMLDivElement>) => {
        e.preventDefault();
        setDragOver(false);
        const file = e.dataTransfer.files?.[0];
        if (!file) return;
        const dt = new DataTransfer();
        dt.items.add(file);
        if (fileInputRef.current) {
            fileInputRef.current.files = dt.files;
            const synthetic = { target: fileInputRef.current } as unknown as React.ChangeEvent<HTMLInputElement>;
            handleFileChange(synthetic);
        }
    };

    const handleSubmit = async () => {
        if (!selectedFile) return;
        if (clientErrorCount > 0) {
            const ok = window.confirm(
                `${clientErrorCount} row(s) have client-side errors and may be rejected by the server. Continue anyway?`
            );
            if (!ok) return;
        }

        setSubmitting(true);
        try {
            const imported = await studentBulkUpdate.submit(selectedFile);
            setActive(imported);
            setPreviewData([]);
            setSelectedFile(null);
            void refreshRecent();
            if (imported.status === 'completed') {
                toast.success(`Update complete: ${imported.succeeded} updated, ${imported.failed} failed, ${imported.skipped} skipped.`);
            } else {
                toast.message('Update queued. Processing in the background...');
            }
        } catch (err: any) {
            const message = err?.response?.data?.message ?? 'Update failed. Please try again.';
            toast.error(message);
        } finally {
            setSubmitting(false);
        }
    };

    return (
        <>
            <Head title="Bulk Update Students" />

            <div className="min-h-screen bg-[#f5f7fb] py-5 px-4 sm:px-6 lg:px-8 dark:bg-background">
                <div className="mx-auto max-w-7xl space-y-5">

                    {/* Hero Card */}
                    <div className="relative overflow-hidden rounded-2xl border border-white bg-white px-6 py-4 shadow-[0_8px_30px_rgb(0,0,0,0.04)] dark:border-white/5 dark:bg-card">
                        <div className="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                            <div className="flex items-center gap-4">
                                <div className="flex size-12 shrink-0 items-center justify-center rounded-xl bg-gradient-to-br from-indigo-50 to-violet-50 shadow-sm ring-1 ring-black/5 dark:from-indigo-950/50 dark:to-violet-950/50">
                                    <UploadCloud className="h-6 w-6 text-indigo-600" />
                                </div>
                                <div>
                                    <h1 className="text-xl font-extrabold tracking-tight text-slate-900 dark:text-white">
                                        Bulk Student Update
                                    </h1>
                                    <p className="max-w-xl text-xs text-slate-500">
                                        Update multiple student records at once using a spreadsheet. Students are matched by
                                        their admission number (Code column). Only non-empty columns are updated.
                                    </p>
                                </div>
                            </div>

                            <div className="flex shrink-0 flex-wrap items-center gap-2">
                                <a href={studentBulkUpdate.templateUrl()} download>
                                    <Button size="sm" className="rounded-lg bg-indigo-600 px-4 font-semibold text-white shadow-md transition-all hover:bg-indigo-700 hover:shadow-lg active:scale-95">
                                        <Download className="mr-1.5 h-4 w-4" />
                                        Download Template
                                    </Button>
                                </a>
                                <Button
                                    size="sm"
                                    variant="outline"
                                    onClick={() => setShowColumns(true)}
                                    className="rounded-lg border-slate-200 font-semibold text-slate-700 transition-all hover:bg-slate-50 hover:text-slate-900 dark:text-slate-200 dark:hover:bg-slate-800 dark:hover:text-white"
                                >
                                    <BookOpen className="mr-1.5 h-4 w-4" />
                                    Column Reference
                                </Button>
                            </div>
                        </div>
                    </div>

                    {/* Info notice */}
                    <div className="flex items-center gap-2.5 rounded-xl border border-blue-200 bg-blue-50 px-4 py-2.5 text-xs text-blue-800 dark:border-blue-500/30 dark:bg-blue-950/30 dark:text-blue-200">
                        <Info className="h-4 w-4 shrink-0 text-blue-600 dark:text-blue-300" />
                        <span>
                            <span className="font-semibold">Only filled columns are updated.</span>{' '}
                            Leave a column blank to keep the existing value. The "Code" column is required to identify each student.
                            After the update, download the report to see which rows failed and why.
                        </span>
                    </div>

                    {/* Active Update Card */}
                    {active && (
                        <Card className="overflow-hidden rounded-xl border-none shadow-[0_8px_30px_rgb(0,0,0,0.04)]">
                            <CardHeader className="flex flex-row items-center justify-between border-b border-slate-50 bg-slate-50/30 px-5 py-3">
                                <CardTitle className="flex items-center gap-2.5 text-sm font-bold text-slate-800 dark:text-slate-100">
                                    <div className="flex size-7 items-center justify-center rounded-lg bg-white shadow-sm ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-700">
                                        <FileSpreadsheet className="h-4 w-4 text-indigo-600" />
                                    </div>
                                    Current Update
                                </CardTitle>
                                <Badge className={cn('rounded-full px-2.5 py-0.5 text-[10px] font-semibold capitalize shadow-sm inline-flex items-center gap-1', statusBadge(active.status))}>
                                    {statusIcon(active.status)}
                                    {active.status}
                                </Badge>
                            </CardHeader>
                            <CardContent className="space-y-4 p-5">
                                <div>
                                    <p className="text-[10px] font-bold tracking-wide text-slate-400 uppercase">File</p>
                                    <p className="mt-0.5 text-sm font-semibold text-slate-700 dark:text-slate-200">{active.file_name}</p>
                                </div>

                                {isInFlight && (
                                    <div className="space-y-2">
                                        <div className="flex items-center justify-between text-xs">
                                            <span className="font-medium text-slate-600 dark:text-slate-300">
                                                Processing row {active.processed_rows} of {active.total_rows}
                                            </span>
                                            <span className="font-semibold text-indigo-600">{progressPct}%</span>
                                        </div>
                                        <div className="h-1.5 w-full overflow-hidden rounded-full bg-slate-100 dark:bg-slate-800">
                                            <div
                                                className="h-full rounded-full bg-gradient-to-r from-indigo-500 to-violet-500 transition-all"
                                                style={{ width: `${progressPct}%` }}
                                            />
                                        </div>
                                    </div>
                                )}

                                {active.status === 'completed' && (
                                    <>
                                        <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                                            <SummaryStat label="Total"   value={active.total_rows} accent="indigo" />
                                            <SummaryStat label="Updated" value={active.succeeded}  accent="emerald" />
                                            <SummaryStat label="Failed"  value={active.failed}     accent="red" />
                                            <SummaryStat label="Skipped" value={active.skipped}    accent="amber" />
                                        </div>
                                        {active.has_report && (
                                            <a href={studentBulkUpdate.reportUrl(active.uuid)} className="inline-block">
                                                <Button size="sm" variant="outline" className="rounded-lg border-slate-200 font-semibold text-slate-700 hover:bg-slate-50">
                                                    <Download className="mr-1.5 h-4 w-4" />
                                                    Download per-row report
                                                </Button>
                                            </a>
                                        )}
                                    </>
                                )}

                                {active.status === 'failed' && active.error && (
                                    <div className="rounded-lg border border-destructive/30 bg-destructive/10 p-3 text-xs text-destructive">
                                        {active.error}
                                    </div>
                                )}
                            </CardContent>
                        </Card>
                    )}

                    {/* Upload Card */}
                    <Card className="overflow-hidden rounded-xl border-none shadow-[0_8px_30px_rgb(0,0,0,0.04)]">
                        <CardHeader className="border-b border-slate-50 bg-slate-50/30 px-5 py-3">
                            <CardTitle className="flex items-center gap-2.5 text-sm font-bold text-slate-800 dark:text-slate-100">
                                <div className="flex size-7 items-center justify-center rounded-lg bg-white shadow-sm ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-700">
                                    <Upload className="h-4 w-4 text-indigo-600" />
                                </div>
                                Upload Spreadsheet
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-4 p-5">
                            <div
                                onDragOver={(e) => { e.preventDefault(); setDragOver(true); }}
                                onDragLeave={() => setDragOver(false)}
                                onDrop={handleDrop}
                                onClick={() => fileInputRef.current?.click()}
                                className={cn(
                                    'flex cursor-pointer flex-col items-center gap-2 rounded-xl border-2 border-dashed px-6 py-6 text-center transition-all',
                                    dragOver
                                        ? 'border-indigo-500 bg-indigo-50/50 dark:bg-indigo-950/20'
                                        : 'border-slate-200 bg-slate-50/30 hover:border-indigo-400 hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-900/30 dark:hover:bg-slate-900/50',
                                )}
                            >
                                <div className="flex size-10 items-center justify-center rounded-xl bg-white shadow-sm ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-700">
                                    <UploadCloud className="h-5 w-5 text-indigo-500" />
                                </div>
                                <p className="text-sm font-semibold text-slate-800 dark:text-slate-100">
                                    {selectedFile ? selectedFile.name : 'Drag & drop your spreadsheet here'}
                                </p>
                                <p className="text-xs text-slate-500">
                                    or <span className="font-semibold text-indigo-600">click to browse</span> — supports .xls, .xlsx, .csv
                                </p>
                                <input
                                    ref={fileInputRef}
                                    type="file"
                                    accept=".xls,.xlsx,.csv"
                                    className="hidden"
                                    onChange={handleFileChange}
                                />
                            </div>

                            <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-end">
                                <Button
                                    type="button"
                                    size="sm"
                                    onClick={handleSubmit}
                                    disabled={!selectedFile || submitting}
                                    className="rounded-lg bg-indigo-600 px-4 font-semibold text-white shadow-md transition-all hover:bg-indigo-700 hover:shadow-lg active:scale-95 disabled:opacity-50"
                                >
                                    {submitting ? <Spinner className="mr-1.5 h-4 w-4 animate-spin" /> : <Upload className="mr-1.5 h-4 w-4" />}
                                    {previewData.length > 0 ? `Update ${previewData.length} Student(s)` : 'Update'}
                                </Button>
                            </div>

                            {/* Client-side errors */}
                            {clientErrorCount > 0 && (
                                <div className="rounded-lg border border-destructive/30 bg-destructive/10 p-3 text-xs text-destructive">
                                    <div className="flex items-start gap-2">
                                        <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0" />
                                        <div className="flex-1">
                                            <p className="mb-1.5 font-semibold">{clientErrorCount} row(s) have missing required fields</p>
                                            <table className="w-full text-xs">
                                                <tbody>
                                                    {Object.entries(clientErrors).slice(0, 10).map(([idx, msgs]) => (
                                                        <tr key={idx}>
                                                            <td className="w-16 py-0.5 align-top font-medium">Row {Number(idx) + 1}</td>
                                                            <td className="py-0.5">{msgs.join(', ')}</td>
                                                        </tr>
                                                    ))}
                                                </tbody>
                                            </table>
                                            {Object.keys(clientErrors).length > 10 && (
                                                <p className="mt-1.5 text-xs opacity-80">
                                                    Showing 10 of {Object.keys(clientErrors).length} — see preview below.
                                                </p>
                                            )}
                                        </div>
                                    </div>
                                </div>
                            )}
                        </CardContent>
                    </Card>

                    {/* Preview Card */}
                    {previewData.length > 0 && (
                        <Card className="overflow-hidden rounded-xl border-none shadow-[0_8px_30px_rgb(0,0,0,0.04)]">
                            <CardHeader className="flex flex-row items-center justify-between border-b border-slate-50 bg-slate-50/30 px-5 py-3">
                                <CardTitle className="flex items-center gap-2.5 text-sm font-bold text-slate-800 dark:text-slate-100">
                                    <div className="flex size-7 items-center justify-center rounded-lg bg-white shadow-sm ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-700">
                                        <FileSpreadsheet className="h-4 w-4 text-indigo-600" />
                                    </div>
                                    Preview
                                </CardTitle>
                                <Badge className="rounded-full bg-indigo-50 px-2.5 py-0.5 text-[10px] font-semibold text-indigo-600 shadow-sm hover:bg-indigo-50 dark:bg-indigo-500/10 dark:text-indigo-400">
                                    {previewData.length} row{previewData.length === 1 ? '' : 's'}
                                </Badge>
                            </CardHeader>
                            <CardContent className="p-0">
                                <div className="max-h-80 overflow-auto custom-scrollbar">
                                    <table className="w-full text-xs">
                                        <thead className="sticky top-0 z-10 bg-slate-50/80 backdrop-blur-sm dark:bg-slate-900/80">
                                            <tr>
                                                <th className="px-3 py-2 text-left text-[10px] font-bold tracking-wide text-slate-400 uppercase">#</th>
                                                {PREVIEW_COLUMNS.map((c) => (
                                                    <th key={c.key} className="px-3 py-2 text-left text-[10px] font-bold tracking-wide text-slate-400 uppercase">
                                                        {c.label}
                                                    </th>
                                                ))}
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {previewData.map((row, i) => {
                                                const hasError = !!clientErrors[i];
                                                return (
                                                    <tr
                                                        key={i}
                                                        className={cn(
                                                            'border-t border-slate-100 dark:border-slate-800',
                                                            hasError
                                                                ? 'bg-destructive/5 text-destructive'
                                                                : 'hover:bg-slate-50/50 dark:hover:bg-slate-900/30',
                                                        )}
                                                    >
                                                        <td className="px-3 py-2 font-semibold">{i + 1}</td>
                                                        {PREVIEW_COLUMNS.map((c) => (
                                                            <td key={c.key} className="px-3 py-2">
                                                                {row[c.key] === undefined || row[c.key] === null || row[c.key] === ''
                                                                    ? <span className="text-slate-300">&mdash;</span>
                                                                    : String(row[c.key])}
                                                            </td>
                                                        ))}
                                                    </tr>
                                                );
                                            })}
                                        </tbody>
                                    </table>
                                </div>
                            </CardContent>
                        </Card>
                    )}

                    {/* Recent Updates Card */}
                    <Card className="overflow-hidden rounded-xl border-none shadow-[0_8px_30px_rgb(0,0,0,0.04)]">
                        <CardHeader className="border-b border-slate-50 bg-slate-50/30 px-5 py-3">
                            <CardTitle className="flex items-center gap-2.5 text-sm font-bold text-slate-800 dark:text-slate-100">
                                <div className="flex size-7 items-center justify-center rounded-lg bg-white shadow-sm ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-700">
                                    <History className="h-4 w-4 text-indigo-600" />
                                </div>
                                Recent Updates
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="p-0">
                            {recent.length === 0 ? (
                                <div className="flex flex-col items-center justify-center py-10 text-center">
                                    <div className="mb-3 flex size-14 items-center justify-center rounded-full bg-slate-50 text-slate-300 dark:bg-slate-900">
                                        <History className="h-7 w-7" />
                                    </div>
                                    <h3 className="text-sm font-semibold text-slate-900 dark:text-slate-100">No updates yet</h3>
                                    <p className="mt-1 max-w-[280px] text-xs text-slate-500">
                                        Bulk updates you run will appear here with status, counts, and a downloadable report.
                                    </p>
                                </div>
                            ) : (
                                <div className="overflow-x-auto custom-scrollbar">
                                    <table className="w-full text-xs">
                                        <thead className="bg-slate-50/50 dark:bg-slate-900/30">
                                            <tr>
                                                <th className="px-4 py-2 text-left text-[10px] font-bold tracking-wide text-slate-400 uppercase">File</th>
                                                <th className="px-4 py-2 text-left text-[10px] font-bold tracking-wide text-slate-400 uppercase">Date</th>
                                                <th className="px-4 py-2 text-left text-[10px] font-bold tracking-wide text-slate-400 uppercase">Status</th>
                                                <th className="px-4 py-2 text-right text-[10px] font-bold tracking-wide text-slate-400 uppercase">Updated</th>
                                                <th className="px-4 py-2 text-right text-[10px] font-bold tracking-wide text-slate-400 uppercase">Failed</th>
                                                <th className="px-4 py-2 text-right text-[10px] font-bold tracking-wide text-slate-400 uppercase">Skipped</th>
                                                <th className="px-4 py-2 text-right text-[10px] font-bold tracking-wide text-slate-400 uppercase">Report</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {recent.map((r) => (
                                                <tr key={r.uuid} className="border-t border-slate-100 hover:bg-slate-50/40 dark:border-slate-800 dark:hover:bg-slate-900/30">
                                                    <td className="px-4 py-2.5 font-semibold text-slate-700 dark:text-slate-200">
                                                        <div className="flex items-center gap-2">
                                                            <FileSpreadsheet className="h-3.5 w-3.5 text-slate-400" />
                                                            <span className="max-w-[220px] truncate">{r.file_name}</span>
                                                        </div>
                                                    </td>
                                                    <td className="px-4 py-2.5 text-slate-500">
                                                        {r.created_at ? new Date(r.created_at).toLocaleString() : '—'}
                                                    </td>
                                                    <td className="px-4 py-2.5">
                                                        <Badge className={cn('rounded-full px-2 py-0.5 text-[10px] font-semibold capitalize shadow-sm inline-flex items-center gap-1', statusBadge(r.status))}>
                                                            {statusIcon(r.status)}
                                                            {r.status}
                                                        </Badge>
                                                    </td>
                                                    <td className="px-4 py-2.5 text-right font-semibold text-emerald-600">{r.succeeded}</td>
                                                    <td className="px-4 py-2.5 text-right font-semibold text-red-600">{r.failed}</td>
                                                    <td className="px-4 py-2.5 text-right font-semibold text-amber-600">{r.skipped}</td>
                                                    <td className="px-4 py-2.5 text-right">
                                                        {r.has_report ? (
                                                            <a
                                                                href={studentBulkUpdate.reportUrl(r.uuid)}
                                                                className="inline-flex items-center gap-1 font-semibold text-indigo-600 hover:underline"
                                                            >
                                                                <Download className="h-3 w-3" />
                                                                Download
                                                            </a>
                                                        ) : (
                                                            <span className="text-slate-300">&mdash;</span>
                                                        )}
                                                    </td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            )}
                        </CardContent>
                    </Card>
                </div>
            </div>

            {/* Column Reference Modal */}
            <Modal
                isOpen={showColumns}
                onClose={() => setShowColumns(false)}
                title="Bulk Update Column Reference"
                size="4xl"
            >
                <div className="space-y-3">
                    <div className="flex items-start gap-3 rounded-xl border border-indigo-200 bg-indigo-50 p-3 text-xs text-indigo-800 dark:border-indigo-500/30 dark:bg-indigo-950/30 dark:text-indigo-200">
                        <Info className="mt-0.5 h-4 w-4 shrink-0" />
                        <span>
                            Only the <strong>Code</strong> column is required. All other columns are optional &mdash;
                            leave them blank to keep the existing value. Column headers are matched case-insensitively
                            and spaces are converted to underscores.
                        </span>
                    </div>
                    <div className="max-h-[60vh] overflow-auto custom-scrollbar rounded-xl border border-slate-100 dark:border-slate-800">
                        <table className="w-full text-sm">
                            <thead className="sticky top-0 bg-slate-50/80 backdrop-blur-sm dark:bg-slate-900/80">
                                <tr>
                                    <th className="px-3 py-2 text-left text-xs font-bold tracking-wide text-slate-400 uppercase">Column</th>
                                    <th className="px-3 py-2 text-left text-xs font-bold tracking-wide text-slate-400 uppercase">Group</th>
                                    <th className="px-3 py-2 text-left text-xs font-bold tracking-wide text-slate-400 uppercase">Required</th>
                                    <th className="px-3 py-2 text-left text-xs font-bold tracking-wide text-slate-400 uppercase">Format</th>
                                    <th className="px-3 py-2 text-left text-xs font-bold tracking-wide text-slate-400 uppercase">Example</th>
                                    <th className="px-3 py-2 text-left text-xs font-bold tracking-wide text-slate-400 uppercase">Notes</th>
                                </tr>
                            </thead>
                            <tbody>
                                {STUDENT_BULK_UPDATE_COLUMNS.map((c) => (
                                    <tr key={c.name} className="border-t border-slate-100 dark:border-slate-800">
                                        <td className="px-3 py-2 font-semibold text-slate-700 dark:text-slate-200">{c.name}</td>
                                        <td className="px-3 py-2 text-slate-500">{c.group}</td>
                                        <td className="px-3 py-2">
                                            {c.required ? (
                                                <Badge className="rounded-full bg-red-100 px-2 py-0 text-[10px] font-semibold text-red-700 shadow-sm hover:bg-red-100 dark:bg-red-900/40 dark:text-red-400">
                                                    Required
                                                </Badge>
                                            ) : (
                                                <span className="text-xs text-slate-400">Optional</span>
                                            )}
                                        </td>
                                        <td className="px-3 py-2"><code className="rounded bg-slate-100 px-1.5 py-0.5 text-xs dark:bg-slate-800">{c.format}</code></td>
                                        <td className="px-3 py-2"><code className="rounded bg-slate-100 px-1.5 py-0.5 text-xs dark:bg-slate-800">{c.example}</code></td>
                                        <td className="px-3 py-2 text-xs text-slate-500">{c.notes || '—'}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </div>
            </Modal>
        </>
    );
}

StudentBulkUpdate.layout = {
    breadcrumbs: [
        { title: 'Dashboard', href: '/dashboard' },
        { title: 'Students', href: '/students' },
        { title: 'Bulk Update', href: '/students/bulk-update' },
    ],
};
