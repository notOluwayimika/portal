<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\School;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class SchoolController extends Controller
{
    public function index()
    {
        $schools = School::query()
            ->withCount([
                'students' => fn ($q) => $q->withoutGlobalScopes(),
                'teachers' => fn ($q) => $q->withoutGlobalScopes(),
                'users' => fn ($q) => $q->withoutGlobalScopes(),
            ])
            ->orderBy('name')
            ->get();

        return Inertia::render('super-admin/schools/index', [
            'schools' => $schools->map(fn ($s) => [
                'uuid' => $s->uuid,
                'name' => $s->name,
                'slug' => $s->slug,
                'address' => $s->address,
                'phone' => $s->phone,
                'email' => $s->email,
                'website' => $s->website,
                'name_on_result' => $s->name_on_result,
                'active' => (bool) $s->active,
                'students_count' => $s->students_count,
                'teachers_count' => $s->teachers_count,
                'users_count' => $s->users_count,
            ])->values(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('schools', 'name')],
            'address' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'website' => ['nullable', 'url', 'max:255'],
            'name_on_result' => ['nullable', 'string', 'max:255'],
        ]);

        School::create([
            ...$data,
            'slug' => $this->uniqueSlug($data['name']),
            'active' => true,
        ]);

        return back()->with('success', 'School created.');
    }

    public function update(Request $request, School $school)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('schools', 'name')->ignore($school->id)],
            'address' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'website' => ['nullable', 'url', 'max:255'],
            'name_on_result' => ['nullable', 'string', 'max:255'],
            'active' => ['boolean'],
        ]);

        $school->update($data);

        return back()->with('success', 'School updated.');
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name);
        $slug = $base;
        $i = 1;

        while (School::where('slug', $slug)->exists()) {
            $slug = $base . '-' . ++$i;
        }

        return $slug;
    }
}
