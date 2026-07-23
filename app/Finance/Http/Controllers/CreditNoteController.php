<?php

namespace App\Finance\Http\Controllers;

use App\Exceptions\BusinessRuleException;
use App\Finance\Actions\IssueCreditNote;
use App\Finance\Enums\CreditNoteKind;
use App\Finance\Http\Requests\IssueCreditNoteRequest;
use App\Finance\Http\Resources\CreditNoteResource;
use App\Finance\Models\Invoice;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

/**
 * Issue a credit note (or write-off) against an invoice. Validate → (route-)authorize →
 * delegate → respond; the transaction and the ceiling guard live in the Action, and the
 * DB facade is never touched here (arch rule).
 */
class CreditNoteController extends Controller
{
    public function store(IssueCreditNoteRequest $request, Invoice $invoice, IssueCreditNote $action): JsonResponse
    {
        $amount = Money::fromKobo(
            $request->integer('amount_minor'),
            (string) $request->input('currency', Money::DEFAULT_CURRENCY),
        );

        $kind = $request->filled('kind')
            ? CreditNoteKind::from((string) $request->input('kind'))
            : CreditNoteKind::CreditNote;

        try {
            $creditNote = $action->handle(
                $invoice,
                $amount,
                $kind,
                $request->input('note') !== null ? (string) $request->input('note') : null,
                $request->user(),
            );
        } catch (BusinessRuleException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(new CreditNoteResource($creditNote), 201);
    }
}
