import { Head, router, useForm } from '@inertiajs/react';
import { ImageUp, Trash2 } from 'lucide-react';
import type { FormEvent } from 'react';
import { useEffect, useState } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

export default function ResultSignatureEdit({
    signatureUrl,
}: {
    signatureUrl: string | null;
}) {
    const [previewUrl, setPreviewUrl] = useState<string | null>(null);
    const form = useForm<{ signature: File | null }>({ signature: null });

    useEffect(() => {
        return () => {
            if (previewUrl) {
                URL.revokeObjectURL(previewUrl);
            }
        };
    }, [previewUrl]);

    const selectFile = (file: File | null) => {
        if (previewUrl) {
            URL.revokeObjectURL(previewUrl);
        }

        form.setData('signature', file);
        setPreviewUrl(file ? URL.createObjectURL(file) : null);
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post('/result-signature', {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                setPreviewUrl(null);
            },
        });
    };

    return (
        <div className="mx-auto flex w-full max-w-2xl flex-col gap-4 p-4">
            <Head title="Result Signature" />
            <div>
                <h1 className="text-xl font-semibold">Result Signature</h1>
                <p className="text-sm text-muted-foreground">
                    Upload the signature that may appear on approved student
                    results.
                </p>
            </div>
            <Card>
                <CardHeader>
                    <CardTitle className="text-base">Your signature</CardTitle>
                </CardHeader>
                <CardContent>
                    <form onSubmit={submit} className="space-y-4">
                        {(previewUrl || signatureUrl) && (
                            <div className="flex min-h-32 items-center justify-center rounded-md border bg-white p-4">
                                <img
                                    src={
                                        previewUrl ?? signatureUrl ?? undefined
                                    }
                                    alt="Result signature preview"
                                    className="max-h-28 max-w-full object-contain"
                                />
                            </div>
                        )}
                        <div className="space-y-1">
                            <Label htmlFor="result-signature">
                                Signature image
                            </Label>
                            <Input
                                id="result-signature"
                                type="file"
                                accept="image/png,image/jpeg,image/webp"
                                onChange={(event) =>
                                    selectFile(event.target.files?.[0] ?? null)
                                }
                                required={!signatureUrl}
                            />
                            <p className="text-xs text-muted-foreground">
                                PNG with a transparent background is
                                recommended. Maximum size: 2 MB.
                            </p>
                            <InputError message={form.errors.signature} />
                        </div>
                        <div className="flex justify-between gap-2">
                            {signatureUrl ? (
                                <Button
                                    type="button"
                                    variant="destructive"
                                    onClick={() =>
                                        router.delete('/result-signature')
                                    }
                                >
                                    <Trash2 className="h-4 w-4" /> Remove
                                </Button>
                            ) : (
                                <span />
                            )}
                            <Button
                                type="submit"
                                disabled={
                                    !form.data.signature || form.processing
                                }
                            >
                                <ImageUp className="h-4 w-4" />
                                {signatureUrl
                                    ? 'Replace signature'
                                    : 'Upload signature'}
                            </Button>
                        </div>
                    </form>
                </CardContent>
            </Card>
        </div>
    );
}
