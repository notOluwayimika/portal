<?php

namespace App\Services;

use App\Exceptions\ConflictException;
use App\Exceptions\NotFoundException;
use App\Models\FileUpload;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\HttpFoundation\File\Exception\FileException;

class FileUploadService
{
    public function __construct(private FileUpload $fileUpload) {}

    private function shouldUseS3(): bool
    {
        return !empty(config('filesystems.disks.s3.key')) &&
            !empty(config('filesystems.disks.s3.secret')) &&
            !empty(config('filesystems.disks.s3.bucket'));
    }

    private function getDisk(): string
    {
        return $this->shouldUseS3() ? 's3' : 'public';
    }

    private function storeFile(string $path, string $filePath, string $fileName, string $disk): array
    {
        $storageDisk = Storage::disk($disk);

        if ($disk === 's3') {
            $storageDisk->setVisibility($path, 'public');
        }

        return [
            'filename' => $fileName,
            'filePath' => $disk === 's3' ? config('app.s3_bucket') . '/' . $filePath : $filePath,
            'url'      => Storage::disk($disk)->url($path),
        ];
    }

    public function storeFileUpload(string $name, string $filePath, string $url): object
    {
        return $this->fileUpload->create([
            'name'        => $name,
            'folder_path' => $filePath,
            'url'         => $url,
        ]);
    }

    public function getFileUpload(string $id, array $columns = []): ?object
    {
        return $this->fileUpload->select($columns ?: '*')->find($id);
    }

    public function updateFileUpload(string $id, array $columns): int
    {
        return $this->fileUpload->where('id', $id)->update($columns);
    }

    public function deleteFileUpload(string $id): int
    {
        return $this->fileUpload->where('id', $id)->delete();
    }

    /**
     * Core upload method - handles single file upload to any disk
     */
    private function uploadFile(Request $request, string $filename, string $filePath, ?string $disk = null): ?array
    {
        if (!$request->hasFile($filename)) {
            return null;
        }

        $file = $request->file($filename);
        $disk = $disk ?? $this->getDisk();

        try {
            if (!$file->isValid()) {
                throw new FileException(__('Invalid file upload'));
            }

            // $fileName = time() . '_' . $file->getClientOriginalName();
            // $path = $file->storeAs($filePath, $fileName, $disk);
            $path = $file->store($filePath, $disk);

            if (!$path) {
                throw new RuntimeException(__('Failed to store file'));
            }

            return $this->storeFile($path, $filePath, basename($path), $disk);
        } catch (FileException $e) {
            Log::error('File validation error: ' . $e->getMessage());
            throw $e;
        } catch (RuntimeException $e) {
            Log::error('Upload error: ' . $e->getMessage());
            throw new RuntimeException(__('File upload failed: ') . $e->getMessage());
        } catch (Exception $e) {
            Log::error('Upload error: ' . $e->getMessage());
            throw new Exception(__('An error occurred during file upload'));
        }
    }

    /**
     * Core multiple files upload method
     */
    private function uploadMultipleFiles(Request $request, string $name, string $filePath, string $disk = 's3'): ?array
    {
        if (!$request->hasFile($name)) {
            return null;
        }

        $data = ['filename' => [], 'filePath' => $filePath];

        foreach ($request->file($name) as $file) {
            $path = $file->store($filePath, $disk);
            Storage::disk($disk)->setVisibility($path, 'public');
            $data['filename'][] = basename($path);
        }

        $data['url'] = $disk === 's3' 
            ? config('app.s3_bucket') . '/' . $filePath 
            : Storage::disk($disk)->url($filePath);

        return $data;
    }

    /**
     * Local file upload (non-cloud storage)
     */
    public function fileUpload(Request $request, string $name, string $filePath, ?string $refCode = null): ?string
    {
        $fullPath = public_path($filePath);

        if (!File::exists($fullPath)) {
            File::makeDirectory($fullPath, 0755, true);
        }

        if (!$request->hasFile($name)) {
            return null;
        }

        $file = $request->file($name);
        $filename = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
        $extension = $file->getClientOriginalExtension();

        $fileNameToStore = $refCode === null
            ? str_replace(' ', '_', $filename) . '_' . time() . '.' . $extension
            : $refCode . '_' . str_replace(' ', '_', $filename) . '_' . encodeData(auth()->id()) . '.' . $extension;

        $file->move($fullPath, $fileNameToStore);

        return $fileNameToStore;
    }

    // Public wrappers that use the core methods
    public function uploadFileToS3Bucket(Request $request, string $filename, string $filePath): ?array
    {
        return $this->uploadFile($request, $filename, $filePath, 's3');
    }

    public function dynamicallyStoreFile(Request $request, string $filename, string $filePath): ?array
    {
        return $this->uploadFile($request, $filename, $filePath);
    }

    public function multiFileUploadS3Bucket(Request $request, string $name, string $filePath): ?array
    {
        return $this->uploadMultipleFiles($request, $name, $filePath, 's3');
    }

    /**
     * Store and upload helpers
     */
    public function storeAndUploadFile(Request $request, string $name, string $filePath): ?int
    {
        $uploadFile = $this->uploadFile($request, $name, $filePath, $this->getDisk());

        return $uploadFile
            ? $this->storeFileUpload($uploadFile['filename'], $uploadFile['filePath'], $uploadFile['url'])->id
            : null;
    }

    public function storeAndUploadMultiFile(Request $request, string $name, string $filePath): ?int
    {
        $uploadFile = $this->multiFileUploadS3Bucket($request, $name, $filePath);

        return $uploadFile
            ? $this->storeFileUpload(json_encode($uploadFile['filename']), $uploadFile['filePath'], $uploadFile['url'])->id
            : null;
    }

    public function updateSingleFile(Request $request, int $id, string $name, string $filePath): void
    {
        $uploadFile = $this->uploadFileToS3Bucket($request, $name, $filePath);
        if ($uploadFile) {
            $this->updateFileUpload($id, ['name' => $uploadFile['filename']]);
        }
    }

    /**
     * Delete a file from storage
     */
    public function unlinkFileUpload(string $path, ?string $disk = 's3'): bool
    {
        $disk = $disk ?? $this->getDisk();

        try {
            if (Storage::disk($disk)->exists($path)) {
                return Storage::disk($disk)->delete($path);
            }
            return false;
        } catch (\Throwable $th) {
            Log::warning("Failed to delete file from {$disk}: " . $th->getMessage());
            return false;
        }
    }

    /**
     * Delete file from both local public path and storage disk
     */
    public function unlinkFileFromAll(string $path, string $folder, string $name): void
    {
        // Delete from local public path
        File::delete(public_path($folder . '/' . $name));

        // Delete from storage disk (S3 or local)
        $this->unlinkFileUpload($path);
    }

    public function unlinkFileAndUpdate(int $id, string $attr, string $table, string $relatedTable, ?string $disk = 's3'): bool
    {
        $model = DB::table($table)->select($attr)->find($id);

        if (!$model) {
            throw new NotFoundException(__('Model not found'), 404);
        }

        $relatedModel = DB::table($relatedTable)->find($model->$attr);

        if (!$relatedModel || !$this->unlinkFileUpload($relatedModel->folder_path . '/' . $relatedModel->name, $disk)) {
            throw new ConflictException(__('Image/file not deleted'), 409);
        }

        DB::table($table)->where('id', $id)->update([$attr => null]);
        $this->deleteFileUpload($model->$attr);

        return true;
    }
}