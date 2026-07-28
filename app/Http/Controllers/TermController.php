<?php

namespace App\Http\Controllers;

use App\Http\Resources\TermResource;
use App\Models\AcademicSession;
use App\Models\Term;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Academic terms within a session.
 *
 * Term dates are LOAD-BEARING FOR MONEY since S1 commit 2: `finance_fee_schedules.term_id` is a
 * RESTRICT foreign key, so a term's window prices a fee schedule and a term that is priced cannot
 * be deleted. That is why the write path here validates real dates rather than strings, assigns an
 * explicit field list rather than spreading the request, and lets ValidationException surface as
 * the 422 it is.
 *
 * `status` IS NOT WRITEABLE THROUGH THIS CONTROLLER, by omission from {@see validatedTerm}. It is
 * on Term::$fillable, so the previous `...$request->all()` spread let any caller set it. Moving a
 * term between lifecycle states is a governed action, not a field edit — see the PR that removed
 * it for what a transition endpoint would need (its own permission, and a regeneration of the
 * derived RBAC oracles). Nothing here replaces that; the field is simply no longer settable.
 */
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
        // No try/catch. ValidationException is a Throwable, so the previous
        // `catch (\Throwable)` swallowed it and answered 500 'Unable to create term' — the client
        // never learned which field was wrong. Laravel already renders ValidationException as a
        // 422 with per-field errors; letting it propagate IS the handling. (destroy() keeps its
        // catch: that one is a deliberate Finance FK guard, not this failure mode.)
        $validated = $this->validatedTerm($request);

        // Assigned EXPLICITLY, never `...$request->all()`. Term::$fillable includes `status`,
        // `school_id` and `academic_session_id`, so the spread let a client set a term's status
        // directly and name a school the route never validated. The session owns the parentage —
        // it comes from the route-bound $session, not the body — and `status` is not settable
        // here at all (see the class docblock for what a governed transition would need).
        $term = $session->terms()->create([
            ...$validated,
            'slug' => Str::slug(str_replace('/', '-', $validated['name']), '-'),
        ]);

        return response()->json($term, 201);
    }

    /**
     * The fields THIS endpoint owns, and only those.
     *
     * `status` is deliberately absent: it is a lifecycle transition (active → completed), not a
     * field a caller edits in passing, and terms are now referenced by finance fee schedules
     * under a RESTRICT FK. A governed transition endpoint is a separate change — it needs its own
     * permission and a regeneration of the derived RBAC oracles, both owned elsewhere this slice.
     *
     * @return array<string, mixed>
     */
    private function validatedTerm(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'order' => ['required', 'integer', 'min:1'],
            // Real date rules. These were `required|string`, which accepted "banana" — and term
            // dates now price fee schedules, so a garbage window reaches money.
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after:start_date'],
            'result_visible_at' => ['nullable', 'date'],
            'registration_deadline' => ['nullable', 'date'],
        ]);
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
        $validated = $this->validatedTerm($request);

        // findOrFail, not find: the term must belong to THIS session. The route now scopes the
        // binding (routes/api.php), which already 404s a foreign uuid before we get here — this
        // keeps the method correct on its own terms rather than depending on a route flag that
        // was removed once already. `find(...)->update(...)` on a foreign uuid was a 500.
        $term = $session->terms()->findOrFail($term->id);

        $term->update([
            ...$validated,
            'slug' => Str::slug(str_replace('/', '-', $validated['name']), '-'),
        ]);

        // The MODEL, refreshed — not the boolean update() returns. The previous line assigned
        // that boolean over $term and returned it, so a successful edit answered `true`.
        return response()->json($term->fresh(), 200);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(AcademicSession $session, Term $term)
    {
        // findOrFail for the same reason as update(): a term uuid from another session used to
        // yield null here and 500 on ->delete(). Fixed ABOVE the guard below, which is a
        // different concern and is deliberately left exactly as shipped.
        $term = $session->terms()->findOrFail($term->id);

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
