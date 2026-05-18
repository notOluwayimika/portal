import { Camera, UserCircle2 } from 'lucide-react';
import { useRef } from 'react';

interface ProfileImageUploadProps {
    preview: string | null;
    onChange: (file: File) => void;
    error?: string;
}

export function ProfileImageUpload({ preview, onChange, error }: ProfileImageUploadProps) {
    const inputRef = useRef<HTMLInputElement>(null);

    const handleChange = (e: React.ChangeEvent<HTMLInputElement>) => {
        const file = e.target.files?.[0];
        if (file) onChange(file);
    };

    return (
        <div className="flex flex-col items-center gap-3">
            <div
                className="relative h-24 w-24 cursor-pointer overflow-hidden rounded-full border-2 border-dashed border-border bg-muted transition-colors hover:border-primary"
                onClick={() => inputRef.current?.click()}
            >
                {preview ? (
                    <img
                        src={preview}
                        alt="Profile preview"
                        className="h-full w-full object-cover"
                    />
                ) : (
                    <UserCircle2 className="h-full w-full p-3 text-muted-foreground" />
                )}
                <div className="absolute inset-0 flex items-center justify-center bg-black/40 opacity-0 transition-opacity hover:opacity-100">
                    <Camera className="h-6 w-6 text-white" />
                </div>
            </div>

            <button
                type="button"
                className="text-xs text-muted-foreground underline underline-offset-4 hover:text-foreground"
                onClick={() => inputRef.current?.click()}
            >
                {preview ? 'Change photo' : 'Upload photo'}
            </button>

            <input
                ref={inputRef}
                type="file"
                accept="image/jpeg,image/jpg,image/png,image/gif,image/webp"
                className="hidden"
                onChange={handleChange}
            />

            {error && <p className="text-destructive text-xs">{error}</p>}
        </div>
    );
}
