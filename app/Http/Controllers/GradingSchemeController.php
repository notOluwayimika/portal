<?php

namespace App\Http\Controllers;

use App\Http\Resources\GradingSchemeResource;
use App\Models\GradingScheme;
use App\Support\ActiveSchool;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class GradingSchemeController extends Controller
{
    public function index()
    {
        return GradingSchemeResource::collection(
            GradingScheme::where('school_id', ActiveSchool::id())->where('status', 'active')->with('items')->orderBy('name')->get()
        );
    }

    public function store(Request $request)
    {
        $data = $this->validateScheme($request);
        $scheme = DB::transaction(function () use ($data) {
            $scheme = GradingScheme::create([
                'school_id' => ActiveSchool::id(),
                'name' => $data['name'],
                'mode' => 'categorical',
                'version' => 1,
                'status' => 'active',
            ]);
            $this->createItems($scheme, $data['items']);

            return $scheme;
        });

        return response()->json(new GradingSchemeResource($scheme->load('items')), 201);
    }

    public function update(Request $request, GradingScheme $gradingScheme)
    {
        abort_unless($gradingScheme->school_id === ActiveSchool::id(), 404);
        $data = $this->validateScheme($request);
        $scheme = DB::transaction(function () use ($gradingScheme, $data) {
            $gradingScheme->update(['status' => 'retired']);
            $scheme = GradingScheme::create([
                'school_id' => $gradingScheme->school_id,
                'family_uuid' => $gradingScheme->family_uuid,
                'name' => $data['name'],
                'mode' => 'categorical',
                'version' => $gradingScheme->version + 1,
                'status' => 'active',
            ]);
            $this->createItems($scheme, $data['items']);
            $gradingScheme->classLevels()->update(['grading_scheme_id' => $scheme->id]);

            return $scheme;
        });

        return new GradingSchemeResource($scheme->load('items'));
    }

    private function validateScheme(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'items' => ['required', 'array', 'min:2'],
            'items.*.code' => ['required', 'string', 'max:20', 'distinct'],
            'items.*.label' => ['required', 'string', 'max:255'],
        ]);
    }

    private function createItems(GradingScheme $scheme, array $items): void
    {
        foreach ($items as $index => $item) {
            $scheme->items()->create([...$item, 'display_order' => $index + 1]);
        }
    }
}
