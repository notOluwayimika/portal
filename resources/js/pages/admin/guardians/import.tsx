import { Head } from '@inertiajs/react';
import { AlertTriangle, Download, FileText, Loader2, Upload } from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';
import { toast } from 'sonner';
import Modal from '@/components/ui/Modal';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Spinner } from '@/components/ui/spinner';
import { GUARDIAN_IMPORT_COLUMNS } from '@/constants/guardian-import-columns';
import { useExcelImport } from '@/hooks/use-excel-import';
import { cn } from '@/lib/utils';
import {
    guardianImports,
    type GuardianImportRecord,
} from '@/services/guardian-imports';

type Row = Record<string, unknown>;

const REQUIRED_COLUMNS = GUARDIAN_IMPORT_COLUMNS.filter((c) => c.required).map((c) => c.name);
const PREVIEW_COLUMNS: { key: string; label: string }[] = [
    { key: 'admission_number', label: 'Admission #' },
    { key: 'first_name',       label: 'First Name' },
    { key: 'last_name',        label: 'Last Name' },
    { key: 'phone',            label: 'Phone' },
    { key: 'email',            label: 'Email' },
    { key: 'relationship',     label: 'Relationship' },
    { key: 'is_primary',       label: 'Primary' },
    { key: 'can_login',        label: 'Can Login' },
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

export default function GuardianImport() {
    const fileInputRef = useRef<HTMLInputElement>(null);
    const { importExcelData } = useExcelImport();

    const [selectedFile, setSelectedFile] = useState<File | null>(null);
    const [previewData, setPreviewData]   = useState<Row[]>([]);
    const [updateExistingLinks, setUpdateExistingLinks] = useState(false);
    const [showColumns, setShowColumns]   = useState(false);

    const [submitting, setSubmitting]     = useState(false);
    const [active, setActive]             = useState<GuardianImportRecord | null>(null);
    const [recent, setRecent]             = useState<GuardianImportRecord[]>([]);

    const clientErrors = useMemo(() => {
        const map: Record<number, string[]> = {};
        previewData.forEach((row, i) => {
            const errs = validateRowClientSide(row);
            if (errs.length) map[i] = errs;
        });
        return map;
    }, [previewData]);

    const clientErrorCount = Object.keys(clientErrors).length;

    useEffect(() => { void refreshRecent(); }, []);

    // Poll while active import is in flight.
    useEffect(() => {
        if (!active || active.status === 'completed' || active.status === 'failed') return;
        const id = setInterval(async () => {
            try {
                const fresh = await guardianImports.status(active.uuid);
                setActive(fresh);
                if (fresh.status === 'completed' || fresh.status === 'failed') {
                    clearInterval(id);
                    void refreshRecent();
                    if (fresh.status === 'completed') {
                        toast.success(`Import complete: ${fresh.succeeded} succeeded, ${fresh.failed} failed, ${fresh.skipped} skipped.`);
                    } else {
                        toast.error(`Import failed: ${fresh.error ?? 'unknown error'}`);
                    }
                }
            } catch { /* keep polling */ }
        }, 2000);
        return () => clearInterval(id);
    }, [active?.uuid, active?.status]);

    const refreshRecent = async () => {
        try {
            setRecent(await guardianImports.list(10));
        } catch { /* non-fatal */ }
    };

    const handleFileChange = (e: React.ChangeEvent<HTMLInputElement>) => {
        importExcelData(e, ({ file, jsonData }) => {
            setSelectedFile(file);
            setPreviewData(jsonData);
        });
        e.target.value = '';
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
            const imported = await guardianImports.submit(selectedFile, updateExistingLinks);
            setActive(imported);
            setPreviewData([]);
            setSelectedFile(null);
            void refreshRecent();
            if (imported.status === 'completed') {
                toast.success(`Import complete: ${imported.succeeded} succeeded, ${imported.failed} failed, ${imported.skipped} skipped.`);
            } else {
                toast.message('Import queued. We will email you when it finishes.');
            }
        } catch (err: any) {
            const message = err?.response?.data?.message ?? 'Import failed. Please try again.';
            toast.error(message);
        } finally {
            setSubmitting(false);
        }
    };

    return (
        <>
            <Head title="Import Guardians" />

            <div className="flex h-full flex-1 flex-col gap-6 p-4 pb-20">
                {/* Header */}
                <div className="flex items-center justify-between">
                    <div>
                        <h1 className="text-2xl font-bold">Import Guardians</h1>
                        <p className="text-sm text-muted-foreground">
                            Bulk-onboard guardians from a spreadsheet and link them to students.
                        </p>
                    </div>
                    <div className="flex gap-2">
                        <a href={guardianImports.templateUrl()} download>
                            <Button variant="outline" type="button">
                                <Download className="mr-1 h-4 w-4" />
                                Download Template
                            </Button>
                        </a>
                        <Button variant="outline" type="button" onClick={() => setShowColumns(true)}>
                            <FileText className="mr-1 h-4 w-4" />
                            View column descriptions
                        </Button>
                    </div>
                </div>

                {/* Photo banner */}
                <div className="rounded-md border border-amber-300/40 bg-amber-50 p-3 text-sm dark:bg-amber-950/30">
                    Photos are not included in bulk import. Add guardian photos individually via the guardian profile page after import.
                </div>

                {/* Upload area */}
                <div className="rounded-lg border bg-background shadow-sm">
                    <div className="flex flex-col items-center gap-3 p-8 text-center">
                        <Upload className="h-8 w-8 text-muted-foreground" />
                        <p className="text-sm text-muted-foreground">
                            {selectedFile ? selectedFile.name : 'Select an Excel or CSV file (.xls, .xlsx, .csv)'}
                        </p>
                        <Button type="button" variant="secondary" onClick={() => fileInputRef.current?.click()}>
                            Browse File
                        </Button>
                        <input
                            ref={fileInputRef}
                            type="file"
                            accept=".xls,.xlsx,.csv"
                            className="hidden"
                            onChange={handleFileChange}
                        />
                    </div>

                    <div className="flex items-center justify-between gap-4 border-t px-6 py-3 text-sm">
                        <label className="flex items-center gap-2">
                            <Checkbox
                                checked={updateExistingLinks}
                                onCheckedChange={(v) => setUpdateExistingLinks(Boolean(v))}
                            />
                            <span>Update existing student-guardian links if found</span>
                        </label>
                        <Button
                            type="button"
                            onClick={handleSubmit}
                            disabled={!selectedFile || submitting}
                        >
                            {submitting ? <Spinner className="mr-2 h-4 w-4 animate-spin" /> : <Upload className="mr-2 h-4 w-4" />}
                            {previewData.length > 0 ? `Import ${previewData.length} Row(s)` : 'Import'}
                        </Button>
                    </div>
                </div>

                {/* Client-side errors */}
                {clientErrorCount > 0 && (
                    <div className="rounded-md border border-destructive/30 bg-destructive/10 p-3 text-sm text-destructive">
                        <div className="flex items-start gap-2">
                            <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0" />
                            <div>
                                <p className="mb-1 font-medium">{clientErrorCount} row(s) have missing required fields:</p>
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
                            </div>
                        </div>
                    </div>
                )}

                {/* Preview */}
                {previewData.length > 0 && (
                    <div className="rounded-md border">
                        <div className="border-b bg-muted/50 px-4 py-2 text-sm font-medium">
                            Preview ({previewData.length} row{previewData.length === 1 ? '' : 's'})
                        </div>
                        <div className="max-h-72 overflow-auto">
                            <table className="w-full text-sm">
                                <thead className="sticky top-0 bg-muted/90 backdrop-blur-sm">
                                    <tr>
                                        <th className="px-3 py-2 text-left text-xs font-medium">S/N</th>
                                        {PREVIEW_COLUMNS.map((c) => (
                                            <th key={c.key} className="px-3 py-2 text-left text-xs font-medium">{c.label}</th>
                                        ))}
                                    </tr>
                                </thead>
                                <tbody>
                                    {previewData.map((row, i) => {
                                        const hasError = !!clientErrors[i];
                                        return (
                                            <tr
                                                key={i}
                                                className={cn('border-t', hasError && 'bg-destructive/10 text-destructive')}
                                            >
                                                <td className="px-3 py-2">{i + 1}</td>
                                                {PREVIEW_COLUMNS.map((c) => (
                                                    <td key={c.key} className="px-3 py-2">
                                                        {row[c.key] === undefined || row[c.key] === null || row[c.key] === ''
                                                            ? '-'
                                                            : String(row[c.key])}
                                                    </td>
                                                ))}
                                            </tr>
                                        );
                                    })}
                                </tbody>
                            </table>
                        </div>
                    </div>
                )}

                {/* Active import status */}
                {active && (
                    <div className="rounded-md border bg-background p-4 shadow-sm">
                        <div className="mb-3 flex items-center justify-between">
                            <div>
                                <h2 className="font-semibold">Current Import</h2>
                                <p className="text-xs text-muted-foreground">{active.file_name}</p>
                            </div>
                            <span className={cn(
                                'rounded-full px-2 py-0.5 text-xs font-medium',
                                active.status === 'completed' && 'bg-emerald-100 text-emerald-800',
                                active.status === 'failed' && 'bg-destructive/15 text-destructive',
                                (active.status === 'queued' || active.status === 'processing') && 'bg-amber-100 text-amber-800',
                            )}>
                                {active.status}
                            </span>
                        </div>

                        {(active.status === 'queued' || active.status === 'processing') && (
                            <div className="flex items-center gap-2 text-sm text-muted-foreground">
                                <Loader2 className="h-4 w-4 animate-spin" />
                                Processing row {active.processed_rows} of {active.total_rows}…
                            </div>
                        )}

                        {active.status === 'completed' && (
                            <div className="flex flex-wrap gap-4 text-sm">
                                <div><span className="font-medium">Succeeded:</span> {active.succeeded}</div>
                                <div><span className="font-medium">Failed:</span> {active.failed}</div>
                                <div><span className="font-medium">Skipped:</span> {active.skipped}</div>
                                {active.has_report && (
                                    <a href={guardianImports.reportUrl(active.uuid)} className="ml-auto">
                                        <Button variant="outline" size="sm">
                                            <Download className="mr-1 h-4 w-4" />
                                            Download report
                                        </Button>
                                    </a>
                                )}
                            </div>
                        )}

                        {active.status === 'failed' && (
                            <p className="text-sm text-destructive">{active.error}</p>
                        )}
                    </div>
                )}

                {/* Recent imports */}
                <div className="rounded-md border">
                    <div className="border-b bg-muted/50 px-4 py-2 text-sm font-medium">Recent Imports</div>
                    {recent.length === 0 ? (
                        <p className="p-6 text-center text-sm text-muted-foreground">No imports yet.</p>
                    ) : (
                        <table className="w-full text-sm">
                            <thead className="bg-muted/30">
                                <tr>
                                    <th className="px-3 py-2 text-left text-xs font-medium">File</th>
                                    <th className="px-3 py-2 text-left text-xs font-medium">Date</th>
                                    <th className="px-3 py-2 text-left text-xs font-medium">Status</th>
                                    <th className="px-3 py-2 text-left text-xs font-medium">Succeeded</th>
                                    <th className="px-3 py-2 text-left text-xs font-medium">Failed</th>
                                    <th className="px-3 py-2 text-left text-xs font-medium">Skipped</th>
                                    <th className="px-3 py-2 text-right text-xs font-medium">Report</th>
                                </tr>
                            </thead>
                            <tbody>
                                {recent.map((r) => (
                                    <tr key={r.uuid} className="border-t">
                                        <td className="px-3 py-2">{r.file_name}</td>
                                        <td className="px-3 py-2">
                                            {r.created_at ? new Date(r.created_at).toLocaleString() : '-'}
                                        </td>
                                        <td className="px-3 py-2">{r.status}</td>
                                        <td className="px-3 py-2">{r.succeeded}</td>
                                        <td className="px-3 py-2">{r.failed}</td>
                                        <td className="px-3 py-2">{r.skipped}</td>
                                        <td className="px-3 py-2 text-right">
                                            {r.has_report ? (
                                                <a
                                                    href={guardianImports.reportUrl(r.uuid)}
                                                    className="text-primary underline underline-offset-4"
                                                >
                                                    Download
                                                </a>
                                            ) : '—'}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    )}
                </div>
            </div>

            <Modal
                isOpen={showColumns}
                onClose={() => setShowColumns(false)}
                title="Import Columns"
                size="4xl"
            >
                <div className="max-h-[70vh] overflow-auto">
                    <table className="w-full text-sm">
                        <thead className="sticky top-0 bg-muted/90 backdrop-blur-sm">
                            <tr>
                                <th className="px-3 py-2 text-left text-xs font-medium">Column</th>
                                <th className="px-3 py-2 text-left text-xs font-medium">Group</th>
                                <th className="px-3 py-2 text-left text-xs font-medium">Required</th>
                                <th className="px-3 py-2 text-left text-xs font-medium">Format</th>
                                <th className="px-3 py-2 text-left text-xs font-medium">Example</th>
                                <th className="px-3 py-2 text-left text-xs font-medium">Notes</th>
                            </tr>
                        </thead>
                        <tbody>
                            {GUARDIAN_IMPORT_COLUMNS.map((c) => (
                                <tr key={c.name} className="border-t">
                                    <td className="px-3 py-2 font-medium">{c.name}</td>
                                    <td className="px-3 py-2">{c.group}</td>
                                    <td className="px-3 py-2">{c.required ? 'Yes' : 'No'}</td>
                                    <td className="px-3 py-2"><code className="text-xs">{c.format}</code></td>
                                    <td className="px-3 py-2"><code className="text-xs">{c.example}</code></td>
                                    <td className="px-3 py-2 text-muted-foreground">{c.notes}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </Modal>
        </>
    );
}

GuardianImport.layout = {
    breadcrumbs: [
        { title: 'Dashboard', href: '/dashboard' },
        { title: 'Guardians', href: '/guardians' },
        { title: 'Import', href: '/guardians/import' },
    ],
};
