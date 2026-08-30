<?php

namespace App\Http\Controllers;

use App\Enums\ScholarshipKind;
use App\Http\Resources\ScholarshipResource;
use App\Models\Scholarship;
use App\Support\ActiveSchool;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ScholarshipController extends Controller
{
    public function index()
    {
        try {
            $scholarships = ActiveSchool::getOrFail()->scholarships;

            return ScholarshipResource::collection($scholarships);
        } catch (\Throwable $th) {
            \Log::error($th->getMessage());

            return response()->json(['error' => 'Failed to retrieve scholarships'], 500);
        }
    }

    /**
     * `kind` IS REQUIRED ON CREATE and optional on update, and the asymmetry is deliberate.
     *
     * A new scholarship can always say which scheme it is — the person creating it is the person who
     * knows — and there is no reason to mint another NULL now that the field can be set. NULL means
     * "nobody has configured this yet" ({@see ScholarshipKind}); it is the state the backfill left
     * the pre-existing rows in, not a value anyone should be able to choose.
     *
     * THE VALIDATION RUNS OUTSIDE THE `try`, and that is a fix rather than a style choice. Every
     * method here wraps its body in `catch (\Throwable)` — and `ValidationException` IS a Throwable,
     * so until this commit a missing `name` was swallowed and answered **500 "Failed to create
     * scholarship"** with no `errors` payload, measured on this branch before the change. A client
     * cannot tell "you left a field blank" from "the server is broken", and the screen below needs
     * the 422 to say which field. The rest of the controller's shape is left exactly as it was; what
     * is wrong with it is written down in
     * `docs/handoff/tickets/scholarship-controller-does-not-follow-the-house-request-pattern.md`
     * rather than rewritten nine days before cutover.
     */
    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'kind' => ['required', Rule::enum(ScholarshipKind::class)],
        ]);

        try {
            $school = ActiveSchool::getOrFail();
            $existing = $school->scholarships()->where('name', $request->name)->first();
            if ($existing) {
                return response()->json(['error' => 'Scholarship with this name already exists'], 409);
            }

            $scholarship = $school->scholarships()->create([
                'name' => $request->name,
                'kind' => $request->kind,
            ]);

            return response()->json(new ScholarshipResource($scholarship), 201);
        } catch (\Throwable $th) {
            \Log::error($th->getMessage());

            return response()->json(['error' => 'Failed to create scholarship'], 500);
        }
    }

    /**
     * `kind` IS `sometimes` HERE, FOR TWO REASONS, and both are load-bearing.
     *
     * The two rows that exist today were backfilled to NULL, so classification has to be reachable on
     * a row that already exists — that is the entire point of this commit. And the Scholarships tab
     * edits the NAME inline, sending `{name}` alone; a `required` rule would 422 that edit, and an
     * unconditional `$request->only('name', 'kind')` write would silently NULL the classification of
     * a scholarship somebody had just configured. `only()` skips absent keys, so a name-only edit
     * leaves `kind` exactly where it was.
     *
     * A CLASSIFICATION IS CORRECTABLE, DELIBERATELY. Nothing here refuses a second write, because a
     * scholarship entered as `discount` when it is in fact `sponsored` bills every family on that
     * scheme for fees an outside body has already agreed to pay, and the fix for that must not need
     * a migration. What it does NOT admit is a
     * move back to NULL: `Rule::enum` rejects null, so "nobody has said yet" stays the state the
     * backfill left rather than becoming something an operator can choose.
     *
     * AND IT IS NOT MAKER-CHECKER, WHICH IS A DECISION AND NOT AN OMISSION. `kind` decides whether a
     * family is billed at all, so the question is fair to ask. The approval Brookstone actually wants
     * is on the VALUE of a reduction, and the discount-policy change flow already carries it
     * (`finance_discount_policy_changes`). Adding a second approval chain to a two-row classification
     * screen days before cutover buys a control nobody has been trained to operate and blocks the
     * work it is meant to protect. The change is AUDITED instead — {@see Scholarship} carries
     * `LogsActivity` over `name` and `kind`, so a flip to `sponsored` names its causer and both
     * values. If that trade is ever revisited, revisit it on the evidence in the log, not by
     * re-deriving this paragraph.
     */
    public function update(Request $request, Scholarship $scholarship)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'kind' => ['sometimes', Rule::enum(ScholarshipKind::class)],
        ]);

        try {
            $scholarship->update($request->only('name', 'kind'));

            return new ScholarshipResource($scholarship);
        } catch (\Throwable $th) {
            \Log::error($th->getMessage());

            return response()->json(['error' => 'Failed to update scholarship'], 500);
        }
    }

    public function destroy(Scholarship $scholarship)
    {
        try {
            $scholarship->delete();

            return response()->json(['message' => 'Scholarship deleted successfully']);
        } catch (\Throwable $th) {
            \Log::error($th->getMessage());

            return response()->json(['error' => 'Failed to delete scholarship'], 500);
        }
    }
}
