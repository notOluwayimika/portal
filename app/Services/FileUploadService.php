<?php

namespace App\Services;

use App\Exceptions\NotFoundException;
use App\Exceptions\ConflictException;
use Aws\S3\S3Client;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Models\FileUpload;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\File;
use Barryvdh\DomPDF\Facade as PDF;
use Dompdf\Dompdf;
use Exception;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Symfony\Component\HttpFoundation\File\Exception\FileException;

class FileUploadService {
    protected FileUpload $fileUpload;
    public int $id;

    public function __construct()
    {
        $this->fileUpload = new FileUpload;
    }

    private function shouldUseS3(): bool
    {
        return !empty(env('AWS_ACCESS_KEY_ID')) &&
            !empty(env('AWS_SECRET_ACCESS_KEY')) &&
            !empty(env('AWS_BUCKET'));
    }

    /**
     * Get the file upload
     */
    public function getFileUpload(String $id, Array $columns=[])  : Object
    {
        return $this->fileUpload->select($columns)->find($id);
    }

    /**
     * Update a file upload
     */
    public function updateFileUpload(String $id, Array $columns=[]) : bool
    {
        return $this->fileUpload->where('id', $id)->update($columns);
    }

    /**
     * delete file upload from database
     */
    public function deleteFileUpload(String $id) : bool
    {
        return $this->fileUpload->where('id', $id)->delete();
    }

    /**
     * Return S3 storage disk
     */
    public function getDisk()
    {
        return Storage::disk('s3');
    }

    public function multiFileUploadS3Bucket(Request $request, String $name, String $filePath)
    {
        $data = null;
        if ($request->hasfile($name)) {
            $data = ['filename' => [], 'path' => null, 'filePath' => $filePath];
            foreach ($request->file($name) as $file) {
                $path = $file->store($filePath, 's3');
                $this->getDisk()->setVisibility($path, 'public');
                array_push($data['filename'], basename($path));
            }
            $data['path'] = config('app.s3_bucket') . '/' . $filePath;
        }

        return $data;
    }

    public function uploadFileToS3Bucket(mixed $request, string $filename, string $filePath)
    {
        if (!$request->hasFile($filename)) return null;

        $file = $request->file($filename);

        try {
            if (!$file->isValid()) throw new FileException(__('Invalid file upload'));

            $originalName = $file->getClientOriginalName();
            $fileName = time() . '_' . $originalName;

            $path = $file->storeAs($filePath, $fileName, 's3');

            if (!$path) throw new RuntimeException(__('Failed to store file on S3'));

            $this->getDisk()->setVisibility($path, 'public');

            return [
                'filename' => basename($path),
                'path' => config('app.s3_bucket') . '/' . $filePath,
                'filePath' => $filePath,
                'url' => $this->getDisk()->url($path),
            ];
        } catch (FileException $e) {
            Log::error('File validation error: ' . $e->getMessage());
            throw $e;
        } catch (RuntimeException $e) {
            Log::error('S3 upload error: ' . $e->getMessage());
            throw new RuntimeException(__('File upload failed: ' . $e->getMessage()));
        } catch (\Exception $e) {
            Log::error('Upload error: ' . $e->getMessage());
            throw new \Exception(__('An error occurred during file upload'));
        }
    }

    function fileUpload(mixed $request, string $name, string $filePath, ?string $refCode = null)
    {
        if (!File::exists(public_path($filePath))) {
            File::makeDirectory(public_path($filePath));
        }

        $fileNameToStore = null;

        if ($request->hasFile($name)) {

            $originalTempFile =  $request->file($name);
            $filenamewithextension = $originalTempFile->getClientOriginalName();
            $filename              = pathinfo($filenamewithextension, PATHINFO_FILENAME);
            $extension             = $originalTempFile->getClientOriginalExtension();
            $fileNameToStore       = $refCode === null
                ? str_ireplace(' ', '_', $filename) . '_' . time() . '.' . $extension
                : $refCode . '_' . str_ireplace(' ', '_', $filename) . '_' . encodeData(auth()->user()->id) . '.' . $extension;
            $originalTempFile->move(public_path($filePath), $fileNameToStore);
        }

        return $fileNameToStore;
    }

