<?php

namespace App\Services;

use App\DTOs\StudentDto;
use App\Models\Student;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;

class StudentService
{
    public function paginate(Request $request): LengthAwarePaginator
    {
        return Student::query()
            ->when($request->search, fn ($q) => $q->search($request->search))
            ->with([
                'user:id,first_name,last_name',
                'classrooms',
            ])
            ->latest()
            ->paginate(50);
    }

    public function store(array $attributes): Student
    {
        return Student::create($attributes);
    }

    public function show(Student $student): Student
    {
        return $student->load([
            'user:id,first_name,last_name',
            'classrooms:id,name',
            'guardian:id,first_name,last_name',
            'nextOfKin:id,first_name,last_name,phone',
            'scores',
            'medicalRecord',
        ]);
    }

    public function update(Student $student, array $attributes): Student
    {
        $student->update($attributes);

        // if ($request->photo) {
        //     $student->update([
        //         'photo' => $this->uploadFile($request->photo, 'profiles')
        //     ]);
        // }
        return $student;
    }

    public function delete(Student $student): bool
    {
        return $student->delete();
    }

    public function datatable()
    {
        $students = Student::with([
            'user:id,first_name,last_name',
            'classrooms:id,name',
        ])
            ->latest()
            ->get();
        $results = [];

        foreach ($students as $student) {
            $results[] = [
                'id'                => $student->id,
                'name'              => $student->name,
                'email'             => $student->email,
                'phone'             => $student->phone,
                'classrooms'        => $student->classrooms->map(fn($classroom) => $classroom->name)->implode(', '),
                'admission_number'  => $student->admission_number,
                'photo'             => $student->photo,
            ];
        }

        return $results;
    }

    public function datatable2()
    {
        $students = Student::with([
            'user:id,first_name,last_name',
            'classrooms:id,name',
        ])->latest();

        // Search
        $query = Student::query();
        $query->when(request('search'), function ($q) {
            $searchTerm = '%' . request('search') . '%';

            $q->where(function ($q) use ($searchTerm) {
                // Search in student fields
                $q->where('first_name', 'LIKE', $searchTerm)
                    ->orWhere('last_name', 'LIKE', $searchTerm)
                    ->orWhere('admission_number', 'LIKE', $searchTerm)
                    ->orWhere('phone', 'LIKE', $searchTerm)
                    ->orWhere('email', 'LIKE', $searchTerm);

                // Search in related classroom names
                $q->orWhereHas('classrooms', function ($q) use ($searchTerm) {
                    $q->where('name', 'ILIKE', $searchTerm);
                });
            });
        });

        $students = $query->get();
        $results = [];

        foreach ($students as $student) {
            $results[] = [
                'id'                => $student->id,
                'name'              => $student->name,
                'email'             => $student->email,
                'phone'             => $student->phone,
                'classrooms'        => $student->classrooms->map(fn($classroom) => $classroom->name)->implode(', '),
                'admission_number'  => $student->admission_number,
                'photo'             => $student->photo,
            ];
        }

        return $results;
    }
}
