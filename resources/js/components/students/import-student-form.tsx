import axios from 'axios';
import { AlertTriangle, Upload } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { toast } from 'sonner';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { SearchableSelect } from '@/components/ui/searchable-select';
import { Spinner } from '@/components/ui/spinner';
import { useExcelImport } from '@/hooks/use-excel-import';
import { ExcelDateToJSDate } from '@/hooks/use-helper';
import { useApiSweetAlertConfirmation } from '@/hooks/use-sweetalert-confirmation';
import { cn } from '@/lib/utils';

interface ImportStudentFormProps {
    onSuccess: () => void;
    onCancel: () => void;
}

interface StudentRow {
    first_name?: string;
    middle_name?: string;
    last_name?: string;
    gender?: string;
    date_of_birth?: number | string;
    admission_number?: string;
    [key: string]: unknown;
}

interface CurriculumOption {
    id: number;
    term: number;
    class_level: string;
    arm: string;
    stream: string;
    full_name: string;
}

export function ImportStudentForm({
    onSuccess,
    onCancel,
}: ImportStudentFormProps) {
    const fileInputRef = useRef<HTMLInputElement>(null);
    const { importExcelData } = useExcelImport();

    const [curricula, setCurricula] = useState<CurriculumOption[]>([]);
    const [curriculumId, setCurriculumId] = useState<string>('');
    const [curriculumError, setCurriculumError] = useState('');

    const { confirmAndExecute, loading: posting } =
        useApiSweetAlertConfirmation();

    const [previewData, setPreviewData] = useState<StudentRow[]>([]);
    const [rowErrors, setRowErrors] = useState<Record<number, string[]>>({});
    const [selectedFile, setSelectedFile] = useState<File | null>(null);

    useEffect(() => {
        let isMounted = true;
        axios.get('/api/students/resources').then((res) => {
            if (isMounted) {
                setCurricula(res.data.data.curricula || []);
            }
        });

        return () => {
            isMounted = false;
        };
    }, []);

    const handleFileChange = (e: React.ChangeEvent<HTMLInputElement>) => {
        setRowErrors({});
        importExcelData(e, ({ file, jsonData }) => {
            setSelectedFile(file);
            setPreviewData(jsonData as StudentRow[]);
        });
        e.target.value = '';
    };

    const handleImport = async () => {
        if (!curriculumId) {
            setCurriculumError('Please select a class before importing.');

            return;
        }

        if (!previewData.length) {
            return;
        }

        setCurriculumError('');
        setRowErrors({});

        try {
            const result = await confirmAndExecute({
                sweetAlertTitle: `Import ${previewData.length} Student(s)?`,
                sweetAlertText:
                    'Valid rows will be saved. Rows with errors will be reported back.',
                sweetAlertIcon: 'question',
                confirmButtonText: 'Import',
                showSuccessAlert: false,
                showErrorAlert: false,
                onConfirm: async () => {
                    const res = await axios.post('/api/students/import', {
                        curriculum_id: curriculumId,
                        students: previewData,
                    });

                    return { ok: true, message: res.data?.message };
                },
            });

            if (result === false) {
                return;
            }

            if (result.ok) {
                toast.success(
                    result.message ??
                        `${previewData.length} student(s) imported successfully.`,
                );
                onSuccess();
            } else {
                if (result.saved > 0) {
                    toast.success(
                        `${result.saved} student(s) imported successfully.`,
                    );
                }

                setRowErrors(result.errors);
            }
        } catch (err: any) {
            const body = err.response?.data;

            if (body?.errors) {
                if ((body.saved ?? 0) > 0) {
                    toast.success(
                        `${body.saved} student(s) imported successfully.`,
                    );
                }

                setRowErrors(body.errors);
            } else {
                toast.error('Something went wrong. Please try again.');
            }
        }
    };

    const errorCount = Object.keys(rowErrors).length;

    const curriculaOptions = curricula.map((c) => ({
        value: c.id.toString(),
        label: c.full_name,
    }));

    return (
        <form className="space-y-4">
            {/* Class selector */}
            <div className="space-y-2">
                <Label>Assigned Class</Label>
                <SearchableSelect
                    placeholder="Search for a class..."
                    options={curriculaOptions}
                    value={curriculaOptions.find(
                        (o) => o.value === curriculumId,
                    )}
                    onChange={(opt: any) => {
                        setCurriculumId(opt?.value || '');
                        setCurriculumError('');
                    }}
                    error={!!curriculumError}
                />
                {curriculumError && (
                    <p className="text-xs text-destructive">
                        {curriculumError}
                    </p>
                )}
            </div>

            {/* File picker */}
            <div className="flex flex-col items-center gap-3 rounded-lg border-2 border-dashed border-border p-6 text-center">
                <Upload className="h-8 w-8 text-muted-foreground" />
                <p className="text-sm text-muted-foreground">
                    {selectedFile
                        ? selectedFile.name
                        : 'Select an Excel or CSV file (.xls, .xlsx, .csv)'}
                </p>
                <Button
                    type="button"
                    variant="secondary"
                    onClick={() => fileInputRef.current?.click()}
                >
                    Browse File
                </Button>
                <a
                    href="/assets/docs/students-import-template.xlsx"
                    download
                    className="text-xs text-primary underline underline-offset-4"
                >
                    Download Template
                </a>
                <input
                    ref={fileInputRef}
                    type="file"
                    accept=".xls,.xlsx,.csv"
                    className="hidden"
                    onChange={handleFileChange}
                />
            </div>

            {/* Row-level errors from backend */}
            {errorCount > 0 && (
                <div className="rounded-md border border-destructive/30 bg-destructive/10 p-3">
                    <div className="flex items-start gap-2">
                        <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0 text-destructive" />
                        <div className="text-sm text-destructive">
                            <p className="mb-1 font-medium">
                                {errorCount} row(s) have errors:
                            </p>
                            <table className="w-full text-xs">
                                <tbody>
                                    {Object.entries(rowErrors).map(
                                        ([idx, messages]) => (
                                            <tr key={idx}>
                                                <td className="w-16 py-0.5 align-top font-medium">
                                                    Row {Number(idx) + 1}
                                                </td>
                                                <td className="py-0.5">
                                                    {messages.join(', ')}
                                                </td>
                                            </tr>
                                        ),
                                    )}
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            )}

            {/* Preview table */}
            <div className="max-h-72 overflow-auto rounded-md border">
                {previewData.length === 0 ? (
                    <p className="p-6 text-center text-sm text-muted-foreground">
                        Imported data will appear here after selecting a file.
                    </p>
                ) : (
                    <table className="w-full text-sm">
                        <thead className="sticky top-0 bg-muted/90 backdrop-blur-sm">
                            <tr>
                                {[
                                    'S/N',
                                    'First Name',
                                    'Middle Name',
                                    'Last Name',
                                    'Gender',
                                    'Date of Birth',
                                    'Admission #',
                                ].map((h) => (
                                    <th
                                        key={h}
                                        className="px-3 py-2 text-left text-xs font-medium"
                                    >
                                        {h}
                                    </th>
                                ))}
                            </tr>
                        </thead>
                        <tbody>
                            {previewData.map((row, index) => {
                                const hasError = !!rowErrors[index];

                                return (
                                    <tr
                                        key={index}
                                        className={cn(
                                            'border-t',
                                            hasError &&
                                                'bg-destructive/10 text-destructive',
                                        )}
                                    >
                                        <td className="px-3 py-2">
                                            {index + 1}
                                        </td>
                                        <td className="px-3 py-2">
                                            {row.first_name ?? '-'}
                                        </td>
                                        <td className="px-3 py-2">
                                            {row.middle_name ?? '-'}
                                        </td>
                                        <td className="px-3 py-2">
                                            {row.last_name ?? '-'}
                                        </td>
                                        <td className="px-3 py-2">
                                            {row.gender ?? '-'}
                                        </td>
                                        <td className="px-3 py-2">
                                            {typeof row.date_of_birth ===
                                            'number'
                                                ? ExcelDateToJSDate(
                                                      row.date_of_birth,
                                                  ).toLocaleDateString()
                                                : (row.date_of_birth ?? '-')}
                                        </td>
                                        <td className="px-3 py-2">
                                            {row.admission_number ?? '-'}
                                        </td>
                                    </tr>
                                );
                            })}
                        </tbody>
                    </table>
                )}
            </div>

            {previewData.length > 0 && (
                <p className="text-xs text-muted-foreground">
                    {previewData.length} row(s) ready to import
                    {errorCount > 0 &&
                        ` · ${errorCount} with errors (rows in red will be skipped)`}
                </p>
            )}

            {/* Footer */}
            <div className="flex justify-end gap-3 border-t pt-4">
                <Button
                    type="button"
                    variant="outline"
                    onClick={onCancel}
                    disabled={posting}
                >
                    Cancel
                </Button>
                <Button
                    type="button"
                    onClick={handleImport}
                    disabled={posting || previewData.length === 0}
                >
                    {posting ? (
                        <Spinner className="mr-2 h-4 w-4 animate-spin" />
                    ) : (
                        <Upload className="mr-2 h-4 w-4" />
                    )}
                    {previewData.length > 0
                        ? `Import ${previewData.length} Students`
                        : 'Import Students'}
                </Button>
            </div>
        </form>
    );
}
