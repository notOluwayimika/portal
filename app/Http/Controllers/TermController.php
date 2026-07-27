<?php

namespace App\Http\Controllers;

use App\Http\Resources\TermResource;
use App\Models\AcademicSession;
use App\Models\Term;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class TermController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(AcademicSession $session)
    {
        return TermResource::collection($session->terms);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request, AcademicSession $session)
    {
        try {
            $request->validate([
                'name' => 'required|string',
                'order' => 'required|integer',
                'start_date' => 'required|string',
                'end_date' => 'required|string',
                'result_visible_at' => 'nullable|string',
                'registration_deadline' => 'nullable|string',
            ]);
            $term = $session->terms()->create([...$request->all(), 'slug' => Str::slug(str_replace('/', '-', $request->name), '-')]);

            return response()->json($term, 201);
        } catch (\Throwable $th) {
            \Log::error($th->getMessage());

            return response()->json('Unable to create term', 500);
        }

    }

    /**
     * Display the specified resource.
     */
    public function show(Term $term)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Term $term)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, AcademicSession $session, Term $term)
    {
        try {
            $request->validate([
                'name' => 'required|string',
                'order' => 'required|integer',
                'start_date' => 'required|string',
                'end_date' => 'required|string',
                'result_visible_at' => 'nullable|string',
                'registration_deadline' => 'nullable|string',
            ]);
            $term = $session->terms()->find($term->id)->update([...$request->all(), 'slug' => Str::slug(str_replace('/', '-', $request->name), '-')]);

            return response()->json($term, 200);
        } catch (\Throwable $th) {
            \Log::error($th->getMessage());

            return response()->json('Unable to update term', 500);
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(AcademicSession $session, Term $term)
    {
        $term = $session->terms()->find($term->id);

        // A term priced by a finance fee schedule is protected by a RESTRICT FK (S1 commit 2). Without
        // this guard the FK surfaces a raw QueryException (MySQL 1451) as a 500; translate it to a
        // friendly refusal. The same protection reaches an academic-session delete via the CASCADE
        // academic_sessions ← terms chain — deleting a session whose term is priced is refused too.
        try {
            $term->delete();
        } catch (\Throwable $e) {
            if ($e instanceof QueryException && (int) ($e->errorInfo[1] ?? 0) === 1451) {
                return response()->json(['message' => 'This term has financial records and cannot be deleted.'], 422);
            }
            throw $e;
        }

        return response()->json(null, 204);
    }
}
