<?php

namespace App\Http\Controllers;

use App\Http\Resources\TeacherResource;
use Illuminate\Http\Request;

class TeacherController extends Controller
{
    public function index()
    {
        $school = auth()->user()->school;
        $teachers = $school->teachers;
        return TeacherResource::collection($teachers);

    }
}
