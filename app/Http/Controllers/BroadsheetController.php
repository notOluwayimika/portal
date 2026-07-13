<?php

namespace App\Http\Controllers;

use App\Exports\BroadsheetExport;
use App\Models\Curriculum;
use App\Services\BroadsheetService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;

class BroadsheetController extends Controller
{
    public function __construct(private BroadsheetService $broadsheetService)
    {
    }

    public function groups(Request $request)
    {
        $request->validate([
            'class_level' => 'required|string',
            'status' => 'nullable|string|in:active,draft,closed',
            'is_ccm' => 'nullable|in:true,false',
        ]);

        $classLevel = \App\Support\ActiveSchool::getOrFail()->classLevels()
            ->where('uuid', $request->class_level)
            ->firstOrFail();

        $isCcm = $request->has('is_ccm') ? $request->boolean('is_ccm') : null;

        return response()->json([
            'groups' => $this->broadsheetService->groups($classLevel, $request->status, $isCcm),
        ]);
    }

    public function show(Curriculum $curriculum)
    {
        return response()->json($this->broadsheetService->build($curriculum));
    }

    public function export(Curriculum $curriculum)
    {
        $data = $this->broadsheetService->build($curriculum);

        $type = $data['is_ccm'] ? 'ccm' : 'end-of-term';
        $filename = Str::slug($data['class_level'] . '-' . $type . '-broadsheet') . '.xlsx';

        return Excel::download(new BroadsheetExport($data), $filename);
    }
}
