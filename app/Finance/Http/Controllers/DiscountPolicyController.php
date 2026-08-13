<?php

namespace App\Finance\Http\Controllers;

use App\Finance\Enums\DiscountPolicyStatus;
use App\Finance\Http\Resources\DiscountPolicyResource;
use App\Finance\Models\DiscountPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;

/**
 * Read the discount catalog (S1 commit 3). There is deliberately no PUT or DELETE here — editing and
 * removing are `amend` and `retire` change requests. Reads carry only the group's finance.access.
 */
class DiscountPolicyController extends Controller
{
    /**
     * The School's policies, by name, with ONE OPTIONAL FILTER — the {@see FeeScheduleController::index}
     * shape, deliberately: `status` absent means UNFILTERED.
     *
     * THIS CHANGED IN U2 AND THE CHANGE IS THE POINT. Until the authoring screen existed this method
     * hard-filtered to `active` with no way to ask for anything else, and a caller wanting the catalog
     * got a partial one it could not tell was partial. A policy that priced an invoice must stay
     * nameable forever — the same argument `finance_discount_policies` uses for having a no-DELETE
     * trigger and a state-scoped name unique rather than a plain one — so a superseded or retired row
     * is provenance, not clutter, and the screen that authors the catalog has to show it. A caller
     * that wants only the choosable ones now says so: `?status=active`.
     *
     * NOTHING ELSE CALLED THIS. Verified at the time of the change (repo-wide search for the path in
     * resources/, tests/ and app/): the only references were the wayfinder-generated client and the two
     * route fixtures, so no consumer relied on the old implicit filter. A future consumer that wants
     * the choosable set must pass `status=active` — which is what U8 (awarding a discount at invoice
     * time) will ask for, and is more honest than an unstated default it cannot see.
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'status' => ['nullable', Rule::enum(DiscountPolicyStatus::class)],
        ]);

        $policies = DiscountPolicy::query()
            ->when(
                $request->filled('status'),
                fn ($query) => $query->where('status', (string) $request->input('status')),
            )
            ->orderBy('name')
            ->get();

        return response()->json(DiscountPolicyResource::collection($policies));
    }
}