    /**
     * Update existing file
     */
    public function updateSingleFile(Request $request, int $id, string $name, string $filePath)
    {
        $uploadFile = $this->uploadFileToS3Bucket($request, $name, $filePath);
        $this->updateFileUpload($id, ['name' => $uploadFile]);
    }

    /**
     * Delete a file upload from s3
     */
    function unlinkFileUpload(String $path) {
        if(Storage::disk('s3')->exists($path)) {
            return Storage::disk('s3')->delete($path);
        }
        return false;
    }

    /**
     * Delete a file upload from s3
     */
    function dynamicallyUnlinkFileUpload(String $path) {
        $disk = $this->shouldUseS3() ? 's3' : 'local';

        if(Storage::disk($disk)->exists($path)) {
            return Storage::disk($disk)->delete($path);
        }
        return false;
    }

    /**
     * get file upload from s3
     */
    public function get(string $filename, string $filePath)
    {
        $headers = [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$filename.'"',
        ];

        return Response::make(Storage::disk('s3')->get($filePath.'/'.$filename), 200, $headers);
    }

    public function unlinkFileAndUpdate(int $id, String $attr, String $table, String $relatedTable, bool $useDynamic = false)
    {
        $model = DB::table($table)->select($attr)->find($id);

        if (!$model) throw new NotFoundException(__('Model not found'), 404);

        $relatedModel = DB::table($relatedTable)->where('id', $model->$attr)->first();

        $unlink = !$useDynamic
            ? $this->unlinkFileUpload($relatedModel->folder_path.'/'.$relatedModel->name)
            : $this->dynamicallyUnlinkFileUpload($relatedModel->folder_path.'/'.$relatedModel->name);

        if ($unlink) {

            DB::table($table)->where('id', $id)->update([$attr => null]);

            // Delete file upload
            $this->deleteFileUpload($model->$attr);

            return true;
        }

        throw new ConflictException(__('Image/file not deleted'), 409);
    }

    /**
     * store the uploaded file
    */
    public function storeFileUpload(String $name, String $filePath, String $url) : Object
    {
        return $this->fileUpload->create([
            'name'          => $name,
            'folder_path'   => $filePath,
            'url'          => $url,
        ]);
    }

    /**
     * upload multiple file and store as an array
    */
    public function storeAndUploadMultiFile(mixed $request, String $name, String $filePath)
    {
        $uploadFile = $this->multiFileUploadS3Bucket($request, $name, $filePath);

        return $uploadFile
            ? $this->storeFileUpload(json_encode($uploadFile['filename']), $uploadFile['filePath'], $uploadFile['url'])->id
            : $uploadFile;
    }

    /**
     * upload and store file
    */
    public function storeAndUploadFile(mixed $request, ?string $name, string $filePath, bool $useDynamic = false)
    {
        $uploadFile = !$useDynamic
            ? $this->uploadFileToS3Bucket($request, $name, $filePath)
            : $this->dynamicallyStoreFile($request, $name, $filePath);

        return $uploadFile
            ? $this->storeFileUpload($uploadFile['filename'], $uploadFile['filePath'], $uploadFile['url'])->id
            : $uploadFile;
    }

    // #########################################################################
    // FILE-UPLOAD DYNAMIC
    // #########################################################################

    public function dynamicallyStoreFile(Request $request, String $filename, String $filePath)
    {
        if ($request->hasFile($filename)) {
            $disk = $this->shouldUseS3() ? 's3' : 'local';
            $path = $request->file($filename)->store($filePath, $disk);

            if ($disk === 's3') {
                Storage::disk('s3')->setVisibility($path, 'public');
            }

            if ($path) {
                return [
                    'filename' => basename($path),
                    'path' => $filePath,
                    'filePath' => $this->shouldUseS3() ? config('app.s3_bucket') . '/' . $filePath : $filePath,
                    'url' => Storage::disk($disk)->url($path),
                ];
            }
        }

        return null;
    }

    public function dynamicUnlinkFileUpload(string $path, string $folder, string $name)
    {

        if (File::exists(public_path($folder . '/' . $name))) {
            File::delete(public_path($folder . '/' . $name));
        }

        try {
            if (Storage::disk('s3')->exists($path)) {
                Storage::disk('s3')->delete($path);
            }
        } catch (\Throwable $th) {
            //throw $th;
        }
    }
}
