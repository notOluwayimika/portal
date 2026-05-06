<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;

class FileUploadService
{
    /**
     * Create a new class instance.
     */
    public function __construct()
    {
        //
    }

    public function handle($file, string $directory = 'cvs')
    {
        if (!$file) {
            return null;
        }
        if ($file instanceof UploadedFile) {
            $path = Storage::disk('s3')->putFile($directory, $file);
            $url = Storage::disk('s3')->url($path);
            $originalName = $file->getClientOriginalName();
            $extension = $file->getClientOriginalExtension();
            $baseName = pathinfo($originalName, PATHINFO_FILENAME);
            $fileSizeMB = round($file->getSize() / 1048576, 2);
        }
        return [
            "url" => $url,
            "extension" => $extension,
            "file_name" => $originalName,
            "file_base_name" => $baseName,
            "file_size" => $fileSizeMB
        ];

    }
}
