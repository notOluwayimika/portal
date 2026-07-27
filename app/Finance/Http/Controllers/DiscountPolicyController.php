<?php

namespace App\Finance\Http\Controllers;

use App\Finance\Enums\DiscountPolicyStatus;
use App\Finance\Http\Resources\DiscountPolicyResource;
use App\Finance\Models\DiscountPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

/**
 * Read the discount catalog (S1 commit 3). There is deliberately no PUT or DELETE here — editing and
 * removing are `amend` and `retire` change requests. Reads carry only the group's finance.access.
 */
class DiscountPolicyController extends Controller
{
    public function index(): JsonResponse
    {
        $policies = DiscountPolicy::query()
            ->where('status', DiscountPolicyStatus::Active->value)
            ->orderBy('name')
            ->get();

        return response()->json(DiscountPolicyResource::collection($policies));
    }
}
