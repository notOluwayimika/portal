<?php

namespace App\Services;

use App\Models\Teacher;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

class TeacherService
{
    public function paginate(Request $request): LengthAwarePaginator
    {
        return Teacher::query()
            ->when($request->search, function ($q) use ($request) {
                $term = '%' . $request->search . '%';
                $q->where(function ($inner) use ($term) {
                    $inner->where('first_name', 'LIKE', $term)
                          ->orWhere('last_name', 'LIKE', $term)
                          ->orWhere('staff_number', 'LIKE', $term);
                });
            })
            ->when($request->status, fn($q) => $q->where('status', $request->status))
            ->with(['photoFile'])
            ->latest()
            ->paginate($request->integer('per_page', 25));
    }

    public function store(array $attributes): Teacher
    {
        if (empty($attributes['staff_number'])) {
            $attributes['staff_number'] = $this->generateStaffNumber($attributes['school_id']);
        }

        return Teacher::create($attributes);
    }

    private function generateStaffNumber(int $schoolId): string
    {
        $year   = now()->year;
        $prefix = "STF/{$year}/";

        $last = Teacher::withTrashed()
            ->where('school_id', $schoolId)
            ->where('staff_number', 'LIKE', $prefix . '%')
            ->orderByDesc('staff_number')
            ->value('staff_number');

        $next = $last ? ((int) substr($last, strlen($prefix))) + 1 : 1;

        return $prefix . str_pad($next, 3, '0', STR_PAD_LEFT);
    }

    public function show(Teacher $teacher): Teacher
    {
        return $teacher->load(['photoFile']);
    }

    public function update(Teacher $teacher, array $attributes): Teacher
    {
        $teacher->update(array_filter(
            $attributes,
            fn($v) => !is_null($v)
        ) + ['photo_id' => $attributes['photo_id'] ?? $teacher->photo_id]);

        return $teacher;
    }

    public function updateStatus(Teacher $teacher, string $status): void
    {
        $teacher->update(['status' => $status]);
    }

    public function delete(Teacher $teacher): bool
    {
        return (bool) $teacher->delete();
    }
}
