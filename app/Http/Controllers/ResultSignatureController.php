<?php

namespace App\Http\Controllers;

use App\Services\FileUploadService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class ResultSignatureController extends Controller
{
    public function __construct(private FileUploadService $fileUploadService) {}

    public function edit(Request $request)
    {
        $user = $request->user()->load('signatureFile');

        return Inertia::render('result-signature/edit', [
            'signatureUrl' => $user->signatureFile?->url,
        ]);
    }

    public function update(Request $request)
    {
        $request->validate([
            'signature' => ['required', 'image', 'mimes:png,jpg,jpeg,webp', 'max:2048'],
        ]);

        $user = $request->user()->load('signatureFile');

        DB::transaction(function () use ($request, $user) {
            $oldSignature = $user->signatureFile;
            $signatureId = $this->fileUploadService->storeAndUploadFile($request, 'signature', 'result-signatures');

            $user->update(['signature_id' => $signatureId]);

            if ($oldSignature) {
                $this->fileUploadService->unlinkFileUpload($oldSignature->folder_path.'/'.$oldSignature->name, null);
                $oldSignature->delete();
            }
        });

        return back()->with('success', 'Result signature updated.');
    }

    public function destroy(Request $request)
    {
        $user = $request->user()->load('signatureFile');
        $signature = $user->signatureFile;

        $user->update(['signature_id' => null]);

        if ($signature) {
            $this->fileUploadService->unlinkFileUpload($signature->folder_path.'/'.$signature->name, null);
            $signature->delete();
        }

        return back()->with('success', 'Result signature removed.');
    }
}
